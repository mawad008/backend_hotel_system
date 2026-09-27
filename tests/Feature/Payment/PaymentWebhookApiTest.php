<?php

namespace Tests\Feature\Payment;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Payment\Gateway\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Gateway\DummyPaymentGateway;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentTransaction;
use App\Domain\Payment\Models\PaymentWebhookEvent;
use App\Domain\Payment\Services\PaymentWebhookService;
use App\Domain\Reservation\Models\Reservation;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Unit\Payment\Workflow\Fakes\RecordingPaymentGateway;

/**
 * Phase 5E — POST /api/v1/payments/webhooks/{provider}.
 *
 * The webhook boundary: raw-body signature verification, provider parsing,
 * event persistence + deduplication + matching, and application of the
 * result through the Phase 5C reusable capability.
 */
class PaymentWebhookApiTest extends TestCase
{
    private const SECRET = 'phase-5e-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['payment.providers.dummy.webhook_secret' => self::SECRET]);
        $this->app->forgetInstance(PaymentGatewayInterface::class);
    }

    // ── helpers ────────────────────────────────────────────────────

    private function sign(string $body, string $secret = self::SECRET): string
    {
        return hash_hmac('sha256', $body, $secret);
    }

    private function postWebhook(string $body, ?string $signature = null, string $provider = 'dummy'): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        if ($signature !== null) {
            $server['HTTP_X_SIGNATURE'] = $signature;
        }

        return $this->call('POST', "/api/v1/payments/webhooks/{$provider}", [], [], [], $server, $body);
    }

    /**
     * A reservation with a PENDING deposit hold already recorded (Payment
     * HOLD_REQUESTED, transaction pending with a known provider reference)
     * — the state a real "API pending → webhook" flow leaves behind.
     *
     * @return array{Reservation, Payment, PaymentTransaction}
     */
    private function pendingHold(?Hotel $hotel = null, string $reservationStatus = Reservation::STATUS_PENDING): array
    {
        $hotel ??= Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $reservation = Reservation::factory()->create([
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'status' => $reservationStatus,
        ]);
        $payment = Payment::factory()->create([
            'reservation_id' => $reservation->id,
            'hotel_id' => $hotel->id,
            'status' => Payment::STATUS_HOLD_REQUESTED,
            'amount' => '120.00',
            'currency' => 'EUR',
        ]);
        $transaction = PaymentTransaction::factory()->create([
            'payment_id' => $payment->id,
            'type' => PaymentTransaction::TYPE_HOLD,
            'status' => PaymentTransaction::STATUS_PENDING,
            'provider' => 'dummy',
            'provider_reference' => 'dummy_hold_ref_'.$reservation->id,
        ]);

        return [$reservation, $payment, $transaction];
    }

    private function body(string $providerReference, string $status = 'succeeded', ?string $eventId = 'evt-1'): string
    {
        $payload = [
            'type' => "hold.{$status}",
            'provider_reference' => $providerReference,
            'status' => $status,
        ];

        if ($eventId !== null) {
            $payload['event_id'] = $eventId;
        }

        return json_encode($payload);
    }

    // ══ A. Signature ═══════════════════════════════════════════════

    public function test_valid_signature_over_the_exact_raw_body_is_accepted(): void
    {
        [, , $tx] = $this->pendingHold();
        // Body with irregular whitespace — the signature is over these exact bytes.
        $raw = '  '.$this->body($tx->provider_reference).'  ';

        $this->postWebhook($raw, $this->sign($raw))
            ->assertStatus(200)
            ->assertJsonPath('data.processing_status', PaymentWebhookEvent::PROCESSING_PROCESSED);
    }

    public function test_invalid_signature_is_rejected_with_400_and_nothing_persisted(): void
    {
        [, , $tx] = $this->pendingHold();
        $body = $this->body($tx->provider_reference);

        $this->postWebhook($body, 'deadbeef')
            ->assertStatus(400)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('payment_webhook_events', 0);
        $this->assertSame(Payment::STATUS_HOLD_REQUESTED, $tx->payment->fresh()->status);
    }

    public function test_missing_signature_header_is_rejected_with_400(): void
    {
        [, , $tx] = $this->pendingHold();

        $this->postWebhook($this->body($tx->provider_reference), null)
            ->assertStatus(400);

        $this->assertDatabaseCount('payment_webhook_events', 0);
    }

    public function test_missing_secret_fails_closed_with_400(): void
    {
        config(['payment.providers.dummy.webhook_secret' => '']);
        $this->app->forgetInstance(PaymentGatewayInterface::class);

        [, , $tx] = $this->pendingHold();
        $body = $this->body($tx->provider_reference);

        // Even a signature computed against an empty secret is rejected.
        $this->postWebhook($body, $this->sign($body, ''))->assertStatus(400);
    }

    public function test_signature_over_a_reformatted_body_is_rejected(): void
    {
        [, , $tx] = $this->pendingHold();
        $sent = $this->body($tx->provider_reference);
        $reformatted = json_encode(json_decode($sent), JSON_PRETTY_PRINT);

        $this->postWebhook($sent, $this->sign($reformatted))->assertStatus(400);
    }

    // ══ B. Parsing ════════════════════════════════════════════════

    public function test_malformed_json_is_a_422(): void
    {
        $raw = 'this-is-not-json';

        $this->postWebhook($raw, $this->sign($raw))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('payment_webhook_events', 0);
    }

    public function test_json_array_shape_is_a_422(): void
    {
        $raw = '[1,2,3]';
        $this->postWebhook($raw, $this->sign($raw))->assertStatus(422);
    }

    public function test_payload_missing_type_is_a_422(): void
    {
        $raw = json_encode(['provider_reference' => 'x', 'status' => 'succeeded']);
        $this->postWebhook($raw, $this->sign($raw))->assertStatus(422);
    }

    public function test_payload_with_a_sensitive_field_is_rejected_422(): void
    {
        $raw = json_encode(['type' => 'hold.succeeded', 'card_number' => '4111111111111111', 'cvv' => '123']);

        $this->postWebhook($raw, $this->sign($raw))->assertStatus(422);
        $this->assertDatabaseCount('payment_webhook_events', 0);
    }

    // ══ C. Deduplication ══════════════════════════════════════════

    public function test_same_provider_and_event_id_is_deduplicated(): void
    {
        [$reservation, , $tx] = $this->pendingHold();
        $body = $this->body($tx->provider_reference, 'succeeded', 'evt-dup');

        $this->postWebhook($body, $this->sign($body))
            ->assertStatus(200)
            ->assertJsonPath('data.processing_status', PaymentWebhookEvent::PROCESSING_PROCESSED);

        $this->postWebhook($body, $this->sign($body))
            ->assertStatus(200)
            ->assertJsonPath('data.processing_status', PaymentWebhookEvent::PROCESSING_DUPLICATE_IGNORED);

        $this->assertSame(1, PaymentWebhookEvent::where('processing_status', PaymentWebhookEvent::PROCESSING_PROCESSED)->count());
        $this->assertSame(Reservation::STATUS_DEPOSIT_HELD, $reservation->fresh()->status);
    }

    public function test_payload_hash_fallback_dedup_when_no_event_id(): void
    {
        [, , $tx] = $this->pendingHold();
        $body = $this->body($tx->provider_reference, 'succeeded', null);

        $this->postWebhook($body, $this->sign($body))
            ->assertStatus(200)
            ->assertJsonPath('data.processing_status', PaymentWebhookEvent::PROCESSING_PROCESSED);

        $this->postWebhook($body, $this->sign($body))
            ->assertStatus(200)
            ->assertJsonPath('data.processing_status', PaymentWebhookEvent::PROCESSING_DUPLICATE_IGNORED);

        $this->assertSame(1, PaymentTransaction::where('status', PaymentTransaction::STATUS_SUCCEEDED)->count());
    }

    public function test_duplicate_after_success_does_not_reapply(): void
    {
        [$reservation, $payment, $tx] = $this->pendingHold();
        $body = $this->body($tx->provider_reference, 'succeeded', 'evt-1');
        $this->postWebhook($body, $this->sign($body))->assertStatus(200);

        // A distinct event id, same effect — the transaction is already terminal.
        $body2 = $this->body($tx->provider_reference, 'succeeded', 'evt-2');
        $this->postWebhook($body2, $this->sign($body2))
            ->assertStatus(200)
            ->assertJsonPath('data.processing_status', PaymentWebhookEvent::PROCESSING_DUPLICATE_IGNORED);

        $this->assertSame(Payment::STATUS_HOLD_ACTIVE, $payment->fresh()->status);
        $this->assertSame(1, AuditLog::where('action', 'payment.hold_succeeded')->count());
    }

    public function test_duplicate_after_failure_does_not_reapply(): void
    {
        [$reservation, $payment, $tx] = $this->pendingHold();
        $body = $this->body($tx->provider_reference, 'failed', 'evt-1');
        $this->postWebhook($body, $this->sign($body))->assertStatus(200);

        $body2 = $this->body($tx->provider_reference, 'failed', 'evt-2');
        $this->postWebhook($body2, $this->sign($body2))
            ->assertStatus(200)
            ->assertJsonPath('data.processing_status', PaymentWebhookEvent::PROCESSING_DUPLICATE_IGNORED);

        $this->assertSame(Payment::STATUS_HOLD_FAILED, $payment->fresh()->status);
        $this->assertSame(Reservation::STATUS_CANCELLED, $reservation->fresh()->status);
    }

    // ══ D. Matching ═══════════════════════════════════════════════

    public function test_unmatched_event_is_persisted_as_unmatched_with_http_200(): void
    {
        $body = $this->body('dummy_hold_ref_UNKNOWN', 'succeeded', 'evt-x');

        $this->postWebhook($body, $this->sign($body))
            ->assertStatus(200)
            ->assertJsonPath('data.processing_status', PaymentWebhookEvent::PROCESSING_UNMATCHED);

        $event = PaymentWebhookEvent::sole();
        $this->assertSame(PaymentWebhookEvent::PROCESSING_UNMATCHED, $event->processing_status);
        $this->assertNull($event->payment_id);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('reservations', 0);
    }

    // ══ E–I. Provider outcomes applied from a webhook ════════════

    public function test_success_webhook_applies_the_full_success_path(): void
    {
        [$reservation, $payment, $tx] = $this->pendingHold();
        $body = $this->body($tx->provider_reference, 'succeeded');

        $this->postWebhook($body, $this->sign($body))->assertStatus(200);

        $this->assertSame(Payment::STATUS_HOLD_ACTIVE, $payment->fresh()->status);
        $this->assertSame(PaymentTransaction::STATUS_SUCCEEDED, $tx->fresh()->status);
        $this->assertSame(Reservation::STATUS_DEPOSIT_HELD, $reservation->fresh()->status);
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function terminalOutcomes(): array
    {
        return [
            'failed' => ['failed', PaymentTransaction::STATUS_FAILED, Payment::STATUS_HOLD_FAILED, Reservation::STATUS_CANCELLED],
            'cancelled' => ['cancelled', PaymentTransaction::STATUS_CANCELLED, Payment::STATUS_CANCELLED, Reservation::STATUS_CANCELLED],
            'expired' => ['expired', PaymentTransaction::STATUS_EXPIRED, Payment::STATUS_EXPIRED, Reservation::STATUS_CANCELLED],
        ];
    }

    #[DataProvider('terminalOutcomes')]
    public function test_terminal_webhook_outcomes_apply(string $status, string $txStatus, string $payStatus, string $resStatus): void
    {
        [$reservation, $payment, $tx] = $this->pendingHold();
        $body = $this->body($tx->provider_reference, $status);

        $this->postWebhook($body, $this->sign($body))->assertStatus(200);

        $this->assertSame($txStatus, $tx->fresh()->status);
        $this->assertSame($payStatus, $payment->fresh()->status);
        $this->assertSame($resStatus, $reservation->fresh()->status);
    }

    public function test_pending_webhook_holds_every_state(): void
    {
        [$reservation, $payment, $tx] = $this->pendingHold();
        $body = $this->body($tx->provider_reference, 'pending');

        $this->postWebhook($body, $this->sign($body))
            ->assertStatus(200)
            ->assertJsonPath('data.processing_status', PaymentWebhookEvent::PROCESSING_PROCESSED);

        $this->assertSame(Payment::STATUS_HOLD_REQUESTED, $payment->fresh()->status);
        $this->assertSame(PaymentTransaction::STATUS_PENDING, $tx->fresh()->status);
        $this->assertSame(Reservation::STATUS_PENDING, $reservation->fresh()->status);
    }

    // ══ J. Late success ══════════════════════════════════════════

    public function test_success_webhook_after_reservation_cancelled_is_a_reconciliation_case(): void
    {
        [$reservation, $payment, $tx] = $this->pendingHold(reservationStatus: Reservation::STATUS_CANCELLED);
        $body = $this->body($tx->provider_reference, 'succeeded');

        $this->postWebhook($body, $this->sign($body))->assertStatus(200);

        $this->assertSame(Reservation::STATUS_CANCELLED, $reservation->fresh()->status);
        $this->assertSame(Payment::STATUS_HOLD_ACTIVE, $payment->fresh()->status);
        $this->assertSame(PaymentTransaction::STATUS_SUCCEEDED, $tx->fresh()->status);

        $late = AuditLog::where('action', 'payment.hold_succeeded_after_reservation_closed')->sole();
        $this->assertTrue($late->after['requires_reconciliation']);
        $this->assertNull(AuditLog::where('action', 'like', '%refund%')->first());
        $this->assertArrayNotHasKey('needs_refund', $payment->fresh()->getAttributes());
        $this->assertNull(
            AuditLog::where('action', 'reservation.status_changed')->where('auditable_id', $reservation->id)->first(),
        );
    }

    // ══ K. Already terminal (idempotent) ════════════════════════

    /**
     * @return array<string, array{string, string}>
     */
    public static function alreadyTerminal(): array
    {
        return [
            'succeeded' => [PaymentTransaction::STATUS_SUCCEEDED, 'succeeded'],
            'failed' => [PaymentTransaction::STATUS_FAILED, 'failed'],
            'cancelled' => [PaymentTransaction::STATUS_CANCELLED, 'cancelled'],
            'expired' => [PaymentTransaction::STATUS_EXPIRED, 'expired'],
        ];
    }

    #[DataProvider('alreadyTerminal')]
    public function test_webhook_for_an_already_terminal_transaction_is_idempotent(string $existingTxStatus, string $webhookStatus): void
    {
        [$reservation, $payment, $tx] = $this->pendingHold();
        $tx->update(['status' => $existingTxStatus]);
        $originalPaymentStatus = $payment->status;

        $body = $this->body($tx->provider_reference, $webhookStatus, 'evt-late');

        $this->postWebhook($body, $this->sign($body))
            ->assertStatus(200)
            ->assertJsonPath('data.processing_status', PaymentWebhookEvent::PROCESSING_DUPLICATE_IGNORED);

        // No transition re-applied, no invalid-transition exception.
        $this->assertSame($existingTxStatus, $tx->fresh()->status);
        $this->assertSame($originalPaymentStatus, $payment->fresh()->status);
    }

    // ══ L. Security ═════════════════════════════════════════════

    /**
     * @return array<string, array{string}>
     */
    public static function bodiesToScan(): array
    {
        return [
            'processed' => ['succeeded'],
            'unmatched' => ['succeeded-unmatched'],
            'malformed' => ['malformed'],
        ];
    }

    #[DataProvider('bodiesToScan')]
    public function test_no_webhook_response_leaks_internals(string $case): void
    {
        [, , $tx] = $this->pendingHold();

        $raw = match ($case) {
            'succeeded' => $this->body($tx->provider_reference, 'succeeded'),
            'succeeded-unmatched' => $this->body('dummy_hold_ref_NONE', 'succeeded'),
            'malformed' => 'not-json',
        };

        $content = $this->postWebhook($raw, $this->sign($raw))->getContent();

        foreach ([self::SECRET, 'webhook_secret', 'SQLSTATE', 'stack trace', '#0 /', 'card_number', 'cvv', ' pin', 'DummyPaymentGateway', '.php:', $raw] as $needle) {
            if ($needle === '') {
                continue;
            }
            $this->assertStringNotContainsString($needle, $content, "webhook response leaked '{$needle}'");
        }
    }

    public function test_no_raw_body_is_persisted_on_the_event(): void
    {
        [, , $tx] = $this->pendingHold();
        $raw = $this->body($tx->provider_reference, 'succeeded');
        $this->postWebhook($raw, $this->sign($raw))->assertStatus(200);

        $event = PaymentWebhookEvent::sole();
        $encoded = json_encode($event->getAttributes());
        $this->assertStringNotContainsString($raw, $encoded);
        // Only the allow-listed normalized fields are stored.
        $this->assertEqualsCanonicalizing(
            ['type', 'provider_reference', 'status', 'event_id'],
            array_keys($event->normalized_payload),
        );
    }

    // ══ M. Controller architecture ═════════════════════════════

    public function test_webhook_controller_is_thin(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(PaymentWebhookController::class))->getFileName()
        );

        foreach ([
            'PaymentStateMachine', 'ReservationStateMachine', 'DB::', 'DummyPaymentGateway',
            'PaymentTransaction::', 'Payment::', 'Reservation::', '->save(', 'transitionTo(',
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $source, "controller must not reference {$needle}");
        }
    }

    // ══ N. Provider boundary ═══════════════════════════════════

    public function test_webhook_processing_never_calls_the_provider(): void
    {
        // Structural proof: the service has no gateway dependency at all,
        // and its source never names a provider-call method.
        $class = new \ReflectionClass(PaymentWebhookService::class);

        foreach ($class->getConstructor()->getParameters() as $parameter) {
            $this->assertStringNotContainsString(
                'Gateway\Contracts\PaymentGatewayInterface',
                (string) $parameter->getType(),
            );
        }

        $source = file_get_contents($class->getFileName());
        $this->assertStringNotContainsString('initiateHold', $source);
        $this->assertStringNotContainsString('->capture(', $source);
        $this->assertStringNotContainsString('->verify(', $source);
        $this->assertStringNotContainsString('Http::', $source);

        // Behavioural: a spy gateway bound in the container is never touched
        // during webhook processing.
        $spy = new RecordingPaymentGateway;
        $this->app->instance(PaymentGatewayInterface::class, $spy);

        [, , $tx] = $this->pendingHold();
        $body = $this->body($tx->provider_reference, 'succeeded');
        $normalized = (new DummyPaymentGateway(self::SECRET))->parseWebhook($body);

        app(PaymentWebhookService::class)->process('dummy', $normalized);

        $this->assertSame(0, $spy->initiateHoldCalls);
        $this->assertSame(PaymentTransaction::STATUS_SUCCEEDED, $tx->fresh()->status);
    }
}

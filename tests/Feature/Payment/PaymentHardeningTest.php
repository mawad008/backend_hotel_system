<?php

namespace Tests\Feature\Payment;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Payment\Gateway\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentTransaction;
use App\Domain\Payment\Models\PaymentWebhookEvent;
use App\Domain\Payment\StateMachine\PaymentStateMachine;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\StateMachine\ReservationStateMachine;
use Tests\TestCase;

/**
 * Phase 5F — integration hardening: rate limiting, replay safety,
 * state-machine cross-checks, and static architectural guardrails over the
 * whole Phase 5 payment surface.
 */
class PaymentHardeningTest extends TestCase
{
    private const SECRET = 'phase-5f-hardening-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['payment.providers.dummy.webhook_secret' => self::SECRET]);
        $this->app->forgetInstance(PaymentGatewayInterface::class);
    }

    private function paymentSourceFiles(): array
    {
        $dir = base_path('app/Domain/Payment');
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }
        $files[] = base_path('app/Http/Controllers/Api/V1/PaymentController.php');
        $files[] = base_path('app/Http/Controllers/Api/V1/PaymentWebhookController.php');
        sort($files);

        return $files;
    }

    private function codeWithoutComments(string $file): string
    {
        $out = '';
        foreach (token_get_all(file_get_contents($file)) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }

        return $out;
    }

    // ══ 5F.5 — Rate limiting ═══════════════════════════════════════

    public function test_payment_hold_endpoint_is_rate_limited(): void
    {
        config(['payment.rate_limits.hold.per_minute' => 2]);
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/reservations/999999/payment/hold', ['amount' => '10.00'])->assertStatus(404);
        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/reservations/999999/payment/hold', ['amount' => '10.00'])->assertStatus(404);
        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/reservations/999999/payment/hold', ['amount' => '10.00'])->assertStatus(429);
    }

    public function test_webhook_endpoint_is_rate_limited(): void
    {
        config(['payment.rate_limits.webhook.per_minute' => 2]);

        $post = fn () => $this->call('POST', '/api/v1/payments/webhooks/dummy', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SIGNATURE' => 'x'], '{}');

        $post()->assertStatus(400);
        $post()->assertStatus(400);
        $post()->assertStatus(429);
    }

    public function test_webhook_limit_default_is_high_enough_for_provider_retries(): void
    {
        // Documented posture: webhook ceiling >> hold ceiling.
        $this->assertGreaterThan(
            (int) config('payment.rate_limits.hold.per_minute'),
            (int) config('payment.rate_limits.webhook.per_minute'),
        );
    }

    // ══ 5F.6 — Webhook replay safety ══════════════════════════════

    public function test_a_captured_valid_webhook_cannot_be_replayed_for_new_effects(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $reservation = Reservation::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'status' => Reservation::STATUS_PENDING]);
        $payment = Payment::factory()->create(['reservation_id' => $reservation->id, 'hotel_id' => $hotel->id, 'status' => Payment::STATUS_HOLD_REQUESTED]);
        $tx = PaymentTransaction::factory()->create(['payment_id' => $payment->id, 'status' => PaymentTransaction::STATUS_PENDING, 'provider' => 'dummy', 'provider_reference' => 'dummy_hold_ref_replay']);

        $body = json_encode(['event_id' => 'evt-replay', 'type' => 'hold.succeeded', 'provider_reference' => 'dummy_hold_ref_replay', 'status' => 'succeeded']);
        $sig = hash_hmac('sha256', $body, self::SECRET);
        $send = fn () => $this->call('POST', '/api/v1/payments/webhooks/dummy', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SIGNATURE' => $sig], $body);

        $send()->assertStatus(200);
        // Replay the identical signed bytes 5 more times.
        for ($i = 0; $i < 5; $i++) {
            $send()->assertStatus(200)->assertJsonPath('data.processing_status', PaymentWebhookEvent::PROCESSING_DUPLICATE_IGNORED);
        }

        $this->assertSame(1, AuditLog::where('action', 'payment.hold_succeeded')->count());
        $this->assertSame(Payment::STATUS_HOLD_ACTIVE, $payment->fresh()->status);
        $this->assertSame(1, PaymentWebhookEvent::where('processing_status', PaymentWebhookEvent::PROCESSING_PROCESSED)->count());
    }

    // ══ 5F.3 — State machine cross-check ══════════════════════════

    public function test_every_payment_transition_the_workflow_uses_exists_in_the_state_machine(): void
    {
        $used = [
            [Payment::STATUS_NOT_STARTED, Payment::STATUS_HOLD_REQUESTED],
            [Payment::STATUS_HOLD_REQUESTED, Payment::STATUS_HOLD_ACTIVE],
            [Payment::STATUS_HOLD_REQUESTED, Payment::STATUS_HOLD_FAILED],
            [Payment::STATUS_HOLD_REQUESTED, Payment::STATUS_CANCELLED],
            [Payment::STATUS_HOLD_REQUESTED, Payment::STATUS_EXPIRED],
        ];

        foreach ($used as [$from, $to]) {
            $this->assertTrue(PaymentStateMachine::canTransition($from, $to), "{$from} -> {$to} must be an approved Payment transition");
        }
    }

    public function test_every_payment_driven_reservation_transition_exists_in_the_state_machine(): void
    {
        $used = [
            [Reservation::STATUS_PENDING, Reservation::STATUS_DEPOSIT_HELD],
            [Reservation::STATUS_PENDING, Reservation::STATUS_CANCELLED],
        ];

        foreach ($used as [$from, $to]) {
            $this->assertTrue(ReservationStateMachine::canTransition($from, $to), "{$from} -> {$to} must be an approved Reservation transition");
        }
    }

    // ══ 5F.16 — Static / architectural checks ════════════════════

    public function test_dummy_gateway_is_never_referenced_outside_the_binding_and_the_gateway_package(): void
    {
        foreach ($this->paymentSourceFiles() as $file) {
            if (str_contains($file, DIRECTORY_SEPARATOR.'Gateway'.DIRECTORY_SEPARATOR)) {
                continue; // the gateway package itself
            }

            $this->assertStringNotContainsString(
                'DummyPaymentGateway',
                $this->codeWithoutComments($file),
                basename($file).' must depend on PaymentGatewayInterface, not the concrete DummyPaymentGateway',
            );
        }
    }

    public function test_no_payment_code_assigns_a_workflow_status_directly(): void
    {
        foreach ($this->paymentSourceFiles() as $file) {
            $code = $this->codeWithoutComments($file);
            // ->status = ... assignment (not ->status === comparison, not 'status' => array key)
            $this->assertDoesNotMatchRegularExpression('/->status\s*=\s*[^=]/', $code, basename($file).' assigns ->status directly');
            $this->assertStringNotContainsString('->save()', $code, basename($file).' calls ->save() directly');
        }
    }

    public function test_no_gateway_call_is_wrapped_in_a_db_transaction(): void
    {
        foreach ($this->paymentSourceFiles() as $file) {
            $code = $this->codeWithoutComments($file);
            if (! str_contains($code, 'DB::transaction')) {
                continue;
            }
            // Crude but effective: a gateway operation must never textually
            // appear inside the same file's transaction closures alongside
            // initiateHold/capture/settle/verify.
            $this->assertStringNotContainsString('$this->gateway->initiateHold', $this->insideTransactionBlocks($code));
        }
    }

    public function test_webhook_service_never_persists_a_raw_body(): void
    {
        $code = $this->codeWithoutComments(base_path('app/Domain/Payment/Services/PaymentWebhookService.php'));

        $this->assertStringNotContainsString('getContent()', $code);
        $this->assertStringNotContainsString('rawBody', $code);
        $this->assertStringNotContainsString("'raw_body'", $code);
        // It only ever writes the allow-listed normalized structure.
        $this->assertStringContainsString('$event->normalizedPayload', $code);
    }

    public function test_no_secret_literal_is_committed_in_payment_source(): void
    {
        foreach ($this->paymentSourceFiles() as $file) {
            $code = file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('/(secret|password|api[_-]?key)\s*=\s*[\'"][A-Za-z0-9]{12,}/i', $code);
        }
    }

    public function test_no_debug_statements_in_payment_source(): void
    {
        foreach ($this->paymentSourceFiles() as $file) {
            $code = $this->codeWithoutComments($file);
            foreach (['\bdd\(', '\bdump\(', '\bvar_dump\(', '\bray\(', '\berror_log\(', '\bprint_r\('] as $needle) {
                $this->assertDoesNotMatchRegularExpression("/{$needle}/", $code, basename($file)." contains a debug call ({$needle})");
            }
        }
    }

    /**
     * Return only the text that sits within DB::transaction(function ... { ... })
     * closures — a rough brace-matched slice, good enough for a guardrail.
     */
    private function insideTransactionBlocks(string $code): string
    {
        $out = '';
        $offset = 0;
        while (($pos = strpos($code, 'DB::transaction', $offset)) !== false) {
            $brace = strpos($code, '{', $pos);
            if ($brace === false) {
                break;
            }
            $depth = 0;
            for ($i = $brace; $i < strlen($code); $i++) {
                if ($code[$i] === '{') {
                    $depth++;
                }
                if ($code[$i] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $out .= substr($code, $brace, $i - $brace + 1);
                        $offset = $i + 1;

                        continue 2;
                    }
                }
            }
            break;
        }

        return $out;
    }
}

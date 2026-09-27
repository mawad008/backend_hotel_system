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
use App\Domain\Reservation\Models\Reservation;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 5F — end-to-end integration across the whole Phase 5 payment stack:
 * API (5D) -> Workflow (5C) -> Gateway (5B) -> Webhook (5E).
 */
class PaymentFlowIntegrationTest extends TestCase
{
    private const SECRET = 'phase-5f-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['payment.providers.dummy.webhook_secret' => self::SECRET]);
        $this->app->forgetInstance(PaymentGatewayInterface::class);
    }

    private function owner(): User
    {
        return User::factory()->groupOwner()->create();
    }

    private function reservation(): Reservation
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);

        return Reservation::factory()->create([
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'status' => Reservation::STATUS_PENDING,
        ]);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function apiHold(User $actor, Reservation $reservation, array $headers = [], string $amount = '120.00'): TestResponse
    {
        return $this->actingAs($actor, 'sanctum')
            ->withHeaders($headers)
            ->postJson("/api/v1/reservations/{$reservation->id}/payment/hold", ['amount' => $amount, 'currency' => 'EUR']);
    }

    private function webhook(string $status, string $providerReference, string $eventId = 'evt-1'): TestResponse
    {
        $body = json_encode([
            'event_id' => $eventId,
            'type' => "hold.{$status}",
            'provider_reference' => $providerReference,
            'status' => $status,
        ]);

        return $this->call(
            'POST',
            '/api/v1/payments/webhooks/dummy',
            [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, self::SECRET)],
            $body,
        );
    }

    private function providerReference(): string
    {
        return PaymentTransaction::sole()->provider_reference;
    }

    // ── FLOW A — API success ──────────────────────────────────────

    public function test_flow_a_api_success(): void
    {
        $reservation = $this->reservation();

        $this->apiHold($this->owner(), $reservation)->assertStatus(201);

        $this->assertSame(Payment::STATUS_HOLD_ACTIVE, Payment::sole()->status);
        $this->assertSame(PaymentTransaction::STATUS_SUCCEEDED, PaymentTransaction::sole()->status);
        $this->assertSame(Reservation::STATUS_DEPOSIT_HELD, $reservation->fresh()->status);
    }

    // ── FLOW B — API pending, then SUCCESS webhook ────────────────

    public function test_flow_b_api_pending_then_webhook_success(): void
    {
        $reservation = $this->reservation();

        $this->apiHold($this->owner(), $reservation, ['X-Payment-Simulate' => 'pending'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', Payment::STATUS_HOLD_REQUESTED);

        $this->assertSame(Reservation::STATUS_PENDING, $reservation->fresh()->status);

        $this->webhook('succeeded', $this->providerReference())->assertStatus(200);

        $this->assertSame(Payment::STATUS_HOLD_ACTIVE, Payment::sole()->status);
        $this->assertSame(PaymentTransaction::STATUS_SUCCEEDED, PaymentTransaction::sole()->status);
        $this->assertSame(Reservation::STATUS_DEPOSIT_HELD, $reservation->fresh()->status);
    }

    // ── FLOW C — API pending, then FAILURE webhook ────────────────

    public function test_flow_c_api_pending_then_webhook_failure(): void
    {
        $reservation = $this->reservation();

        $this->apiHold($this->owner(), $reservation, ['X-Payment-Simulate' => 'pending'])->assertStatus(200);

        $this->webhook('failed', $this->providerReference())->assertStatus(200);

        $this->assertSame(Payment::STATUS_HOLD_FAILED, Payment::sole()->status);
        $this->assertSame(Reservation::STATUS_CANCELLED, $reservation->fresh()->status);
    }

    // ── FLOW D — duplicate webhook ───────────────────────────────

    public function test_flow_d_duplicate_webhook_has_no_second_effect(): void
    {
        $reservation = $this->reservation();
        $this->apiHold($this->owner(), $reservation, ['X-Payment-Simulate' => 'pending'])->assertStatus(200);
        $ref = $this->providerReference();

        $this->webhook('succeeded', $ref, 'evt-1')->assertStatus(200)
            ->assertJsonPath('data.processing_status', PaymentWebhookEvent::PROCESSING_PROCESSED);
        $this->webhook('succeeded', $ref, 'evt-1')->assertStatus(200)
            ->assertJsonPath('data.processing_status', PaymentWebhookEvent::PROCESSING_DUPLICATE_IGNORED);

        $this->assertSame(1, AuditLog::where('action', 'payment.hold_succeeded')->count());
        $this->assertSame(1, PaymentWebhookEvent::where('processing_status', PaymentWebhookEvent::PROCESSING_PROCESSED)->count());
        $this->assertSame(Reservation::STATUS_DEPOSIT_HELD, $reservation->fresh()->status);
    }

    // ── FLOW E — late success ────────────────────────────────────

    public function test_flow_e_late_success_after_cancellation(): void
    {
        $reservation = $this->reservation();
        $owner = $this->owner();
        $this->apiHold($owner, $reservation, ['X-Payment-Simulate' => 'pending'])->assertStatus(200);
        $ref = $this->providerReference();

        // Staff cancels the reservation while the hold is still pending.
        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/reservations/{$reservation->id}/transition", ['target_status' => Reservation::STATUS_CANCELLED])
            ->assertOk();

        $this->webhook('succeeded', $ref)->assertStatus(200);

        $this->assertSame(Reservation::STATUS_CANCELLED, $reservation->fresh()->status);
        $this->assertSame(Payment::STATUS_HOLD_ACTIVE, Payment::sole()->status);
        $this->assertSame(PaymentTransaction::STATUS_SUCCEEDED, PaymentTransaction::sole()->status);

        $late = AuditLog::where('action', 'payment.hold_succeeded_after_reservation_closed')->sole();
        $this->assertTrue($late->after['requires_reconciliation']);
        $this->assertNull(AuditLog::where('action', 'like', '%refund%')->first());
        $this->assertArrayNotHasKey('needs_refund', Payment::sole()->getAttributes());
    }

    // ── FLOW F — idempotent API retry ────────────────────────────

    public function test_flow_f_idempotent_api_retry(): void
    {
        $reservation = $this->reservation();
        $owner = $this->owner();
        $headers = ['Idempotency-Key' => 'flow-f-key'];

        $first = $this->apiHold($owner, $reservation, $headers)->assertStatus(201);
        $second = $this->apiHold($owner, $reservation->fresh(), $headers)->assertStatus(201);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_transactions', 1);
    }

    // ── FLOW G — webhook after API success ───────────────────────

    public function test_flow_g_webhook_after_api_success_is_a_noop_200(): void
    {
        $reservation = $this->reservation();
        $this->apiHold($this->owner(), $reservation)->assertStatus(201);
        $ref = $this->providerReference();

        $this->webhook('succeeded', $ref, 'evt-after-api')
            ->assertStatus(200)
            ->assertJsonPath('data.processing_status', PaymentWebhookEvent::PROCESSING_DUPLICATE_IGNORED);

        $this->assertSame(1, AuditLog::where('action', 'payment.hold_succeeded')->count());
        $this->assertSame(Payment::STATUS_HOLD_ACTIVE, Payment::sole()->status);
        $this->assertSame(Reservation::STATUS_DEPOSIT_HELD, $reservation->fresh()->status);
    }
}

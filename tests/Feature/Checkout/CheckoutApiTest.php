<?php

namespace Tests\Feature\Checkout;

use App\Domain\Checkout\Models\Checkout;
use App\Domain\Checkout\Models\Invoice;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentTransaction;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\StayServices\Models\FolioCharge;
use App\Domain\StayServices\Models\HotelService;
use App\Domain\StayServices\Models\ServiceOrder;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CheckoutApiTest extends TestCase
{
    private function owner(): User
    {
        return User::factory()->groupOwner()->create();
    }

    private function reservation(?Hotel $hotel = null, string $status = Reservation::STATUS_IN_STAY): Reservation
    {
        $hotel ??= Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $reservation = Reservation::factory()->create([
            // price_snapshot 0.00 keeps these lifecycle tests isolated from
            // the accommodation charge (exercised in CheckoutAccountingTest).
            'hotel_id' => $hotel->id, 'room_type_id' => $roomType->id, 'status' => $status,
            'price_snapshot' => '0.00',
        ]);
        Payment::factory()->holdActive()->create([
            'reservation_id' => $reservation->id, 'hotel_id' => $hotel->id, 'amount' => '0.00', 'currency' => 'USD',
        ]);

        return $reservation;
    }

    private function charge(Reservation $reservation, string $unit, int $qty = 1): void
    {
        $service = HotelService::factory()->create([
            'hotel_id' => $reservation->hotel_id, 'price' => $unit, 'currency' => 'USD',
        ]);
        $order = ServiceOrder::factory()->confirmed()->create([
            'reservation_id' => $reservation->id, 'hotel_id' => $reservation->hotel_id,
            'service_id' => $service->id, 'quantity' => $qty,
            'unit_price_snapshot' => $unit, 'currency_snapshot' => 'USD',
            'total_amount' => bcmul($unit, (string) $qty, 2),
        ]);
        FolioCharge::factory()->create([
            'reservation_id' => $reservation->id, 'hotel_id' => $reservation->hotel_id,
            'source_type' => FolioCharge::SOURCE_SERVICE_ORDER, 'source_id' => $order->id,
            'description' => 'Item', 'quantity' => $qty, 'unit_amount' => $unit,
            'total_amount' => bcmul($unit, (string) $qty, 2), 'currency' => 'USD',
            'status' => FolioCharge::STATUS_POSTED,
        ]);
    }

    private function checkout(User $actor, Reservation $reservation, array $headers = []): TestResponse
    {
        return $this->actingAs($actor, 'sanctum')->withHeaders($headers)
            ->postJson("/api/v1/reservations/{$reservation->id}/checkout");
    }

    // ── Auth ────────────────────────────────────────────────────────

    public function test_unauthenticated_is_401(): void
    {
        $this->postJson("/api/v1/reservations/{$this->reservation()->id}/checkout")->assertStatus(401);
    }

    public function test_guest_role_gets_a_scope_404(): void
    {
        $this->checkout(User::factory()->guest()->create(), $this->reservation())->assertStatus(404);
    }

    public function test_manager_in_an_unassigned_hotel_gets_a_404(): void
    {
        $assigned = Hotel::factory()->create();
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($assigned);

        $this->checkout($manager, $this->reservation(Hotel::factory()->create()))->assertStatus(404);
    }

    // ── Happy paths ─────────────────────────────────────────────────

    public function test_checkout_with_no_outstanding_completes(): void
    {
        $reservation = $this->reservation();

        $this->checkout($this->owner(), $reservation)
            ->assertOk()
            ->assertJsonPath('data.checkout.status', Checkout::STATUS_COMPLETED)
            ->assertJsonPath('data.reservation.status', Reservation::STATUS_INVOICED)
            ->assertJsonPath('data.invoice.status', Invoice::STATUS_ISSUED)
            ->assertJsonPath('data.totals.outstanding_total', '0.00');

        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_checkout_with_successful_final_settlement(): void
    {
        $reservation = $this->reservation();
        $this->charge($reservation, '55.00');

        $this->checkout($this->owner(), $reservation, ['X-Payment-Simulate' => 'success'])
            ->assertOk()
            ->assertJsonPath('data.checkout.status', Checkout::STATUS_COMPLETED)
            ->assertJsonPath('data.payment.status', Payment::STATUS_SETTLED)
            ->assertJsonPath('data.totals.charges_total', '55.00')
            ->assertJsonPath('data.invoice.status', Invoice::STATUS_ISSUED);
    }

    // ── Failure matrix ─────────────────────────────────────────────

    public function test_failed_settlement_is_a_422_and_leaves_the_reservation_non_final(): void
    {
        $reservation = $this->reservation();
        $this->charge($reservation, '55.00');

        $this->checkout($this->owner(), $reservation, ['X-Payment-Simulate' => 'failure'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.checkout_status', Checkout::STATUS_SETTLEMENT_FAILED);

        $this->assertSame(Reservation::STATUS_CHECKOUT_IN_PROGRESS, $reservation->fresh()->status);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_pending_settlement_is_a_422(): void
    {
        $reservation = $this->reservation();
        $this->charge($reservation, '55.00');

        $this->checkout($this->owner(), $reservation, ['X-Payment-Simulate' => 'pending'])
            ->assertStatus(422)
            ->assertJsonPath('errors.checkout_status', Checkout::STATUS_AWAITING_SETTLEMENT);

        $this->assertSame(Reservation::STATUS_CHECKOUT_IN_PROGRESS, $reservation->fresh()->status);
    }

    public function test_retry_after_a_failed_settlement_succeeds(): void
    {
        $reservation = $this->reservation();
        $this->charge($reservation, '20.00');

        $this->checkout($this->owner(), $reservation, ['X-Payment-Simulate' => 'failure'])->assertStatus(422);
        $this->checkout($this->owner(), $reservation->fresh(), ['X-Payment-Simulate' => 'success'])->assertOk();

        $this->assertSame(Reservation::STATUS_INVOICED, $reservation->fresh()->status);
        $this->assertSame(1, Invoice::count());
    }

    // ── Idempotency ────────────────────────────────────────────────

    public function test_repeated_checkout_returns_the_same_result_without_re_settling(): void
    {
        $reservation = $this->reservation();
        $this->charge($reservation, '33.00');
        $owner = $this->owner();

        $first = $this->checkout($owner, $reservation, ['X-Payment-Simulate' => 'success'])->assertOk();
        $second = $this->checkout($owner, $reservation->fresh(), ['X-Payment-Simulate' => 'success'])->assertOk();

        $this->assertSame($first->json('data.invoice.invoice_number'), $second->json('data.invoice.invoice_number'));
        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, PaymentTransaction::where('status', PaymentTransaction::STATUS_SUCCEEDED)->count());
    }

    public function test_malformed_idempotency_key_is_a_422(): void
    {
        $this->checkout($this->owner(), $this->reservation(), ['Idempotency-Key' => 'has spaces'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['idempotency_key']);
    }

    // ── State ──────────────────────────────────────────────────────

    public function test_checkout_from_a_wrong_state_is_a_422(): void
    {
        $this->checkout($this->owner(), $this->reservation(hotel: null, status: Reservation::STATUS_CHECKED_IN))
            ->assertStatus(422)->assertJsonPath('success', false);
    }

    public function test_nonexistent_reservation_is_a_404(): void
    {
        $this->actingAs($this->owner(), 'sanctum')->postJson('/api/v1/reservations/999999/checkout')->assertStatus(404);
    }

    // ── Security ───────────────────────────────────────────────────

    public function test_client_amount_and_status_in_the_body_are_ignored(): void
    {
        $reservation = $this->reservation();
        $this->charge($reservation, '55.00');

        $this->actingAs($this->owner(), 'sanctum')->withHeaders(['X-Payment-Simulate' => 'success'])
            ->postJson("/api/v1/reservations/{$reservation->id}/checkout", [
                'amount' => '1.00', 'outstanding_total' => '0.00', 'paid' => true,
                'hotel_id' => Hotel::factory()->create()->id, 'payment_status' => 'settled',
            ])
            ->assertOk()
            ->assertJsonPath('data.totals.charges_total', '55.00')
            ->assertJsonPath('data.totals.outstanding_total', '0.00')
            ->assertJsonPath('data.reservation.hotel_id', $reservation->hotel_id);

        // The client's amount was ignored: the settlement transaction records
        // the SERVER-computed outstanding (55.00), and payments.amount (the
        // held deposit) is never overwritten.
        $this->assertSame('55.00', PaymentTransaction::where('type', 'settlement')->sole()->amount);
        $this->assertSame('0.00', $reservation->payment->fresh()->amount);
    }

    public function test_response_never_leaks_provider_or_internal_detail(): void
    {
        $reservation = $this->reservation();
        $this->charge($reservation, '55.00');
        $body = $this->checkout($this->owner(), $reservation, ['X-Payment-Simulate' => 'success'])->getContent();

        foreach (['provider_reference', 'idempotency_key', 'dummy_settlement', 'SQLSTATE', '.php:', 'DummyPaymentGateway'] as $needle) {
            $this->assertStringNotContainsString($needle, $body, "leaked '{$needle}'");
        }
    }

    public function test_rate_limit_applies(): void
    {
        $owner = $this->owner();

        for ($i = 0; $i < 12; $i++) {
            $this->checkout($owner, $this->reservation())->assertOk();
        }

        $this->checkout($owner, $this->reservation())->assertStatus(429);
    }
}

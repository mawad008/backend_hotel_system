<?php

namespace Tests\Feature\Guest;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Inventory\Models\Room;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Loyalty\Models\LoyaltyRule;
use App\Domain\Payment\Gateway\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Gateway\Data\GatewayHoldRequest;
use App\Domain\Payment\Gateway\Data\GatewayOperationRequest;
use App\Domain\Payment\Gateway\Data\GatewayResult;
use App\Domain\Payment\Gateway\Data\NormalizedWebhook;
use App\Domain\Payment\Gateway\GatewayOperation;
use App\Domain\Payment\Gateway\GatewayResultStatus;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentTransaction;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use Tests\TestCase;

/**
 * Approved launch business rules (2026-09-26): currency snapshot,
 * cancellation policy + refund, 0% deposit, loyalty program exposure.
 */
class GuestLaunchRulesTest extends TestCase
{
    private function actingGuest(): Guest
    {
        $guest = Guest::factory()->create();
        $this->withToken($guest->createToken('guest-api')->plainTextToken);

        return $guest;
    }

    private function roomType(bool $refundable = true, array $hotel = []): RoomType
    {
        $h = Hotel::factory()->create($hotel + ['check_in_time' => '15:00:00', 'timezone' => 'UTC', 'deposit_percentage' => 20]);
        $rt = RoomType::factory()->create(['hotel_id' => $h->id, 'base_price' => 100, 'capacity' => 3, 'refundable' => $refundable]);
        Room::factory()->count(2)->create(['hotel_id' => $h->id, 'room_type_id' => $rt->id]);

        return $rt;
    }

    private function book(RoomType $rt, int $inDays = 10, int $nights = 2): Reservation
    {
        $id = $this->postJson('/api/v1/guest/reservations', [
            'room_type_id' => $rt->id,
            'check_in' => now()->addDays($inDays)->toDateString(),
            'check_out' => now()->addDays($inDays + $nights)->toDateString(),
            'adults' => 2,
            'children' => 0,
        ])->assertCreated()->json('data.id');

        return Reservation::findOrFail($id);
    }

    public function test_reservation_and_payment_snapshot_the_currency_and_keep_it(): void
    {
        config(['payment.currency' => 'SAR']);
        $this->actingGuest();
        $reservation = $this->book($this->roomType());
        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/payment/hold")->assertCreated();

        config(['payment.currency' => 'AED']);

        $this->assertSame('SAR', $reservation->fresh()->currency);
        $this->assertSame('SAR', Payment::where('reservation_id', $reservation->id)->value('currency'));
        $this->assertSame('SAR', PaymentTransaction::query()->latest('id')->value('currency'));
        $this->getJson("/api/v1/guest/reservations/{$reservation->id}")->assertJsonPath('data.currency', 'SAR');
    }

    public function test_a_refundable_booking_snapshots_a_24h_window_capped_at_check_in(): void
    {
        $this->actingGuest();
        $far = $this->book($this->roomType(), inDays: 10);
        $this->assertEqualsWithDelta(now()->addHours(24)->timestamp, $far->free_cancellation_until->timestamp, 5);

        // Arriving tomorrow at 15:00 — earlier than now+24h only if it's past 15:00 today;
        // check-in today at 15:00 caps it for sure when booked for today.
        $soon = $this->book($this->roomType(), inDays: 0, nights: 1);
        $this->assertTrue($soon->free_cancellation_until->lessThanOrEqualTo(now()->startOfDay()->setTime(15, 0)));

        $nonRefundable = $this->book($this->roomType(refundable: false));
        $this->assertFalse($nonRefundable->is_refundable);
        $this->assertNull($nonRefundable->free_cancellation_until);
    }

    public function test_free_cancellation_releases_the_deposit_hold(): void
    {
        $this->actingGuest();
        $reservation = $this->book($this->roomType());
        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/payment/hold")->assertCreated();

        $this->getJson("/api/v1/guest/reservations/{$reservation->id}")
            ->assertJsonPath('data.cancellation.allowed', true)
            ->assertJsonPath('data.cancellation.refund', 'full');

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/cancel", ['reason' => 'Plans changed'])
            ->assertOk()
            ->assertJsonPath('data.status', Reservation::STATUS_CANCELLED);

        $this->assertSame(Payment::STATUS_CANCELLED, Payment::where('reservation_id', $reservation->id)->value('status'));
        $this->assertDatabaseHas('payment_transactions', ['type' => PaymentTransaction::TYPE_CANCEL_HOLD, 'status' => PaymentTransaction::STATUS_SUCCEEDED]);
        $this->assertNotNull($reservation->fresh()->cancelled_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'reservation.cancelled']);
    }

    public function test_cancellation_is_refused_after_the_window_and_for_non_refundable_rates(): void
    {
        $this->actingGuest();
        $late = $this->book($this->roomType());
        $late->forceFill(['free_cancellation_until' => now()->subMinute()])->saveQuietly();
        $this->postJson("/api/v1/guest/reservations/{$late->id}/cancel")
            ->assertStatus(422)->assertJsonPath('errors.reason', 'free_cancellation_window_closed');

        $fixed = $this->book($this->roomType(refundable: false));
        $this->getJson("/api/v1/guest/reservations/{$fixed->id}")->assertJsonPath('data.cancellation.allowed', false);
        $this->postJson("/api/v1/guest/reservations/{$fixed->id}/cancel")
            ->assertStatus(422)->assertJsonPath('errors.reason', 'non_refundable_rate');

        $this->assertSame(Reservation::STATUS_PENDING, $late->fresh()->status);
        $this->assertSame(Reservation::STATUS_PENDING, $fixed->fresh()->status);
    }

    public function test_the_policy_snapshot_survives_a_later_room_type_change(): void
    {
        $this->actingGuest();
        $rt = $this->roomType();
        $reservation = $this->book($rt);
        $rt->update(['refundable' => false]);

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/cancel")->assertOk();
    }

    public function test_a_failed_release_leaves_the_reservation_active(): void
    {
        $this->actingGuest();
        $reservation = $this->book($this->roomType());
        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/payment/hold")->assertCreated();

        $real = app(PaymentGatewayInterface::class);
        $this->app->instance(PaymentGatewayInterface::class, new class($real) implements PaymentGatewayInterface
        {
            public function __construct(private readonly PaymentGatewayInterface $inner) {}

            public function initiateHold(GatewayHoldRequest $request): GatewayResult
            {
                return $this->inner->initiateHold($request);
            }

            public function cancelHold(GatewayOperationRequest $request): GatewayResult
            {
                return new GatewayResult(GatewayOperation::CancelHold, GatewayResultStatus::Failed, 'ref', 'provider_unavailable', 'down');
            }

            public function capture(GatewayOperationRequest $request): GatewayResult
            {
                return $this->inner->capture($request);
            }

            public function settle(GatewayOperationRequest $request): GatewayResult
            {
                return $this->inner->settle($request);
            }

            public function verify(GatewayOperationRequest $request): GatewayResult
            {
                return $this->inner->verify($request);
            }

            public function parseWebhook(string $rawBody): NormalizedWebhook
            {
                return $this->inner->parseWebhook($rawBody);
            }

            public function verifySignature(string $rawBody, ?string $signature): bool
            {
                return $this->inner->verifySignature($rawBody, $signature);
            }
        });

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/cancel")
            ->assertStatus(422)->assertJsonPath('errors.reason', 'refund_failed');

        $this->assertSame(Reservation::STATUS_DEPOSIT_HELD, $reservation->fresh()->status);
        $this->assertSame(Payment::STATUS_HOLD_ACTIVE, Payment::where('reservation_id', $reservation->id)->value('status'));
    }

    public function test_a_zero_percent_deposit_confirms_without_a_hold(): void
    {
        $this->actingGuest();
        $reservation = $this->book($this->roomType(hotel: ['deposit_percentage' => 0]));

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/payment/hold")
            ->assertOk()
            ->assertJsonPath('meta.deposit_required', false);

        $this->assertSame(Reservation::STATUS_DEPOSIT_HELD, $reservation->fresh()->status);
        $this->assertDatabaseMissing('payments', ['reservation_id' => $reservation->id]);
    }

    public function test_loyalty_program_is_exposed_disabled_at_launch_and_live_when_configured(): void
    {
        $guest = $this->actingGuest();
        $rt = $this->roomType();
        $groupId = $rt->hotel->hotel_group_id;
        LoyaltyRule::query()->where('hotel_group_id', $groupId)->delete();
        LoyaltyRule::factory()->create(['hotel_group_id' => $groupId, 'is_active' => false, 'earn_points_per_currency' => 0, 'redeem_currency_per_point' => 0, 'max_redeem_points' => 0]);

        $this->getJson("/api/v1/guest/hotels/{$rt->hotel_id}/loyalty")
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.redeemable_points', 0);

        LoyaltyRule::query()->where('hotel_group_id', $groupId)->update(['is_active' => true, 'earn_points_per_currency' => 1, 'redeem_currency_per_point' => '0.05', 'max_redeem_points' => 300]);
        $this->getJson("/api/v1/guest/hotels/{$rt->hotel_id}/loyalty")
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.max_redeem_points', 300)
            ->assertJsonPath('data.points_balance', 0);
        $this->assertNotNull($guest->id);
    }

    public function test_the_reservation_shows_the_deposit_the_hold_will_take(): void
    {
        $this->actingGuest();
        // 2 nights × 123.45 = 246.90; 25% = 61.725 → 61.72 (halalas, truncated like the hold).
        $rt = $this->roomType(hotel: ['deposit_percentage' => 25]);
        $rt->update(['base_price' => '123.45']);
        $reservation = $this->book($rt);

        $this->getJson("/api/v1/guest/reservations/{$reservation->id}")
            ->assertJsonPath('data.hotel.deposit_percentage', '25.00')
            ->assertJsonPath('data.hotel.deposit_amount', '61.72');

        $this->postJson("/api/v1/guest/reservations/{$reservation->id}/payment/hold")->assertCreated();
        $this->assertSame('61.72', (string) \App\Domain\Payment\Models\Payment::where('reservation_id', $reservation->id)->value('amount'));
    }

    public function test_a_booking_snapshots_the_hotels_service_fee(): void
    {
        $this->actingGuest();
        // 2 nights × 100 = 200.00; fixed fee 45 per booking.
        $fixed = $this->book($this->roomType(hotel: ['service_fee_enabled' => true, 'service_fee_type' => 'fixed', 'service_fee_value' => '45.00']));
        $this->assertSame('45.00', (string) $fixed->service_fee_amount);
        $this->getJson("/api/v1/guest/reservations/{$fixed->id}")
            ->assertJsonPath('data.service_fee_amount', '45.00')
            ->assertJsonPath('data.total_amount', '245.00');

        // 7.5% of 200.00 = 15.00.
        $pct = $this->book($this->roomType(hotel: ['service_fee_enabled' => true, 'service_fee_type' => 'percentage', 'service_fee_value' => '7.50']));
        $this->assertSame('15.00', (string) $pct->service_fee_amount);

        // Switched off → no fee.
        $off = $this->book($this->roomType(hotel: ['service_fee_enabled' => false, 'service_fee_type' => 'fixed', 'service_fee_value' => '45.00']));
        $this->assertSame('0.00', (string) $off->service_fee_amount);

        // A later dashboard change never re-prices the existing booking.
        $fixed->hotel->update(['service_fee_value' => '99.00']);
        $this->assertSame('45.00', (string) $fixed->fresh()->service_fee_amount);
    }

    public function test_the_service_fee_is_billed_once_as_its_own_folio_line(): void
    {
        $this->actingGuest();
        $reservation = $this->book($this->roomType(hotel: ['service_fee_enabled' => true, 'service_fee_type' => 'fixed', 'service_fee_value' => '45.00']));
        $charges = app(\App\Domain\StayServices\Services\FolioChargeService::class);

        $charges->postAccommodationCharge($reservation, 'SAR', null);
        $first = $charges->postServiceFeeCharge($reservation, 'SAR', null);
        $again = $charges->postServiceFeeCharge($reservation->fresh(), 'SAR', null);

        $this->assertSame($first->id, $again->id);
        $this->assertSame('45.00', (string) $first->total_amount);
        $folio = app(\App\Domain\StayServices\Services\FolioService::class)->folioFor($reservation->fresh());
        $this->assertSame('245.00', $folio->chargesTotal);

        // No fee → no line.
        $noFee = $this->book($this->roomType());
        $this->assertNull($charges->postServiceFeeCharge($noFee, 'SAR', null));
    }
}


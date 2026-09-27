<?php

namespace Tests\Unit\Loyalty;

use App\Domain\Loyalty\Exceptions\LoyaltyNotAllowedException;
use App\Domain\Loyalty\Models\LoyaltyRule;
use App\Domain\Loyalty\Models\LoyaltyTransaction;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\Services\ReservationCancellationService;
use App\Domain\Reservation\Services\ReservationService;
use App\Domain\StayServices\Models\FolioCharge;

/**
 * AccrueLoyaltyOnStayCompletion — completing a stay accrues points through
 * the same idempotent earn rule the staff action uses.
 */
class LoyaltyLifecycleAccrualTest extends LoyaltyTestCase
{
    public function test_checking_out_accrues_points_once(): void
    {
        $reservation = $this->reservationForLoyalty(status: Reservation::STATUS_CHECKOUT_IN_PROGRESS, priceSnapshot: '250.00');

        app(ReservationService::class)->transitionTo($reservation, Reservation::STATUS_CHECKED_OUT);
        app(ReservationService::class)->transitionTo($reservation->fresh(), Reservation::STATUS_INVOICED);

        $earns = $this->earnsFor($reservation)->get();
        $this->assertCount(1, $earns);
        $this->assertSame(250, (int) $earns->first()->points);
        $this->assertSame(250, $this->loyalty()->accountFor($reservation->guest)->points_balance);

        // The staff "earn" action afterwards is an idempotent replay.
        $this->loyalty()->earnForReservation($reservation->fresh());
        $this->assertSame(1, $this->earnsFor($reservation)->count());
    }

    public function test_an_inactive_program_never_blocks_the_transition(): void
    {
        $reservation = $this->reservationForLoyalty(status: Reservation::STATUS_CHECKOUT_IN_PROGRESS, activeRule: false);

        $updated = app(ReservationService::class)->transitionTo($reservation, Reservation::STATUS_CHECKED_OUT);

        $this->assertSame(Reservation::STATUS_CHECKED_OUT, $updated->status);
        $this->assertSame(0, $this->earnsFor($reservation)->count());
    }

    public function test_non_completion_transitions_do_not_accrue(): void
    {
        $reservation = $this->reservationForLoyalty(status: Reservation::STATUS_CHECKED_IN);

        app(ReservationService::class)->transitionTo($reservation, Reservation::STATUS_IN_STAY);

        $this->assertSame(0, $this->earnsFor($reservation)->count());
    }

    private function earnsFor(Reservation $reservation)
    {
        return LoyaltyTransaction::query()
            ->where('source_type', LoyaltyTransaction::SOURCE_RESERVATION)
            ->where('source_id', $reservation->id)
            ->where('type', LoyaltyTransaction::TYPE_EARN);
    }

    public function test_a_cancelled_booking_never_accrues(): void
    {
        $reservation = $this->reservationForLoyalty(status: Reservation::STATUS_DEPOSIT_HELD);

        app(ReservationService::class)->transitionTo($reservation, Reservation::STATUS_CANCELLED);

        $this->assertSame(0, $this->earnsFor($reservation)->count());
    }

    public function test_redemption_respects_the_configured_maximum(): void
    {
        $reservation = $this->reservationForLoyalty(status: Reservation::STATUS_DEPOSIT_HELD);
        LoyaltyRule::query()->update(['max_redeem_points' => 50]);

        try {
            $this->loyalty()->redeemForReservation($reservation->fresh(), 60);
            $this->fail('Redeeming past the maximum must be refused.');
        } catch (LoyaltyNotAllowedException $e) {
            $this->assertSame('exceeds_maximum_redemption', $e->reason);
        }
    }

    public function test_redemption_posts_a_capped_folio_credit_and_cancellation_returns_the_points(): void
    {
        $reservation = $this->reservationForLoyalty(
            status: Reservation::STATUS_DEPOSIT_HELD, priceSnapshot: '100.00', redeemValue: '1.0000',
        );
        $completed = Reservation::factory()->create([
            'hotel_id' => $reservation->hotel_id,
            'room_type_id' => $reservation->room_type_id,
            'guest_id' => $reservation->guest_id,
            'status' => Reservation::STATUS_INVOICED,
            'price_snapshot' => '500.00',
        ]);
        $this->loyalty()->earnForReservation($completed);

        // 150 points x 1.00 = 150 > the 100.00 stay total: only the 100
        // points that cover the stay are consumed.
        $redeem = $this->loyalty()->redeemForReservation($reservation->fresh(), 150);
        $this->assertSame(-100, $redeem->points);

        $credit = FolioCharge::query()
            ->where('source_type', FolioCharge::SOURCE_LOYALTY_REDEMPTION)
            ->where('source_id', $reservation->id)
            ->sole();
        $this->assertSame('-100.00', (string) $credit->total_amount);
        $this->assertSame(FolioCharge::STATUS_POSTED, $credit->status);
        $this->assertSame(400, $this->loyalty()->accountFor($reservation->guest)->points_balance);

        // A replay of the same request is idempotent (trimmed the same way).
        $this->assertSame($redeem->id, $this->loyalty()->redeemForReservation($reservation->fresh(), 150)->id);

        app(ReservationCancellationService::class)->cancelByStaff($reservation->fresh(), \App\Domain\IdentityAccess\Models\User::factory()->create(), 'guest request');

        $this->assertSame(500, $this->loyalty()->accountFor($reservation->guest)->points_balance);
        $this->assertSame(FolioCharge::STATUS_CANCELLED, $credit->fresh()->status);
        $this->assertSame(1, LoyaltyTransaction::query()
            ->where('type', LoyaltyTransaction::TYPE_REVERSE)
            ->where('source_id', $reservation->id)
            ->count());

        // Idempotent.
        $this->loyalty()->reverseRedemptionForReservation($reservation->fresh());
        $this->assertSame(500, $this->loyalty()->accountFor($reservation->guest)->points_balance);
    }

    public function test_redemption_never_discounts_services(): void
    {
        $reservation = $this->reservationForLoyalty(
            status: Reservation::STATUS_IN_STAY, priceSnapshot: '100.00', redeemValue: '1.0000',
        );
        $completed = Reservation::factory()->create([
            'hotel_id' => $reservation->hotel_id,
            'room_type_id' => $reservation->room_type_id,
            'guest_id' => $reservation->guest_id,
            'status' => Reservation::STATUS_INVOICED,
            'price_snapshot' => '500.00',
        ]);
        $this->loyalty()->earnForReservation($completed);
        app(\App\Domain\StayServices\Services\FolioChargeService::class)->postAccommodationCharge($reservation->fresh(), 'SAR', null);
        FolioCharge::factory()->amount('60.00', 1)->create(['reservation_id' => $reservation->id]);

        // 500 points x 1.00 could cover stay + services; only the stay is discounted.
        $this->loyalty()->redeemForReservation($reservation->fresh(), 160);

        $credit = FolioCharge::query()
            ->where('source_type', FolioCharge::SOURCE_LOYALTY_REDEMPTION)
            ->where('source_id', $reservation->id)
            ->sole();
        $this->assertSame('-100.00', (string) $credit->total_amount);
        $this->assertSame(400, $this->loyalty()->accountFor($reservation->guest)->points_balance);

        $folio = app(\App\Domain\StayServices\Services\FolioService::class)->folioFor($reservation->fresh());
        // stay 100 + service 60 - loyalty 100: the service is still owed in full.
        $this->assertSame('60.00', $folio->chargesTotal);
    }
}


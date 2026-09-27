<?php

namespace Tests\Unit\Loyalty;

use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Loyalty\Exceptions\InvalidLoyaltyPointsException;
use App\Domain\Loyalty\Exceptions\LoyaltyNotAllowedException;
use App\Domain\Loyalty\Models\LoyaltyAccount;
use App\Domain\Loyalty\Models\LoyaltyRule;
use App\Domain\Loyalty\Models\LoyaltyTransaction;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use PHPUnit\Framework\Attributes\DataProvider;

class LoyaltyServiceTest extends LoyaltyTestCase
{
    // ── Account ─────────────────────────────────────────────────────

    public function test_account_is_created_once_per_guest(): void
    {
        $guest = Guest::factory()->create();

        $a = $this->loyalty()->accountFor($guest);
        $b = $this->loyalty()->accountFor($guest->fresh());

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, LoyaltyAccount::count());
        $this->assertSame(0, $a->points_balance);
    }

    // ── Earn ────────────────────────────────────────────────────────

    public function test_earn_computes_floor_of_rate_times_price_snapshot_and_moves_the_cached_balance(): void
    {
        $reservation = $this->reservationForLoyalty(priceSnapshot: '250.50', earnRate: '1.5000'); // 1.5 * 250.50 = 375.75 -> 375
        $actor = User::factory()->hotelManager()->create();

        $txn = $this->loyalty()->earnForReservation($reservation, $actor);

        $this->assertSame(LoyaltyTransaction::TYPE_EARN, $txn->type);
        $this->assertSame(375, $txn->points);
        $this->assertSame(LoyaltyTransaction::SOURCE_RESERVATION, $txn->source_type);
        $this->assertSame($reservation->id, (int) $txn->source_id);
        $this->assertSame('250.50', $txn->metadata['earn_base_amount']);
        $this->assertSame($actor->id, $txn->created_by_user_id);

        $account = $txn->account;
        $this->assertSame(375, $account->points_balance);
        // cache == authoritative ledger
        $this->assertSame($account->points_balance, (int) LoyaltyTransaction::where('loyalty_account_id', $account->id)->sum('points'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'loyalty.earned']);
    }

    public function test_earn_is_idempotent_and_never_double_accrues(): void
    {
        $reservation = $this->reservationForLoyalty();

        $first = $this->loyalty()->earnForReservation($reservation);
        $second = $this->loyalty()->earnForReservation($reservation->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, LoyaltyTransaction::count());
        $this->assertSame(200, $first->account->fresh()->points_balance);
    }

    public function test_earn_blocked_when_the_program_is_inactive(): void
    {
        $reservation = $this->reservationForLoyalty(activeRule: false);

        try {
            $this->loyalty()->earnForReservation($reservation);
            $this->fail('expected rejection');
        } catch (LoyaltyNotAllowedException $e) {
            $this->assertSame('loyalty_program_inactive', $e->reason);
        }
        $this->assertSame(0, LoyaltyTransaction::count());
    }

    public function test_earn_blocked_when_active_but_no_earn_rate_configured(): void
    {
        $group = HotelGroup::factory()->create();
        LoyaltyRule::factory()->create(['hotel_group_id' => $group->id, 'is_active' => true, 'earn_points_per_currency' => null]);
        $reservation = $this->reservationForLoyalty(activeRule: false, group: $group);

        try {
            $this->loyalty()->earnForReservation($reservation);
            $this->fail('expected rejection');
        } catch (LoyaltyNotAllowedException $e) {
            $this->assertSame('earn_rate_not_configured', $e->reason);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonCompletedStatuses(): array
    {
        return [
            'pending' => [Reservation::STATUS_PENDING],
            'verified' => [Reservation::STATUS_VERIFIED],
            'in_stay' => [Reservation::STATUS_IN_STAY],
            'cancelled' => [Reservation::STATUS_CANCELLED],
            'checkout_in_progress' => [Reservation::STATUS_CHECKOUT_IN_PROGRESS],
        ];
    }

    #[DataProvider('nonCompletedStatuses')]
    public function test_earn_requires_a_completed_booking(string $status): void
    {
        $reservation = $this->reservationForLoyalty(status: $status);

        $this->expectException(LoyaltyNotAllowedException::class);
        $this->loyalty()->earnForReservation($reservation);
    }

    public function test_earn_from_checked_out_is_allowed(): void
    {
        $reservation = $this->reservationForLoyalty(status: Reservation::STATUS_CHECKED_OUT);

        $txn = $this->loyalty()->earnForReservation($reservation);
        $this->assertSame(200, $txn->points);
    }

    public function test_earn_with_no_booking_value_is_rejected(): void
    {
        $reservation = $this->reservationForLoyalty(priceSnapshot: '0.00');

        $this->expectException(LoyaltyNotAllowedException::class);
        $this->loyalty()->earnForReservation($reservation);
    }

    public function test_earn_when_rate_rounds_to_zero_points_is_rejected(): void
    {
        // 0.0001 * 200 = 0.02 -> floor 0
        $reservation = $this->reservationForLoyalty(priceSnapshot: '200.00', earnRate: '0.0001');

        try {
            $this->loyalty()->earnForReservation($reservation);
            $this->fail('expected rejection');
        } catch (LoyaltyNotAllowedException $e) {
            $this->assertSame('no_eligible_booking_value', $e->reason);
        }
    }

    // ── Redeem ──────────────────────────────────────────────────────

    public function test_redeem_debits_points_and_records_a_notional_value(): void
    {
        $reservation = $this->reservationForLoyalty(status: Reservation::STATUS_VERIFIED, redeemValue: '0.0500');
        $account = $this->loyalty()->accountFor($reservation->guest);
        $this->loyalty()->recomputeBalance($account);
        // seed a balance via an adjust-style ledger row
        LoyaltyTransaction::factory()->earn(500)->create(['loyalty_account_id' => $account->id, 'source_id' => 9991]);
        $this->loyalty()->recomputeBalance($account->fresh());

        $txn = $this->loyalty()->redeemForReservation($reservation, 300);

        $this->assertSame(LoyaltyTransaction::TYPE_REDEEM, $txn->type);
        $this->assertSame(-300, $txn->points);
        $this->assertSame('15.00', $txn->metadata['notional_value']); // 300 * 0.05
        $this->assertSame(200, $account->fresh()->points_balance);
        $this->assertSame(200, (int) LoyaltyTransaction::where('loyalty_account_id', $account->id)->sum('points'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'loyalty.redeemed']);
    }

    public function test_redeem_rejects_an_insufficient_balance(): void
    {
        $reservation = $this->reservationForLoyalty(status: Reservation::STATUS_IN_STAY);

        try {
            $this->loyalty()->redeemForReservation($reservation, 50);
            $this->fail('expected rejection');
        } catch (LoyaltyNotAllowedException $e) {
            $this->assertSame('insufficient_points_balance', $e->reason);
        }
        $this->assertSame(0, LoyaltyTransaction::where('type', 'redeem')->count());
    }

    public function test_redeem_rejects_a_non_positive_points_value(): void
    {
        $reservation = $this->reservationForLoyalty(status: Reservation::STATUS_IN_STAY);

        $this->expectException(InvalidLoyaltyPointsException::class);
        $this->loyalty()->redeemForReservation($reservation, 0);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonRedeemableStatuses(): array
    {
        return [
            'checked_out' => [Reservation::STATUS_CHECKED_OUT],
            'invoiced' => [Reservation::STATUS_INVOICED],
            'cancelled' => [Reservation::STATUS_CANCELLED],
            'checkout_in_progress' => [Reservation::STATUS_CHECKOUT_IN_PROGRESS],
        ];
    }

    #[DataProvider('nonRedeemableStatuses')]
    public function test_redeem_requires_an_eligible_non_terminal_booking(string $status): void
    {
        $reservation = $this->reservationForLoyalty(status: $status);
        $account = $this->loyalty()->accountFor($reservation->guest);
        LoyaltyTransaction::factory()->earn(1000)->create(['loyalty_account_id' => $account->id, 'source_id' => 8881]);
        $this->loyalty()->recomputeBalance($account->fresh());

        $this->expectException(LoyaltyNotAllowedException::class);
        $this->loyalty()->redeemForReservation($reservation, 100);
    }

    public function test_redeem_same_points_twice_is_an_idempotent_replay(): void
    {
        $reservation = $this->reservationForLoyalty(status: Reservation::STATUS_VERIFIED);
        $account = $this->loyalty()->accountFor($reservation->guest);
        LoyaltyTransaction::factory()->earn(1000)->create(['loyalty_account_id' => $account->id, 'source_id' => 7771]);
        $this->loyalty()->recomputeBalance($account->fresh());

        $first = $this->loyalty()->redeemForReservation($reservation, 100);
        $second = $this->loyalty()->redeemForReservation($reservation->fresh(), 100);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(900, $account->fresh()->points_balance);
        $this->assertSame(1, LoyaltyTransaction::where('type', 'redeem')->count());
    }

    public function test_redeem_different_points_against_the_same_booking_is_a_conflict(): void
    {
        $reservation = $this->reservationForLoyalty(status: Reservation::STATUS_VERIFIED);
        $account = $this->loyalty()->accountFor($reservation->guest);
        LoyaltyTransaction::factory()->earn(1000)->create(['loyalty_account_id' => $account->id, 'source_id' => 6661]);
        $this->loyalty()->recomputeBalance($account->fresh());

        $this->loyalty()->redeemForReservation($reservation, 100);

        try {
            $this->loyalty()->redeemForReservation($reservation->fresh(), 200);
            $this->fail('expected rejection');
        } catch (LoyaltyNotAllowedException $e) {
            $this->assertSame('already_redeemed_against_this_booking', $e->reason);
        }
    }

    // ── Balance integrity ───────────────────────────────────────────

    public function test_every_mutation_writes_exactly_one_ledger_row_and_moves_the_balance_by_its_delta(): void
    {
        $group = HotelGroup::factory()->create();
        $earn = $this->reservationForLoyalty(status: Reservation::STATUS_INVOICED, priceSnapshot: '300.00', group: $group); // 300 pts
        $redeemRes = $this->reservationForLoyalty(status: Reservation::STATUS_IN_STAY, activeRule: false, group: $group);
        $redeemRes->update(['guest_id' => $earn->guest_id]);

        $this->loyalty()->earnForReservation($earn);
        $account = $this->loyalty()->accountFor($earn->guest);
        $this->assertSame(300, $account->points_balance);

        $this->loyalty()->redeemForReservation($redeemRes->fresh(), 120);
        $account = $account->fresh();

        $this->assertSame(180, $account->points_balance);
        $this->assertSame(2, LoyaltyTransaction::where('loyalty_account_id', $account->id)->count());
        $this->assertSame(180, (int) LoyaltyTransaction::where('loyalty_account_id', $account->id)->sum('points'));
    }

    public function test_recompute_balance_heals_a_drifted_cache(): void
    {
        $account = LoyaltyAccount::factory()->create(['points_balance' => 9999]);
        LoyaltyTransaction::factory()->earn(100)->create(['loyalty_account_id' => $account->id, 'source_id' => 1]);
        LoyaltyTransaction::factory()->redeem(30)->create(['loyalty_account_id' => $account->id, 'source_id' => 2]);

        $this->loyalty()->recomputeBalance($account);

        $this->assertSame(70, $account->fresh()->points_balance);
    }
}

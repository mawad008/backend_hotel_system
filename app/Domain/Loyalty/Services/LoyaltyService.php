<?php

namespace App\Domain\Loyalty\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Loyalty\Exceptions\InvalidLoyaltyPointsException;
use App\Domain\Loyalty\Exceptions\LoyaltyNotAllowedException;
use App\Domain\Loyalty\Models\LoyaltyAccount;
use App\Domain\Loyalty\Models\LoyaltyRule;
use App\Domain\Loyalty\Models\LoyaltyTransaction;
use App\Domain\Loyalty\Repositories\Contracts\LoyaltyAccountRepositoryInterface;
use App\Domain\Loyalty\Repositories\Contracts\LoyaltyRuleRepositoryInterface;
use App\Domain\Loyalty\Repositories\Contracts\LoyaltyTransactionRepositoryInterface;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\Repositories\Contracts\ReservationRepositoryInterface;
use App\Domain\StayServices\Services\FolioChargeService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 10 — the loyalty ledger workflow (Phase 0 §13, R43/R51/R59).
 *
 * ── Invariants ──
 * - The `loyalty_transactions` ledger is authoritative; `points_balance` is
 *   a cache that is ONLY changed alongside a ledger row, inside one
 *   DB transaction that locks the account (guardrail #8: "loyalty balance
 *   never mutated without a ledger row").
 * - `earn` accrues from a COMPLETED booking; `redeem` spends against an
 *   ELIGIBLE (non-terminal) booking. Never against services (R59): the
 *   redeem entry posts a negative `loyalty_redemption` folio line capped at
 *   the accommodation total, and a cancelled booking returns its points
 *   (a `reverse` ledger row) and voids that line.
 * - No expiration (R51), no tiers, no rate is ever invented (§13) — a rule
 *   that is inactive / unconfigured simply blocks the operation.
 *
 * All arithmetic is integer (points are counts) / bcmath (the rate × amount
 * step). Never float money math.
 */
class LoyaltyService
{
    /**
     * Reservation statuses that count as a completed/stayed booking —
     * matches the Phase 0 §14 definition used for review eligibility.
     *
     * @var list<string>
     */
    public const COMPLETED_RESERVATION_STATUSES = [
        Reservation::STATUS_CHECKED_OUT,
        Reservation::STATUS_INVOICED,
    ];

    /**
     * Reservation statuses a redemption may be applied against — an active,
     * not-yet-completed, not-cancelled booking. A technical boundary
     * (§13/R59 do not define "eligible booking" precisely) — see the report.
     *
     * @var list<string>
     */
    public const REDEEMABLE_RESERVATION_STATUSES = [
        Reservation::STATUS_PENDING,
        Reservation::STATUS_DEPOSIT_HELD,
        Reservation::STATUS_VERIFIED,
        Reservation::STATUS_CHECKED_IN,
        Reservation::STATUS_IN_STAY,
    ];

    public function __construct(
        private readonly LoyaltyAccountRepositoryInterface $accounts,
        private readonly LoyaltyTransactionRepositoryInterface $transactions,
        private readonly LoyaltyRuleRepositoryInterface $rules,
        private readonly AuditLogger $auditLogger,
        private readonly FolioChargeService $folioCharges,
        private readonly ReservationRepositoryInterface $reservations,
    ) {}

    // ═══════════════════════════════════════════════════════════════════
    //  Read
    // ═══════════════════════════════════════════════════════════════════

    /**
     * The guest's loyalty account, created on first access (idempotent via
     * the `guest_id` UNIQUE).
     */
    public function accountFor(Guest $guest): LoyaltyAccount
    {
        $account = $this->accounts->findByGuest($guest->id);

        if ($account !== null) {
            return $account;
        }

        try {
            return $this->accounts->create(['guest_id' => $guest->id, 'points_balance' => 0]);
        } catch (UniqueConstraintViolationException) {
            return $this->accounts->findByGuest($guest->id)
                ?? throw new LoyaltyNotAllowedException('account_resolution_failed');
        }
    }

    /**
     * @return LengthAwarePaginator<LoyaltyTransaction>
     */
    public function ledgerFor(Guest $guest, int $perPage = 20): LengthAwarePaginator
    {
        return $this->transactions->paginateForAccount($this->accountFor($guest)->id, $perPage);
    }

    /**
     * Re-derive the cached balance from the authoritative ledger.
     */
    public function recomputeBalance(LoyaltyAccount $account): LoyaltyAccount
    {
        return $this->accounts->update($account, [
            'points_balance' => $this->transactions->sumPointsForAccount($account->id),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Earn
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Accrue points for a completed reservation. Idempotent — a second call
     * returns the existing `earn` entry, never a duplicate accrual.
     *
     * @throws LoyaltyNotAllowedException if the program is inactive, the
     *                                    earn rate is unset, the reservation
     *                                    is not completed, or there is no
     *                                    eligible booking value.
     */
    public function earnForReservation(Reservation $reservation, ?User $actor = null): LoyaltyTransaction
    {
        $rule = $this->ruleForReservation($reservation);

        if ($rule === null || ! $rule->is_active) {
            throw LoyaltyNotAllowedException::programInactive();
        }

        if ($rule->earn_points_per_currency === null) {
            throw LoyaltyNotAllowedException::earnRateNotConfigured();
        }

        if (! in_array($reservation->status, self::COMPLETED_RESERVATION_STATUSES, true)) {
            throw LoyaltyNotAllowedException::reservationNotCompleted($reservation->status);
        }

        $base = $reservation->price_snapshot;

        if ($base === null || bccomp((string) $base, '0', 2) <= 0) {
            throw LoyaltyNotAllowedException::nothingToEarn();
        }

        $points = $this->pointsFromAmount((string) $rule->earn_points_per_currency, (string) $base);

        if ($points <= 0) {
            throw LoyaltyNotAllowedException::nothingToEarn();
        }

        return DB::transaction(function () use ($reservation, $points, $base, $actor) {
            $account = $this->lockedAccount($reservation->guest_id);

            $existing = $this->transactions->findByAccountTypeAndSource(
                $account->id, LoyaltyTransaction::TYPE_EARN, LoyaltyTransaction::SOURCE_RESERVATION, $reservation->id,
            );

            if ($existing !== null) {
                return $existing;
            }

            $transaction = $this->appendLedgerEntry($account, [
                'type' => LoyaltyTransaction::TYPE_EARN,
                'points' => $points,
                'source_type' => LoyaltyTransaction::SOURCE_RESERVATION,
                'source_id' => $reservation->id,
                'description' => 'Points earned for a completed stay',
                'created_by_user_id' => $actor?->id,
                'metadata' => ['earn_base_amount' => (string) $base],
            ]);

            $this->auditLogger->record(
                $actor, 'loyalty.earned', $transaction,
                after: $this->auditSnapshot($transaction, $account->fresh()),
                hotelId: $reservation->hotel_id,
            );

            return $transaction;
        });
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Redeem
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Redeem points against an eligible (non-terminal) reservation. Records
     * the ledger movement and the notional redeemed value; the monetary
     * application of that value is deferred (§13 "no folio/service
     * redemption").
     *
     * @throws InvalidLoyaltyPointsException if $points is not a positive integer
     * @throws LoyaltyNotAllowedException on any business precondition failure
     */
    public function redeemForReservation(Reservation $reservation, int $points, ?User $actor = null): LoyaltyTransaction
    {
        if ($points < 1) {
            throw new InvalidLoyaltyPointsException;
        }

        $rule = $this->ruleForReservation($reservation);

        if ($rule === null || ! $rule->is_active) {
            throw LoyaltyNotAllowedException::programInactive();
        }

        if ($rule->redeem_currency_per_point === null) {
            throw LoyaltyNotAllowedException::redeemRateNotConfigured();
        }

        if (! in_array($reservation->status, self::REDEEMABLE_RESERVATION_STATUSES, true)) {
            throw LoyaltyNotAllowedException::reservationNotRedeemable($reservation->status);
        }

        // Approved: redemption never exceeds the configured per-booking
        // maximum (0 at launch = nothing redeemable).
        $maximum = (int) ($rule->max_redeem_points ?? 0);

        if ($points > $maximum) {
            throw LoyaltyNotAllowedException::exceedsMaximum($maximum);
        }

        // Approved: points never discount more than the stay itself. Only the
        // points needed to cover the stay total are consumed — a larger
        // request is trimmed (same arithmetic as the app's `pointsFor`), so
        // the guest never loses points the discount could not use.
        $points = min($points, $this->pointsCoveringStay($reservation, (string) $rule->redeem_currency_per_point));

        if ($points < 1) {
            throw LoyaltyNotAllowedException::exceedsMaximum(0);
        }

        $notionalValue = bcmul((string) $points, (string) $rule->redeem_currency_per_point, 2);

        return DB::transaction(function () use ($reservation, $points, $notionalValue, $actor) {
            // Lock order: reservation, then account (matches cancellation).
            $reservation = $this->reservations->findForUpdate($reservation->id) ?? $reservation;

            if (! in_array($reservation->status, self::REDEEMABLE_RESERVATION_STATUSES, true)) {
                throw LoyaltyNotAllowedException::reservationNotRedeemable($reservation->status);
            }

            $account = $this->lockedAccount($reservation->guest_id);

            $existing = $this->transactions->findByAccountTypeAndSource(
                $account->id, LoyaltyTransaction::TYPE_REDEEM, LoyaltyTransaction::SOURCE_RESERVATION, $reservation->id,
            );

            if ($existing !== null) {
                // Same points -> idempotent replay of a double-submit.
                // Different points -> a conflicting second redemption.
                if (abs($existing->points) === $points) {
                    return $existing;
                }

                throw LoyaltyNotAllowedException::alreadyRedeemed();
            }

            if ($account->points_balance < $points) {
                throw LoyaltyNotAllowedException::insufficientBalance();
            }

            $transaction = $this->appendLedgerEntry($account, [
                'type' => LoyaltyTransaction::TYPE_REDEEM,
                'points' => -$points,
                'source_type' => LoyaltyTransaction::SOURCE_RESERVATION,
                'source_id' => $reservation->id,
                'description' => 'Points redeemed against a booking',
                'created_by_user_id' => $actor?->id,
                'metadata' => ['notional_value' => $notionalValue],
            ]);

            $this->folioCharges->postLoyaltyCredit(
                $reservation, $points, $notionalValue, $reservation->currency, $actor,
            );

            $this->auditLogger->record(
                $actor, 'loyalty.redeemed', $transaction,
                after: $this->auditSnapshot($transaction, $account->fresh()),
                hotelId: $reservation->hotel_id,
            );

            return $transaction;
        });
    }

    /** The fewest points whose value reaches the stay total (ceil). */
    private function pointsCoveringStay(Reservation $reservation, string $valuePerPoint): int
    {
        $stayTotal = bcadd((string) ($reservation->price_snapshot ?? '0'), '0', 2);

        if (bccomp($valuePerPoint, '0', 4) <= 0 || bccomp($stayTotal, '0', 2) <= 0) {
            return 0;
        }

        $points = (int) bcdiv($stayTotal, $valuePerPoint, 0);

        return bccomp(bcmul((string) $points, $valuePerPoint, 4), $stayTotal, 4) < 0 ? $points + 1 : $points;
    }

    /**
     * Return the points a booking redeemed (it was cancelled): one `reverse`
     * ledger row per booking + the folio credit voided. Idempotent; a
     * booking that redeemed nothing is a no-op. Runs in the caller's
     * transaction when there is one.
     */
    public function reverseRedemptionForReservation(Reservation $reservation, ?User $actor = null): ?LoyaltyTransaction
    {
        return DB::transaction(function () use ($reservation, $actor) {
            $account = $this->accounts->findByGuestForUpdate($reservation->guest_id);

            if ($account === null) {
                return null;
            }

            $redeemed = $this->transactions->findByAccountTypeAndSource(
                $account->id, LoyaltyTransaction::TYPE_REDEEM, LoyaltyTransaction::SOURCE_RESERVATION, $reservation->id,
            );

            if ($redeemed === null) {
                return null;
            }

            $existing = $this->transactions->findByAccountTypeAndSource(
                $account->id, LoyaltyTransaction::TYPE_REVERSE, LoyaltyTransaction::SOURCE_RESERVATION, $reservation->id,
            );

            if ($existing !== null) {
                return $existing;
            }

            $transaction = $this->appendLedgerEntry($account, [
                'type' => LoyaltyTransaction::TYPE_REVERSE,
                'points' => abs($redeemed->points),
                'source_type' => LoyaltyTransaction::SOURCE_RESERVATION,
                'source_id' => $reservation->id,
                'description' => 'Redeemed points returned — booking cancelled',
                'created_by_user_id' => $actor?->id,
                'metadata' => ['reverses_transaction_id' => $redeemed->id],
            ]);

            $this->folioCharges->voidLoyaltyCredit($reservation, $actor);

            $this->auditLogger->record(
                $actor, 'loyalty.redemption_reversed', $transaction,
                after: $this->auditSnapshot($transaction, $account->fresh()),
                hotelId: $reservation->hotel_id,
            );

            return $transaction;
        });
    }

    /**
     * The live program of a hotel's group, as the guest app's booking
     * summary shows it — everything the UI needs, nothing hardcoded in the
     * app. A missing / inactive rule is `enabled: false` with zero values.
     *
     * @return array{enabled: bool, earn_points_per_currency: string, redeem_currency_per_point: string, max_redeem_points: int}
     */
    public function programForHotelGroup(?int $hotelGroupId): array
    {
        $rule = $hotelGroupId === null ? null : $this->rules->findByHotelGroup($hotelGroupId);
        $enabled = $rule !== null && $rule->is_active;

        return [
            'enabled' => $enabled,
            'earn_points_per_currency' => $enabled ? (string) ($rule->earn_points_per_currency ?? '0') : '0',
            'redeem_currency_per_point' => $enabled ? (string) ($rule->redeem_currency_per_point ?? '0') : '0',
            'max_redeem_points' => $enabled ? (int) ($rule->max_redeem_points ?? 0) : 0,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Internals
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Get-or-create the account, then re-read it under a row lock. Call only
     * inside a DB::transaction().
     */
    private function lockedAccount(int $guestId): LoyaltyAccount
    {
        $locked = $this->accounts->findByGuestForUpdate($guestId);

        if ($locked !== null) {
            return $locked;
        }

        try {
            $this->accounts->create(['guest_id' => $guestId, 'points_balance' => 0]);
        } catch (UniqueConstraintViolationException) {
            // created concurrently — fall through to the re-read
        }

        return $this->accounts->findByGuestForUpdate($guestId)
            ?? throw new LoyaltyNotAllowedException('account_resolution_failed');
    }

    /**
     * Append one ledger row and move the cached balance by its delta, in the
     * caller's already-open transaction (account already locked).
     *
     * @param  array<string, mixed>  $data
     */
    private function appendLedgerEntry(LoyaltyAccount $account, array $data): LoyaltyTransaction
    {
        try {
            $transaction = $this->transactions->create(['loyalty_account_id' => $account->id] + $data);
        } catch (UniqueConstraintViolationException) {
            // A concurrent identical earn/redeem won the race — the
            // (account, type, source) UNIQUE is authoritative.
            return $this->transactions->findByAccountTypeAndSource(
                $account->id, $data['type'], $data['source_type'], (int) $data['source_id'],
            ) ?? throw new LoyaltyNotAllowedException('ledger_write_race');
        }

        $this->accounts->update($account, [
            'points_balance' => $account->points_balance + $data['points'],
        ]);

        return $transaction;
    }

    private function ruleForReservation(Reservation $reservation): ?LoyaltyRule
    {
        $groupId = $reservation->hotel()->value('hotel_group_id');

        return $groupId === null ? null : $this->rules->findByHotelGroup((int) $groupId);
    }

    /**
     * points = floor(rate x amount). bcmath for the multiplication, integer
     * for the result (points are whole counts). Never rounds up.
     */
    private function pointsFromAmount(string $rate, string $amount): int
    {
        $product = bcmul($rate, $amount, 6);

        return (int) explode('.', $product, 2)[0];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(LoyaltyTransaction $transaction, LoyaltyAccount $account): array
    {
        return array_filter([
            'loyalty_transaction_type' => $transaction->type,
            'points' => $transaction->points,
            'source_type' => $transaction->source_type,
            'source_id' => $transaction->source_id,
            'balance_after' => $account->points_balance,
        ], fn ($value) => $value !== null);
    }
}

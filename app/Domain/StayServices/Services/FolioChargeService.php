<?php

namespace App\Domain\StayServices\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\StayServices\Models\FolioCharge;
use App\Domain\StayServices\Repositories\Contracts\FolioChargeRepositoryInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Phase 9 (review fix) — posts folio charges that do not originate from a
 * service order. Currently the reservation accommodation charge.
 *
 * Follows the Phase 8 folio-charge architecture (`ServiceOrderService`
 * posts service-order charges the same way): idempotent via the
 * `folio_charges (source_type, source_id)` UNIQUE plus a pre-check,
 * audited, decimal-safe.
 *
 * Every method MUST be called from within the caller's DB transaction (the
 * reservation row is already locked). It opens no transaction of its own
 * and calls no external provider.
 */
class FolioChargeService
{
    public function __construct(
        private readonly FolioChargeRepositoryInterface $charges,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Post (once) the reservation accommodation charge.
     *
     * The base amount is `reservation.price_snapshot` — but
     * ReservationExtensionService also adds every stay extension's amount
     * onto `price_snapshot` (so it reflects "current total accommodation
     * price" for display/loyalty purposes) AND posts that same amount as
     * its own separate `stay_extension` folio charge (so the folio shows it
     * as owed immediately, without waiting for checkout). Left alone, this
     * accommodation charge would therefore bill every extension a second
     * time. Already-posted `stay_extension` charges are subtracted here so
     * the two charge types never overlap — no second pricing calculation,
     * just excluding money this reservation already has its own charge for.
     *
     * Idempotent: `source_type = accommodation`, `source_id = reservation.id`,
     * so a checkout retry always reuses the same row and never duplicates it.
     *
     * Returns null when there is no accommodation amount left to bill after
     * that subtraction — nothing to post, exactly like a folio with no
     * service orders has no service lines.
     */
    public function postAccommodationCharge(Reservation $reservation, ?string $currency, ?User $actor): ?FolioCharge
    {
        $existing = $this->charges->findBySourceForUpdate(
            FolioCharge::SOURCE_ACCOMMODATION,
            $reservation->id,
        );

        if ($existing !== null) {
            return $existing;
        }

        $totalPrice = $reservation->price_snapshot === null
            ? null
            : bcadd((string) $reservation->price_snapshot, '0', 2);

        if ($totalPrice === null) {
            return null;
        }

        $extensionsAlreadyCharged = $this->charges->allForReservation($reservation->id)
            ->where('source_type', FolioCharge::SOURCE_STAY_EXTENSION)
            ->where('status', FolioCharge::STATUS_POSTED)
            ->reduce(fn (string $carry, FolioCharge $charge) => bcadd($carry, (string) $charge->total_amount, 2), '0.00');

        $amount = bcsub($totalPrice, $extensionsAlreadyCharged, 2);

        if (bccomp($amount, '0.00', 2) <= 0) {
            return null;
        }

        try {
            $charge = $this->charges->create([
                'reservation_id' => $reservation->id,
                'hotel_id' => $reservation->hotel_id,
                'source_type' => FolioCharge::SOURCE_ACCOMMODATION,
                'source_id' => $reservation->id,
                'description' => 'Accommodation',
                'quantity' => 1,
                'unit_amount' => $amount,
                'total_amount' => $amount,
                'currency' => $currency,
                'status' => FolioCharge::STATUS_POSTED,
                'charged_at' => now(),
                'created_by_user_id' => $actor?->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent checkout posted it first — the UNIQUE constraint
            // is authoritative. Re-read and return the winner.
            return $this->charges->findBySource(FolioCharge::SOURCE_ACCOMMODATION, $reservation->id);
        }

        $this->auditLogger->record(
            $actor,
            'folio_charge.created',
            $charge,
            after: array_filter([
                'folio_charge_status' => $charge->status,
                'source_type' => $charge->source_type,
                'source_id' => $charge->source_id,
                'total_amount' => $charge->total_amount,
                'currency' => $charge->currency,
                'charged_at' => $charge->charged_at?->toIso8601String(),
            ], fn ($value) => $value !== null),
            hotelId: $charge->hotel_id,
        );

        return $charge;
    }

    /**
     * Post (once) the booking's service fee line — the amount snapshotted on
     * the reservation at booking time. Idempotent (`source_type =
     * service_fee`, `source_id = reservation.id`); nothing is posted when the
     * booking has no fee.
     */
    public function postServiceFeeCharge(Reservation $reservation, ?string $currency, ?User $actor): ?FolioCharge
    {
        $amount = bcadd((string) ($reservation->service_fee_amount ?? '0'), '0', 2);

        if (bccomp($amount, '0.00', 2) <= 0) {
            return null;
        }

        $existing = $this->charges->findBySourceForUpdate(FolioCharge::SOURCE_SERVICE_FEE, $reservation->id);

        if ($existing !== null) {
            return $existing;
        }

        try {
            $charge = $this->charges->create([
                'reservation_id' => $reservation->id,
                'hotel_id' => $reservation->hotel_id,
                'source_type' => FolioCharge::SOURCE_SERVICE_FEE,
                'source_id' => $reservation->id,
                'description' => 'Service fee',
                'quantity' => 1,
                'unit_amount' => $amount,
                'total_amount' => $amount,
                'currency' => $currency,
                'status' => FolioCharge::STATUS_POSTED,
                'charged_at' => now(),
                'created_by_user_id' => $actor?->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->charges->findBySource(FolioCharge::SOURCE_SERVICE_FEE, $reservation->id);
        }

        $this->auditLogger->record($actor, 'folio_charge.created', $charge, after: [
            'source_type' => $charge->source_type,
            'source_id' => $charge->source_id,
            'total_amount' => $charge->total_amount,
            'currency' => $charge->currency,
        ], hotelId: $charge->hotel_id);

        return $charge;
    }

    /**
     * Post (once) the incremental accommodation charge for one Extend Stay
     * extension. `source_id` is the `reservation_extensions` row id (not the
     * reservation id — see [FolioCharge::SOURCE_STAY_EXTENSION]), so a
     * reservation extended twice gets two independent charges, each still
     * idempotent under the `(source_type, source_id)` UNIQUE exactly like
     * [postAccommodationCharge].
     *
     * $unitAmount / $totalAmount are decimal strings the caller has already
     * computed from `room_types.base_price` — no pricing decision is made
     * here.
     *
     * MUST be called from within the caller's DB transaction (the
     * reservation row is already locked).
     */
    public function postStayExtensionCharge(
        Reservation $reservation,
        int $extensionId,
        int $nightsAdded,
        string $unitAmount,
        string $totalAmount,
        ?string $currency,
        ?User $actor,
    ): FolioCharge {
        $existing = $this->charges->findBySourceForUpdate(
            FolioCharge::SOURCE_STAY_EXTENSION,
            $extensionId,
        );

        if ($existing !== null) {
            return $existing;
        }

        try {
            $charge = $this->charges->create([
                'reservation_id' => $reservation->id,
                'hotel_id' => $reservation->hotel_id,
                'source_type' => FolioCharge::SOURCE_STAY_EXTENSION,
                'source_id' => $extensionId,
                'description' => "Stay extension +{$nightsAdded} night(s)",
                'quantity' => $nightsAdded,
                'unit_amount' => $unitAmount,
                'total_amount' => $totalAmount,
                'currency' => $currency,
                'status' => FolioCharge::STATUS_POSTED,
                'charged_at' => now(),
                'created_by_user_id' => $actor?->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent replay posted it first — the UNIQUE constraint is
            // authoritative. Re-read and return the winner (guaranteed to
            // exist: the constraint only fires because that row is there).
            return $this->charges->findBySource(FolioCharge::SOURCE_STAY_EXTENSION, $extensionId)
                ?? throw new \RuntimeException('Stay extension folio charge disappeared after a unique-constraint conflict.');
        }

        $this->auditLogger->record(
            $actor,
            'folio_charge.created',
            $charge,
            after: array_filter([
                'folio_charge_status' => $charge->status,
                'source_type' => $charge->source_type,
                'source_id' => $charge->source_id,
                'total_amount' => $charge->total_amount,
                'currency' => $charge->currency,
                'charged_at' => $charge->charged_at?->toIso8601String(),
            ], fn ($value) => $value !== null),
            hotelId: $charge->hotel_id,
        );

        return $charge;
    }

    /**
     * Post (once) a loyalty redemption discount against the booking — a
     * negative line, capped at the accommodation total (`price_snapshot`), so
     * points only ever discount the stay, never services (R59). Idempotent
     * per reservation (`source_id` = reservation id).
     */
    public function postLoyaltyCredit(
        Reservation $reservation,
        int $points,
        string $amount,
        ?string $currency,
        ?User $actor,
    ): ?FolioCharge {
        $existing = $this->charges->findBySourceForUpdate(
            FolioCharge::SOURCE_LOYALTY_REDEMPTION,
            $reservation->id,
        );

        if ($existing !== null && $existing->status === FolioCharge::STATUS_POSTED) {
            return $existing;
        }

        $cap = bcadd((string) ($reservation->price_snapshot ?? '0'), '0', 2);
        $credit = bccomp($amount, $cap, 2) > 0 ? $cap : bcadd($amount, '0', 2);

        if (bccomp($credit, '0.00', 2) <= 0) {
            return null;
        }

        $negative = bcsub('0', $credit, 2);
        $attributes = [
            'description' => "Loyalty redemption ({$points} points)",
            'quantity' => 1,
            'unit_amount' => $negative,
            'total_amount' => $negative,
            'currency' => $currency,
            'status' => FolioCharge::STATUS_POSTED,
            'charged_at' => now(),
            'cancelled_at' => null,
            'created_by_user_id' => $actor?->id,
        ];

        $charge = $existing !== null
            // A voided credit (cancelled then re-redeemed) is re-posted in
            // place — the (source_type, source_id) UNIQUE allows one row.
            ? $this->charges->update($existing, $attributes)
            : $this->charges->create([
                'reservation_id' => $reservation->id,
                'hotel_id' => $reservation->hotel_id,
                'source_type' => FolioCharge::SOURCE_LOYALTY_REDEMPTION,
                'source_id' => $reservation->id,
            ] + $attributes);

        $this->auditLogger->record(
            $actor,
            'folio_charge.created',
            $charge,
            after: array_filter([
                'folio_charge_status' => $charge->status,
                'source_type' => $charge->source_type,
                'source_id' => $charge->source_id,
                'total_amount' => $charge->total_amount,
                'currency' => $charge->currency,
            ], fn ($value) => $value !== null),
            hotelId: $charge->hotel_id,
        );

        return $charge;
    }

    /** Void the booking's loyalty credit (its points were returned). */
    public function voidLoyaltyCredit(Reservation $reservation, ?User $actor): void
    {
        $charge = $this->charges->findBySourceForUpdate(
            FolioCharge::SOURCE_LOYALTY_REDEMPTION,
            $reservation->id,
        );

        if ($charge === null || $charge->status !== FolioCharge::STATUS_POSTED) {
            return;
        }

        $charge = $this->charges->update($charge, [
            'status' => FolioCharge::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);

        $this->auditLogger->record(
            $actor,
            'folio_charge.cancelled',
            $charge,
            after: [
                'folio_charge_status' => $charge->status,
                'source_type' => $charge->source_type,
                'source_id' => $charge->source_id,
            ],
            hotelId: $charge->hotel_id,
        );
    }
}

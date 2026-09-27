<?php

namespace App\Domain\Reservation\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Inventory\Repositories\Contracts\RoomRepositoryInterface;
use App\Domain\Inventory\Repositories\Contracts\RoomTypeRepositoryInterface;
use App\Domain\Reservation\Exceptions\ReservationExtensionIdempotencyKeyConflictException;
use App\Domain\Reservation\Exceptions\ReservationExtensionNotAllowedException;
use App\Domain\Reservation\Exceptions\ReservationNotAvailableException;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\Models\ReservationExtension;
use App\Domain\Reservation\Repositories\Contracts\ReservationExtensionRepositoryInterface;
use App\Domain\Reservation\Repositories\Contracts\ReservationRepositoryInterface;
use App\Domain\StayServices\Services\FolioChargeService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Extend Stay — lets a guest currently occupying a room (`checked_in` /
 * `in_stay`) push their checkout date out. Reuses the exact
 * availability/concurrency design `ReservationService::create()` already
 * uses (Room Type lock first, then the specific Room, both counts read only
 * after the lock is held) against just the *added* date range, prices the
 * addition from the authoritative `room_types.base_price` (no other pricing
 * input), and posts the amount as a `stay_extension` folio charge so it
 * accrues to the account and is settled through the existing
 * checkout/settlement flow — no separate payment call, no new pricing rule.
 *
 * `Payment.amount`/history is never read or written here.
 *
 * One DB transaction, no external provider call — unlike PaymentWorkflowService
 * this needs no A/B/C split.
 */
class ReservationExtensionService
{
    public function __construct(
        private readonly ReservationRepositoryInterface $reservations,
        private readonly ReservationExtensionRepositoryInterface $extensions,
        private readonly RoomTypeRepositoryInterface $roomTypes,
        private readonly RoomRepositoryInterface $rooms,
        private readonly FolioChargeService $folioCharges,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @throws ModelNotFoundException if the Reservation no longer exists.
     * @throws ReservationExtensionIdempotencyKeyConflictException if $idempotencyKey
     *                                                             was already used for a different reservation/date.
     * @throws ReservationExtensionNotAllowedException if the Reservation is
     *                                                 not `checked_in` / `in_stay`.
     * @throws ReservationNotAvailableException if $newCheckOut is not strictly
     *                                          after the Reservation's current check_out, or the added date
     *                                          range is not available for the Reservation's Room / Room Type.
     *                                          `new_check_out > check_out` is normally already enforced by the
     *                                          FormRequest (mirrors StoreGuestReservationRequest trusting
     *                                          check_out > check_in) — this is a defensive re-check, not the
     *                                          primary validation surface.
     */
    public function extend(
        Reservation $reservation,
        CarbonImmutable $newCheckOut,
        ?User $actor,
        ?string $idempotencyKey = null,
    ): ReservationExtension {
        return DB::transaction(function () use ($reservation, $newCheckOut, $actor, $idempotencyKey) {
            $current = $this->reservations->findForUpdate($reservation->id);

            if (! $current) {
                throw (new ModelNotFoundException)->setModel(Reservation::class, [$reservation->id]);
            }

            if ($idempotencyKey !== null) {
                $existing = $this->extensions->findByIdempotencyKey($idempotencyKey);

                if ($existing !== null) {
                    $this->assertIdempotentMatch($existing, $current, $newCheckOut);

                    return $existing;
                }
            }

            if (! in_array($current->status, [Reservation::STATUS_CHECKED_IN, Reservation::STATUS_IN_STAY], true)) {
                throw new ReservationExtensionNotAllowedException($current->status);
            }

            $previousCheckOut = CarbonImmutable::parse($current->check_out);

            // Defensive re-check: the FormRequest already rejects a
            // new_check_out that is not after the reservation's current
            // check_out (mirrors ReservationNotAvailableException's use as
            // the general "this date range does not work" business error).
            if (! $newCheckOut->gt($previousCheckOut)) {
                throw new ReservationNotAvailableException;
            }

            $roomType = $this->roomTypes->findForUpdate($current->room_type_id);

            if (! $roomType) {
                throw (new ModelNotFoundException)->setModel(RoomType::class, [$current->room_type_id]);
            }

            $room = $current->room_id ? $this->rooms->findForUpdate($current->room_id) : null;

            $checkInWire = $previousCheckOut->toDateString();
            $checkOutWire = $newCheckOut->toDateString();

            // Only the *added* window needs checking — the reservation's own
            // stay ([check_in, previous_check_out)) never overlaps
            // [previous_check_out, new_check_out) under the canonical
            // checkout-exclusive predicate, so it never counts against itself.
            if ($room) {
                $overlappingForRoom = $this->reservations->countOverlappingForRoom($room->id, $checkInWire, $checkOutWire);

                if ($overlappingForRoom > 0) {
                    throw new ReservationNotAvailableException;
                }
            }

            $blockingCount = $this->reservations->countOverlappingForRoomType($roomType->id, $checkInWire, $checkOutWire);
            $physicalRoomCount = $this->rooms->countByRoomType($roomType->id);

            if ($blockingCount >= $physicalRoomCount) {
                throw new ReservationNotAvailableException;
            }

            $nightsAdded = $previousCheckOut->diffInDays($newCheckOut);
            $unitPrice = (string) $roomType->base_price;
            $amount = bcmul($unitPrice, (string) $nightsAdded, 2);
            $currency = $current->currency ?: (config('payment.currency') ?: null);

            $updated = $this->reservations->update($current, [
                'check_out' => $newCheckOut,
                'price_snapshot' => bcadd((string) $current->price_snapshot, $amount, 2),
            ]);

            try {
                $extension = $this->extensions->create([
                    'reservation_id' => $updated->id,
                    'hotel_id' => $updated->hotel_id,
                    'previous_check_out' => $previousCheckOut,
                    'new_check_out' => $newCheckOut,
                    'nights_added' => $nightsAdded,
                    'unit_price' => $unitPrice,
                    'amount' => $amount,
                    'currency' => $currency,
                    'idempotency_key' => $idempotencyKey,
                    'created_by_staff_id' => $actor?->id,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // A concurrent request recorded this idempotency key first.
                $winner = $idempotencyKey === null ? null : $this->extensions->findByIdempotencyKey($idempotencyKey);

                if ($winner === null) {
                    throw $e;
                }

                $this->assertIdempotentMatch($winner, $current, $newCheckOut);

                return $winner;
            }

            $folioCharge = $this->folioCharges->postStayExtensionCharge(
                $updated,
                $extension->id,
                $nightsAdded,
                $unitPrice,
                $amount,
                $currency,
                $actor,
            );

            $extension = $this->extensions->update($extension, ['folio_charge_id' => $folioCharge->id]);

            $this->auditLogger->record(
                $actor,
                'reservation.extended',
                $updated,
                before: ['check_out' => $checkInWire, 'price_snapshot' => (string) $current->price_snapshot],
                after: ['check_out' => $checkOutWire, 'price_snapshot' => (string) $updated->price_snapshot],
                hotelId: $updated->hotel_id,
            );

            return $extension;
        });
    }

    /**
     * A replayed idempotency key is only valid for the exact same logical
     * operation — same reservation, same target checkout date.
     */
    private function assertIdempotentMatch(
        ReservationExtension $existing,
        Reservation $reservation,
        CarbonImmutable $newCheckOut,
    ): void {
        if ($existing->reservation_id !== $reservation->id) {
            throw new ReservationExtensionIdempotencyKeyConflictException('different reservation');
        }

        if (! CarbonImmutable::parse($existing->new_check_out)->isSameDay($newCheckOut)) {
            throw new ReservationExtensionIdempotencyKeyConflictException('different new_check_out');
        }
    }
}

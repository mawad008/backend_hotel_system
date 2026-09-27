<?php

namespace App\Domain\Reservation\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\Room;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Inventory\Repositories\Contracts\RoomRepositoryInterface;
use App\Domain\Inventory\Repositories\Contracts\RoomTypeRepositoryInterface;
use App\Domain\Reservation\Events\ReservationStatusChanged;
use App\Domain\Reservation\Exceptions\ReservationNotAvailableException;
use App\Domain\Reservation\Exceptions\ReservationRoomAssignmentNotAllowedException;
use App\Domain\Reservation\Exceptions\RoomHotelMismatchException;
use App\Domain\Reservation\Exceptions\RoomTypeMismatchException;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\Repositories\Contracts\GuestRepositoryInterface;
use App\Domain\Reservation\Repositories\Contracts\ReservationRepositoryInterface;
use App\Domain\Reservation\StateMachine\ReservationStateMachine;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3B foundation, Phase 3D availability/concurrency protection, and
 * Phase 4B status-transition execution (transitionTo). Structural
 * transition rules live only in ReservationStateMachine (Phase 4A).
 * Still deliberately does NOT implement: room allocation strategy, payment
 * or identity-verification preconditions, cancellation penalties — all
 * explicitly deferred to later phases.
 */
class ReservationService
{
    public function __construct(
        private readonly ReservationRepositoryInterface $reservations,
        private readonly RoomTypeRepositoryInterface $roomTypes,
        private readonly RoomRepositoryInterface $rooms,
        private readonly GuestRepositoryInterface $guests,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Reservations resolved from $user's own hotel access — never from a
     * request parameter.
     */
    public function listAccessibleBy(User $user, int $perPage = 15): LengthAwarePaginator
    {
        return $this->reservations->paginateAccessibleBy($user, $perPage);
    }

    /**
     * Scoped the same way as the list above — returns null both when the
     * reservation does not exist and when the user cannot access it.
     */
    public function findAccessibleBy(User $user, int $id): ?Reservation
    {
        return $this->reservations->findAccessibleBy($user, $id);
    }

    /**
     * The front-desk arrivals/departures/in-house lists — pure reads, no
     * workflow decision. Hotel scope is already confirmed by the Policy
     * before these are called (ReservationPolicy::viewFrontDesk).
     */
    public function arrivalsForHotel(int $hotelId, string $date, int $perPage = 15): LengthAwarePaginator
    {
        return $this->reservations->paginateArrivalsForHotel($hotelId, $date, $perPage);
    }

    public function departuresForHotel(int $hotelId, string $date, int $perPage = 15): LengthAwarePaginator
    {
        return $this->reservations->paginateDeparturesForHotel($hotelId, $date, $perPage);
    }

    public function inHouseForHotel(int $hotelId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->reservations->paginateInHouseForHotel($hotelId, $perPage);
    }

    /**
     * The guest booking API's read scope: a guest sees only their own
     * reservations (guest_id === $guest->id). Same thin delegation as
     * listAccessibleBy() — the ownership filter is the whole authorization.
     */
    public function listOwnedByGuest(Guest $guest, int $perPage = 15): LengthAwarePaginator
    {
        return $this->reservations->paginateOwnedByGuest($guest, $perPage);
    }

    /**
     * Guest-scoped single lookup — null both when the reservation does not
     * exist and when it belongs to another guest, so the API answers an
     * identical plain 404 for both.
     */
    public function findOwnedByGuest(Guest $guest, int $id): ?Reservation
    {
        return $this->reservations->findOwnedByGuest($guest, $id);
    }

    /**
     * Creates a Reservation in PENDING with a price snapshot taken from
     * the Room Type's current base_price, protected by the approved
     * Phase 3D availability/concurrency design.
     *
     * Locking (approved order, always Room Type first): the Room Type row
     * is locked first (`findForUpdate`), and — only if room_id is
     * supplied — the specific Room row is locked second. Both locks are
     * acquired before any availability count is read, and both are held
     * until the reservation is inserted and the transaction commits. The
     * Room Type lock is acquired even for a specific-room request: a
     * named Room being physically free is not by itself sufficient to
     * guarantee the Room Type's shared capacity (Option A, aggregate
     * across assigned and unassigned reservations) isn't exceeded by a
     * concurrent unassigned request racing on the same Room Type — only a
     * shared lock closes that race.
     *
     * Availability (approved rules):
     * - If room_id is supplied: reject if any blocking reservation for
     *   that exact Room overlaps the requested range (canonical
     *   checkout-exclusive predicate).
     * - Always (Option A): reject if the Room Type's blocking-reservation
     *   count for the range (assigned + unassigned) is not strictly less
     *   than its total physical Room count.
     * Both counts are read only after the relevant lock is held, and the
     * reservation is inserted only after both checks pass — never before.
     *
     * hotel_id is never accepted from $data — it is always derived from
     * the resolved Room Type, so a caller cannot widen authorization by
     * supplying an arbitrary hotel_id. Whether the acting user is even
     * allowed to create a reservation for that hotel is a Policy/Controller
     * concern — this Service trusts its caller exactly as
     * RoomTypeService/RoomService already do.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ModelNotFoundException if room_type_id, room_id, or guest_id
     *                                does not resolve to an existing record.
     * @throws RoomHotelMismatchException if room_id is supplied and does
     *                                    not belong to the same hotel as the resolved Room Type.
     * @throws RoomTypeMismatchException if room_id is supplied and does
     *                                   not belong to the selected Room Type.
     * @throws ReservationNotAvailableException if the requested range is
     *                                          not available for the specific Room or the Room Type's capacity.
     */
    public function create(array $data, ?User $actor): Reservation
    {
        return DB::transaction(function () use ($data, $actor) {
            $roomType = $this->roomTypes->findForUpdate((int) ($data['room_type_id'] ?? 0));

            if (! $roomType) {
                throw (new ModelNotFoundException)->setModel(RoomType::class, [$data['room_type_id'] ?? null]);
            }

            $hotelId = $roomType->hotel_id;
            $requestedRoomId = $data['room_id'] ?? null;
            $room = null;

            if ($requestedRoomId !== null) {
                $room = $this->rooms->findForUpdate((int) $requestedRoomId);

                if (! $room) {
                    throw (new ModelNotFoundException)->setModel(Room::class, [$requestedRoomId]);
                }

                if ($room->hotel_id !== $hotelId) {
                    throw new RoomHotelMismatchException;
                }

                if ($room->room_type_id !== $roomType->id) {
                    throw new RoomTypeMismatchException;
                }
            }

            $guest = $this->guests->find((int) ($data['guest_id'] ?? 0));

            if (! $guest) {
                throw (new ModelNotFoundException)->setModel(Guest::class, [$data['guest_id'] ?? null]);
            }

            $checkIn = $data['check_in'];
            $checkOut = $data['check_out'];

            if ($room) {
                $overlappingForRoom = $this->reservations->countOverlappingForRoom($room->id, $checkIn, $checkOut);

                if ($overlappingForRoom > 0) {
                    throw new ReservationNotAvailableException;
                }
            }

            $blockingCount = $this->reservations->countOverlappingForRoomType($roomType->id, $checkIn, $checkOut);
            $physicalRoomCount = $this->rooms->countByRoomType($roomType->id);

            if ($blockingCount >= $physicalRoomCount) {
                throw new ReservationNotAvailableException;
            }

            $data['hotel_id'] = $hotelId;
            $data['room_type_id'] = $roomType->id;
            $data['room_id'] = $room?->id;
            $data['guest_id'] = $guest->id;
            $data['status'] = Reservation::STATUS_PENDING;
            // base_price is the room type's NIGHTLY rate (the discovery
            // quote's `estimated_total` and ReservationExtensionService both
            // price nights × base_price); price_snapshot is the stay's total
            // accommodation price that the folio bills and the deposit
            // percentage applies to.
            $nights = (int) CarbonImmutable::parse($checkIn)->diffInDays(CarbonImmutable::parse($checkOut));
            $data['price_snapshot'] = bcmul((string) $roomType->base_price, (string) max($nights, 1), 2);
            // The hotel's booking service fee, snapshotted (dashboard changes never re-price a booking).
            $data['service_fee_amount'] = $roomType->hotel->serviceFeeFor($data['price_snapshot']);
            // Approved cancellation policy, snapshotted at booking time.
            $data = array_merge($data, ReservationCancellationService::snapshotFor($roomType, $roomType->hotel, (string) $checkIn));
            $data['currency'] = strtoupper((string) config('payment.currency'));
            $data['created_by_staff_id'] = $actor?->id;
            $data['cancelled_at'] = null;
            $data['cancellation_reason'] = null;

            $reservation = $this->reservations->create($data);

            $this->auditLogger->record(
                $actor,
                'reservation.created',
                $reservation,
                after: $reservation->toArray(),
                hotelId: $hotelId,
            );

            return $reservation;
        });
    }

    /**
     * Executes a Reservation status transition. Structural validity is
     * decided exclusively by ReservationStateMachine (Phase 4A) — this
     * method owns only the transaction, the row lock, persistence, and the
     * audit entry, never the transition map.
     *
     * The passed $reservation is used only for its id. The authoritative
     * current status is re-read under a row lock inside the transaction
     * (`findForUpdate`), so a stale status on the passed model can never
     * drive the decision and two concurrent transitions cannot both act on
     * the same starting state — the same transaction + lock + revalidate
     * shape RoomService::transitionStatus already uses.
     *
     * Phase 4B deliberately does NOT check payment or identity-verification
     * preconditions (e.g. the §8 rule that CHECKED_IN needs confirmed
     * payment + verified identity): those domains are Phase 5 / Phase 6 and
     * their guards are layered on later. Availability is likewise untouched
     * — every status except CANCELLED already blocks inventory (Phase 3D),
     * so a forward transition never changes a reservation's blocking effect
     * and needs no re-check.
     *
     * @throws ModelNotFoundException if the Reservation no longer exists.
     * @throws InvalidReservationStatusTransitionException if $currentStatus
     *                                                     → $targetStatus is not an approved transition.
     */
    public function transitionTo(Reservation $reservation, string $targetStatus, ?User $actor = null): Reservation
    {
        $fromStatus = null;

        $transitioned = DB::transaction(function () use ($reservation, $targetStatus, $actor, &$fromStatus) {
            $current = $this->reservations->findForUpdate($reservation->id);

            if (! $current) {
                throw (new ModelNotFoundException)->setModel(Reservation::class, [$reservation->id]);
            }

            $fromStatus = $current->status;

            ReservationStateMachine::assertCanTransition($fromStatus, $targetStatus);

            $before = $current->toArray();

            $current = $this->reservations->update($current, ['status' => $targetStatus]);

            $this->auditLogger->record(
                $actor,
                'reservation.status_changed',
                $current,
                before: $before,
                after: $current->toArray(),
                hotelId: $current->hotel_id,
            );

            return $current;
        });

        // Phase 11 — the single post-commit seam for downstream side effects
        // (notifications). Emitted only after the status change is durable, so
        // a listener never runs inside this transaction and a listener failure
        // can never roll the transition back. The Reservation domain has no
        // dependency on any listener.
        ReservationStatusChanged::dispatch($transitioned, (string) $fromStatus, $actor, app()->getLocale());

        return $transitioned;
    }

    /**
     * Reservation statuses in which staff may assign or move the physical
     * room: every live (inventory-blocking) status before checkout starts.
     */
    public const ROOM_ASSIGNABLE_STATUSES = [
        Reservation::STATUS_PENDING,
        Reservation::STATUS_DEPOSIT_HELD,
        Reservation::STATUS_VERIFIED,
        Reservation::STATUS_CHECKED_IN,
        Reservation::STATUS_IN_STAY,
    ];

    /** A room in this operational status is never offered or assigned. */
    private const ROOM_OUT_OF_SERVICE_STATUS = 'under_maintenance';

    /**
     * The rooms the front desk may assign to $reservation right now: its
     * hotel + room type, not under maintenance, and not held by another
     * reservation over the same dates (its own current room stays listed).
     *
     * @return Collection<int, Room>
     */
    public function assignableRooms(Reservation $reservation): Collection
    {
        $booked = $this->reservations->bookedRoomIdsForRoomType(
            $reservation->room_type_id,
            $reservation->check_in->toDateString(),
            $reservation->check_out->toDateString(),
            $reservation->id,
        );

        return $this->rooms->allForRoomType($reservation->room_type_id)
            ->filter(fn (Room $room) => $room->hotel_id === $reservation->hotel_id
                && $room->status !== self::ROOM_OUT_OF_SERVICE_STATUS
                && ! in_array($room->id, $booked, true))
            ->values();
    }

    /**
     * Front desk assigns (or moves) the physical room for a reservation —
     * guest-app bookings are always created room-less (room type only).
     * The room must belong to the reservation's hotel and room type and be
     * free for the stay's dates; the Room Type capacity is unaffected (the
     * reservation already consumes one unit of it).
     *
     * @throws ReservationRoomAssignmentNotAllowedException
     * @throws RoomHotelMismatchException
     * @throws RoomTypeMismatchException
     * @throws ReservationNotAvailableException
     */
    public function assignRoom(Reservation $reservation, int $roomId, ?User $actor): Reservation
    {
        return DB::transaction(function () use ($reservation, $roomId, $actor) {
            $current = $this->reservations->findForUpdate($reservation->id);

            if (! $current) {
                throw (new ModelNotFoundException)->setModel(Reservation::class, [$reservation->id]);
            }

            if (! in_array($current->status, self::ROOM_ASSIGNABLE_STATUSES, true)) {
                throw new ReservationRoomAssignmentNotAllowedException($current->status);
            }

            $room = $this->rooms->findForUpdate($roomId);

            if (! $room) {
                throw (new ModelNotFoundException)->setModel(Room::class, [$roomId]);
            }

            if ($room->hotel_id !== $current->hotel_id) {
                throw new RoomHotelMismatchException;
            }

            if ($room->room_type_id !== $current->room_type_id) {
                throw new RoomTypeMismatchException;
            }

            if ($room->status === self::ROOM_OUT_OF_SERVICE_STATUS) {
                throw new ReservationNotAvailableException;
            }

            $checkIn = $current->check_in->toDateString();
            $checkOut = $current->check_out->toDateString();

            if ($this->reservations->countOverlappingForRoomExcluding($room->id, $checkIn, $checkOut, $current->id) > 0) {
                throw new ReservationNotAvailableException;
            }

            $before = $current->toArray();

            // Who assigned / last moved the room, and when.
            $current = $this->reservations->update($current, [
                'room_id' => $room->id,
                'room_assigned_by_user_id' => $actor?->id,
                'room_assigned_at' => now(),
            ]);

            $this->auditLogger->record(
                $actor,
                'reservation.room_assigned',
                $current,
                before: $before,
                after: $current->toArray(),
                hotelId: $current->hotel_id,
            );

            return $current;
        });
    }
}

<?php

namespace App\Domain\Reservation\Repositories;

use App\Domain\IdentityAccess\Models\User;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\Repositories\Contracts\ReservationRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class EloquentReservationRepository implements ReservationRepositoryInterface
{
    public function paginateAccessibleBy(User $user, int $perPage = 15): LengthAwarePaginator
    {
        return $this->scopeToAccess(Reservation::query(), $user)->paginate($perPage);
    }

    public function findAccessibleBy(User $user, int $id): ?Reservation
    {
        return $this->scopeToAccess(Reservation::query(), $user)->find($id);
    }

    public function paginateOwnedByGuest(Guest $guest, int $perPage = 15): LengthAwarePaginator
    {
        return Reservation::query()
            ->where('guest_id', $guest->id)
            ->with(['hotel.cover', 'hotel.cityRef', 'roomType', 'room', 'payment'])
            ->latest('id')
            ->paginate($perPage);
    }

    public function findOwnedByGuest(Guest $guest, int $id): ?Reservation
    {
        return Reservation::query()
            ->where('guest_id', $guest->id)
            ->find($id);
    }

    public function paginateForGuestAccessibleBy(User $user, Guest $guest, int $perPage = 15): LengthAwarePaginator
    {
        return $this->scopeToAccess(Reservation::query()->where('guest_id', $guest->id), $user)
            ->latest('id')
            ->paginate($perPage);
    }

    public function create(array $data): Reservation
    {
        return Reservation::create($data);
    }

    public function findForUpdate(int $id): ?Reservation
    {
        return Reservation::query()->lockForUpdate()->find($id);
    }

    public function update(Reservation $reservation, array $data): Reservation
    {
        $reservation->update($data);

        return $reservation->refresh();
    }

    public function paginateArrivalsForHotel(int $hotelId, string $date, int $perPage = 15): LengthAwarePaginator
    {
        return Reservation::query()
            ->where('hotel_id', $hotelId)
            ->where('check_in', $date)
            ->where('status', '!=', Reservation::STATUS_CANCELLED)
            ->orderBy('check_in')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function paginateDeparturesForHotel(int $hotelId, string $date, int $perPage = 15): LengthAwarePaginator
    {
        return Reservation::query()
            ->where('hotel_id', $hotelId)
            ->where('check_out', $date)
            ->where('status', '!=', Reservation::STATUS_CANCELLED)
            ->orderBy('check_out')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function paginateInHouseForHotel(int $hotelId, int $perPage = 15): LengthAwarePaginator
    {
        return Reservation::query()
            ->where('hotel_id', $hotelId)
            ->whereIn('status', [
                Reservation::STATUS_IN_STAY,
                Reservation::STATUS_CHECKOUT_IN_PROGRESS,
                Reservation::STATUS_CHECKOUT_BLOCKED,
            ])
            ->orderBy('check_out')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function overlappingForHotel(int $hotelId, string $from, string $to): Collection
    {
        return $this->overlapQuery($from, $to)
            ->where('hotel_id', $hotelId)
            ->get(['id', 'check_in', 'check_out']);
    }

    public function paginateForFolioLedger(int $hotelId, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = Reservation::query()
            ->where('hotel_id', $hotelId)
            ->where('status', '!=', Reservation::STATUS_CANCELLED);

        if (array_key_exists('reservation_ids', $filters)) {
            $query->whereIn('id', $filters['reservation_ids']);
        }

        if (($filters['search'] ?? null) !== null && $filters['search'] !== '') {
            $search = $filters['search'];
            $query->where(function (Builder $q) use ($search): void {
                $q->where('id', 'like', $search.'%')
                    ->orWhereHas('guest', function (Builder $g) use ($search): void {
                        $g->where('name', 'like', '%'.$search.'%')
                            ->orWhere('phone', 'like', '%'.$search.'%');
                    });
            });
        }

        return $query->orderByDesc('check_in')->paginate($perPage)->withQueryString();
    }

    public function countsByStatusForHotel(int $hotelId, string $from, string $to): array
    {
        return Reservation::query()
            ->where('hotel_id', $hotelId)
            ->whereBetween('check_in', [$from, $to])
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as aggregate')
            ->pluck('aggregate', 'status')
            ->all();
    }

    public function countOverlappingForRoom(int $roomId, string $checkIn, string $checkOut): int
    {
        return $this->overlapQuery($checkIn, $checkOut)
            ->where('room_id', $roomId)
            ->count();
    }

    public function countOverlappingForRoomExcluding(int $roomId, string $checkIn, string $checkOut, int $excludedReservationId): int
    {
        return $this->overlapQuery($checkIn, $checkOut)
            ->where('room_id', $roomId)
            ->whereKeyNot($excludedReservationId)
            ->count();
    }

    public function bookedRoomIdsForRoomType(int $roomTypeId, string $checkIn, string $checkOut, int $excludedReservationId): array
    {
        return $this->overlapQuery($checkIn, $checkOut)
            ->where('room_type_id', $roomTypeId)
            ->whereNotNull('room_id')
            ->whereKeyNot($excludedReservationId)
            ->pluck('room_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function countOverlappingForRoomType(int $roomTypeId, string $checkIn, string $checkOut): int
    {
        return $this->overlapQuery($checkIn, $checkOut)
            ->where('room_type_id', $roomTypeId)
            ->count();
    }

    /**
     * Canonical, checkout-exclusive overlap predicate (approved Phase 3D):
     * existing.check_in < $checkOut AND $checkIn < existing.check_out.
     * Strict `<` on both sides is what allows adjacent stays through.
     * Scoped to Reservation::BLOCKING_STATUSES as an explicit positive
     * list — never `status != cancelled` — so an unrecognized future
     * status can never silently become inventory-blocking.
     */
    private function overlapQuery(string $checkIn, string $checkOut): Builder
    {
        return Reservation::query()
            ->whereIn('status', Reservation::BLOCKING_STATUSES)
            ->where('check_in', '<', $checkOut)
            ->where('check_out', '>', $checkIn);
    }

    /**
     * Reservation deliberately does not use the HotelScoped trait (Phase
     * 3A decision) while its schema/model shape is still under review, so
     * the same Group-Owner-bypass / assigned-hotels-only rule the trait
     * applies elsewhere is reproduced here directly against the user's own
     * stored access records — never a client-supplied hotel_id.
     */
    private function scopeToAccess(Builder $query, User $user): Builder
    {
        if ($user->isGroupOwner()) {
            return $query;
        }

        return $query->whereIn('hotel_id', $user->authorizedHotelIds());
    }
}

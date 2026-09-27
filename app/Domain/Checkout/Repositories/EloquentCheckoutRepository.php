<?php

namespace App\Domain\Checkout\Repositories;

use App\Domain\Checkout\Models\Checkout;
use App\Domain\Checkout\Repositories\Contracts\CheckoutRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentCheckoutRepository implements CheckoutRepositoryInterface
{
    public function paginateForHotel(int $hotelId, array $filters, int $perPage): LengthAwarePaginator
    {
        return Checkout::query()
            ->where('hotel_id', $hotelId)
            ->when(($filters['status'] ?? null) !== null, fn ($q) => $q->where('status', $filters['status']))
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }

    public function find(int $id): ?Checkout
    {
        return Checkout::query()->find($id);
    }

    public function findByReservation(int $reservationId): ?Checkout
    {
        return Checkout::query()->where('reservation_id', $reservationId)->first();
    }

    public function findByReservationForUpdate(int $reservationId): ?Checkout
    {
        return Checkout::query()
            ->where('reservation_id', $reservationId)
            ->lockForUpdate()
            ->first();
    }

    public function create(array $data): Checkout
    {
        return Checkout::create($data)->refresh();
    }

    public function update(Checkout $checkout, array $data): Checkout
    {
        $checkout->update($data);

        return $checkout->refresh();
    }
}

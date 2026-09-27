<?php

namespace App\Domain\Reservation\Repositories;

use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Repositories\Contracts\GuestRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentGuestRepository implements GuestRepositoryInterface
{
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return Guest::query()
            ->withCount('reservations')
            ->when(($filters['search'] ?? null) !== null && $filters['search'] !== '', function (Builder $q) use ($filters): void {
                $search = $filters['search'];
                $q->where(function (Builder $inner) use ($search): void {
                    $inner->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhere('phone', 'like', '%'.$search.'%');
                });
            })
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function find(int $id): ?Guest
    {
        return Guest::query()->find($id);
    }

    public function findByPhone(string $phone): ?Guest
    {
        return Guest::query()->where('phone', $phone)->first();
    }

    public function create(array $data): Guest
    {
        return Guest::create($data);
    }

    public function update(Guest $guest, array $data): Guest
    {
        $guest->update($data);

        return $guest->refresh();
    }
}

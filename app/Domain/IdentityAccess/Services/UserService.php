<?php

namespace App\Domain\IdentityAccess\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityAccess\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly HotelAccessService $hotelAccess,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function list(int $perPage = 15): LengthAwarePaginator
    {
        return $this->users->paginate(perPage: $perPage);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor): User
    {
        return DB::transaction(function () use ($data, $actor) {
            $hotelIds = $data['hotel_ids'] ?? [];
            unset($data['hotel_ids']);

            $data['password'] = Hash::make($data['password']);

            $user = $this->users->create($data);

            if ($hotelIds !== []) {
                $this->hotelAccess->syncHotelAccess($user, $hotelIds);
            }

            $this->auditLogger->record($actor, 'user.created', $user, after: $user->fresh('hotels')->toArray());

            return $user->load(['role', 'hotels']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, array $data, ?User $actor): User
    {
        return DB::transaction(function () use ($user, $data, $actor) {
            $before = $user->fresh('hotels')->toArray();

            $hotelIds = $data['hotel_ids'] ?? null;
            unset($data['hotel_ids']);

            if (! empty($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            } else {
                unset($data['password']);
            }

            $this->users->update($user, $data);

            // A deactivated account or a changed password ends every existing
            // session — tokens never expire on their own.
            if ((array_key_exists('is_active', $data) && ! $data['is_active']) || isset($data['password'])) {
                $user->tokens()->delete();
            }

            if ($hotelIds !== null) {
                $this->hotelAccess->syncHotelAccess($user, $hotelIds);
            }

            $after = $user->fresh('hotels')->toArray();

            $this->auditLogger->record($actor, 'user.updated', $user, before: $before, after: $after);

            return $user->load(['role', 'hotels']);
        });
    }

    public function delete(User $user, ?User $actor): bool
    {
        return DB::transaction(function () use ($user, $actor) {
            $before = $user->toArray();

            $result = $this->users->delete($user);

            $this->auditLogger->record($actor, 'user.deleted', $user, before: $before);

            return $result;
        });
    }
}

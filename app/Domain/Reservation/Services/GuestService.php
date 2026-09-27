<?php

namespace App\Domain\Reservation\Services;

use App\Domain\IdentityAccess\Models\User;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Repositories\Contracts\GuestRepositoryInterface;
use App\Domain\Reservation\Repositories\Contracts\ReservationRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Staff-facing guest directory. Primarily read-only — a Guest's own profile
 * is normally created/updated through the guest app's own auth/profile flow
 * (GuestAuthService). The one staff-initiated mutation is create() (front
 * desk registering a walk-in with no app account yet): it reuses the same
 * repository, the same `phone` UNIQUE identity, and produces a row
 * indistinguishable from one the guest app would create — a later OTP
 * verification with the same phone resolves to this exact Guest
 * (GuestAuthService::verifyOtp() looks up by phone before creating).
 */
class GuestService
{
    public function __construct(
        private readonly GuestRepositoryInterface $guests,
        private readonly ReservationRepositoryInterface $reservations,
    ) {}

    /**
     * @param  array{search?: string|null}  $filters
     */
    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->guests->paginate($filters, $this->clampPerPage($perPage));
    }

    public function find(int $id): ?Guest
    {
        return $this->guests->find($id);
    }

    /**
     * @param  array{name: string|null, phone: string, email: string|null}  $data
     */
    public function create(array $data): Guest
    {
        return $this->guests->create([
            'name' => $data['name'] ?? null,
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            // Never set by staff — matches the guest app's own vocabulary:
            // this profile is not phone-verified until its owner completes
            // OTP verification themselves.
            'phone_verified_at' => null,
            'profile_completed_at' => null,
        ]);
    }

    public function reservationsFor(User $user, Guest $guest, int $perPage = 15): LengthAwarePaginator
    {
        return $this->reservations->paginateForGuestAccessibleBy($user, $guest, $this->clampPerPage($perPage));
    }

    private function clampPerPage(int $perPage): int
    {
        return min(max($perPage, 1), 100);
    }
}

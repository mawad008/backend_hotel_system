<?php

namespace App\Domain\Shared\Concerns;

use App\Domain\IdentityAccess\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Restricts a hotel-scoped model's query results to the hotels the given
 * user is authorized to access. Hotel scope is always resolved from the
 * user's stored `user_hotel_access` records (or their Group Owner
 * all-hotels bypass) — never from a client-supplied hotel_id.
 */
trait HotelScoped
{
    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        if ($user->isGroupOwner()) {
            return $query;
        }

        return $query->whereIn(
            $this->qualifyColumn($this->hotelScopeColumn()),
            $user->authorizedHotelIds()
        );
    }

    /**
     * The column on this model that identifies the owning hotel.
     */
    public function hotelScopeColumn(): string
    {
        return 'hotel_id';
    }
}

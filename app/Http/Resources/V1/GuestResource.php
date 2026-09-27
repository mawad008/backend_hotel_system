<?php

namespace App\Http\Resources\V1;

use App\Domain\Reservation\Models\Guest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Guest
 *
 * The authoritative guest shape for the mobile app. Field name is `name`
 * (not `full_name`) — consistent with UserResource / ReservationResource.
 *
 * `reservations_count` only appears when the caller eager-loaded it via
 * `withCount('reservations')` (the staff directory listing) — the guest
 * app's own profile view never loads that count.
 */
class GuestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'phone_verified_at' => $this->phone_verified_at,
            'profile_completed_at' => $this->profile_completed_at,
            'profile_complete' => $this->isProfileComplete(),
            // Read by every hotel of the group to prepare the room; shown to
            // staff on the guest profile.
            'preferences' => $this->resolvedPreferences(),
            // PROFILE_Privacy identity-image choice (default: delete after checkout).
            'identity_retention' => $this->identity_retention ?? Guest::IDENTITY_DELETE_AFTER_CHECKOUT,
            'data_deletion_requested_at' => $this->data_deletion_requested_at,
            'reservations_count' => $this->whenCounted('reservations'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

<?php

namespace App\Http\Resources\V1;

use App\Domain\DigitalAccess\Models\AccessGrant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Phase 7 — the safe HTTP representation of a digital access grant.
 *
 * Only application-level status fields are exposed. The provider reference,
 * `idempotency_key`, attempt `metadata` and any internal/database detail are
 * deliberately absent (Phase 0 §17, "no internal provider references unless
 * required by the API contract").
 *
 * The `credential` (PIN) is the ONE piece of secret data returned, and ONLY
 * while the grant is ACTIVE — §11: "pin_code (… an app-delivered code)". It
 * is the minimum representation the guest needs to open the door. It is
 * nulled server-side the moment the grant is revoked or expires, so a
 * revoked/expired grant never carries it.
 *
 * @mixin AccessGrant
 */
class AccessGrantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'reservation_id' => $this->reservation_id,
            'hotel_id' => $this->hotel_id,
            'guest_id' => $this->guest_id,
            'status' => $this->status,
            'access_mode' => $this->access_mode,
            'provider' => $this->provider,
            'issued_at' => $this->issued_at,
            'activated_at' => $this->activated_at,
            'expires_at' => $this->expires_at,
            'revoked_at' => $this->revoked_at,
            'revocation_reason' => $this->revocation_reason,
            'failure_reason' => $this->failure_reason,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            // The app-delivered PIN — present only for an active credential.
            'credential' => $this->when(
                $this->status === AccessGrant::STATUS_ACTIVE && $this->credential !== null,
                fn () => $this->credential,
            ),
        ];
    }
}

<?php

namespace App\Http\Resources\V1;

use App\Domain\Audit\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditLog
 *
 * `before`/`after` are whatever safe snapshot the recording call chose to
 * pass AuditLogger::record() — every call site already excludes secrets/
 * card data/provider payloads at the source, so nothing is re-filtered
 * here. `actor` is a minimal identity, loaded only `whenLoaded`.
 */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'actor_id' => $this->actor_id,
            'actor' => $this->whenLoaded('actor', fn () => [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
                'email' => $this->actor->email,
            ]),
            'action' => $this->action,
            'auditable_type' => $this->auditable_type,
            'auditable_id' => $this->auditable_id,
            'hotel_id' => $this->hotel_id,
            'before' => $this->before,
            'after' => $this->after,
            'ip_address' => $this->ip_address,
            'created_at' => $this->created_at,
        ];
    }
}

<?php

namespace App\Http\Resources\V1;

use App\Domain\Notification\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Notification
 *
 * Safe fields only. The idempotency key and the provider reference are never
 * exposed; `subject` / `body` are the vetted localized template text and
 * `context` holds only non-sensitive scalars (from/to status, reference).
 * No recipient contact value is present on the row in the first place.
 */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reservation_id' => $this->reservation_id,
            'hotel_id' => $this->hotel_id,
            'type' => $this->type->value,
            'channel' => $this->channel->value,
            'status' => $this->status->value,
            'locale' => $this->locale,
            'subject' => $this->subject,
            'body' => $this->body,
            'context' => $this->context,
            'is_read' => $this->isRead(),
            'read_at' => $this->read_at,
            'sent_at' => $this->sent_at,
            'failed_at' => $this->failed_at,
            'created_at' => $this->created_at,
        ];
    }
}

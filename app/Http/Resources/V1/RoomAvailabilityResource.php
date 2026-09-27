<?php

namespace App\Http\Resources\V1;

use App\Domain\Discovery\Services\RoomAvailability;
use App\Support\LocalizedContent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RoomAvailability
 *
 * `gallery` mirrors `PublicRoomTypeResource::gallery` — the room type's
 * photos, in display order. The relation is already eager-loaded by
 * `EloquentHotelCatalogRepository::activeRoomTypesForHotel` (which
 * `HotelDiscoveryService::availability` builds every row from), so reading
 * it here is never an extra query.
 *
 * `bed_type` / `area_sqm` / `breakfast_included` / `refundable` mirror
 * `PublicRoomTypeResource`'s same fields, read off `$this->roomType`.
 */
class RoomAvailabilityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'room_type_id' => $this->roomType->id,
            'name' => $this->roomType->name,
            'base_price' => $this->roomType->base_price,
            'currency' => config('payment.currency'),
            'capacity' => $this->roomType->capacity,
            'amenities' => $this->roomType->amenities ?? [],
            'description' => $this->roomType->description,
            'bed_type' => LocalizedContent::resolve($this->roomType->bed_type_i18n),
            'view' => LocalizedContent::resolve($this->roomType->view_i18n),
            'area_sqm' => $this->roomType->area_sqm,
            'breakfast_included' => (bool) $this->roomType->breakfast_included,
            'refundable' => (bool) $this->roomType->refundable,
            // Room Detail: badge, "the rate includes" list, catalog facilities.
            ...app(\App\Http\Resources\V1\Support\RoomDetailContent::class)->for($this->roomType),
            'custom_specs' => collect($this->roomType->custom_specs ?? [])
                ->map(fn (array $spec) => [
                    'label' => LocalizedContent::resolve($spec['label_i18n'] ?? null),
                    'value' => LocalizedContent::resolve($spec['value_i18n'] ?? null),
                ])
                ->filter(fn (array $spec) => $spec['label'] !== null && $spec['label'] !== '' && $spec['value'] !== null && $spec['value'] !== '')
                ->values()
                ->all(),
            'gallery' => RoomMediaResource::collection($this->roomType->galleryMedia),
            'rooms_total' => $this->roomsTotal,
            'rooms_available' => $this->roomsAvailable,
            'is_available' => $this->isAvailable(),
            'nights' => $this->nights,
            'estimated_total' => $this->estimatedTotal,
        ];
    }
}

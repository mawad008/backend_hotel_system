<?php

namespace App\Http\Resources\V1;

use App\Domain\Inventory\Models\RoomType;
use App\Support\LocalizedContent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RoomType
 *
 * Anonymous guest view of a room type (Slice 1). No `is_active` (always true
 * here) and no raw inventory counts — availability for a stay comes from the
 * availability endpoint.
 *
 * `gallery` is present only when the relation is eager-loaded (see
 * `EloquentHotelCatalogRepository::activeRoomTypesForHotel`) — same
 * `whenLoaded` convention as `PublicHotelResource::gallery`. The mobile app
 * treats `gallery[0].url` as the room type's cover photo; there is no
 * separate cover collection for room types (see `RoomMedia::COLLECTION_GALLERY`).
 *
 * `bed_type` is resolved from `bed_type_i18n` to the request locale (same
 * `LocalizedContent` convention as the hotel's own name/tagline/description);
 * `area_sqm` / `breakfast_included` / `refundable` are plain columns. All
 * four are nullable/false when the room type has none on file — never
 * fabricated by either the API or the mobile client.
 */
class PublicRoomTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hotel_id' => $this->hotel_id,
            'name' => $this->name,
            'base_price' => $this->base_price,
            'currency' => config('payment.currency'),
            'capacity' => $this->capacity,
            'amenities' => $this->amenities ?? [],
            'description' => $this->description,
            'bed_type' => LocalizedContent::resolve($this->bed_type_i18n),
            'view' => LocalizedContent::resolve($this->view_i18n),
            'area_sqm' => $this->area_sqm,
            'breakfast_included' => (bool) $this->breakfast_included,
            'refundable' => (bool) $this->refundable,
            // Room Detail: badge, "the rate includes" list, catalog facilities.
            ...app(\App\Http\Resources\V1\Support\RoomDetailContent::class)->for($this->resource),
            'custom_specs' => $this->resolvedCustomSpecs(),
            'gallery' => RoomMediaResource::collection($this->whenLoaded('galleryMedia')),
        ];
    }

    /** @return list<array{label: string, value: string}> */
    private function resolvedCustomSpecs(): array
    {
        return collect($this->custom_specs ?? [])
            ->map(fn (array $spec) => [
                'label' => LocalizedContent::resolve($spec['label_i18n'] ?? null),
                'value' => LocalizedContent::resolve($spec['value_i18n'] ?? null),
            ])
            ->filter(fn (array $spec) => $spec['label'] !== null && $spec['label'] !== '' && $spec['value'] !== null && $spec['value'] !== '')
            ->values()
            ->all();
    }
}

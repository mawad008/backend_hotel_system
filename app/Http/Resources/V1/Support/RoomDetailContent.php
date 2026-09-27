<?php

namespace App\Http\Resources\V1\Support;

use App\Domain\HotelGroup\Models\Facility;
use App\Domain\Inventory\Models\RoomType;
use App\Support\LocalizedContent;
use Illuminate\Support\Collection;

/**
 * The guest-facing Room Detail content shared by `PublicRoomTypeResource`
 * and `RoomAvailabilityResource` (Figma v2 `ROOM_Detail_Premium`).
 *
 * `facilities` resolves the room's stored amenity keys against the
 * admin-managed facility catalog — `{key, label, icon}` in the request
 * locale, same shape as a hotel's `amenities` — so the app renders whatever
 * the dashboard assigned, never a hardcoded subset. Inactive catalog entries
 * are hidden. The catalog is read once per request (scoped binding).
 */
class RoomDetailContent
{
    /** @var Collection<string, Facility>|null */
    private ?Collection $catalog = null;

    /** @return array{tag: ?string, inclusions: list<string>, facilities: list<array{key: string, label: string, icon: ?string}>} */
    public function for(RoomType $roomType): array
    {
        return [
            'tag' => $this->text(LocalizedContent::resolve($roomType->tag_i18n)),
            'inclusions' => collect($roomType->inclusions_i18n ?? [])
                ->map(fn ($item) => is_array($item) ? $this->text(LocalizedContent::resolve($item)) : null)
                ->filter()
                ->values()
                ->all(),
            'facilities' => $this->facilities($roomType->amenities ?? []),
        ];
    }

    /** @param list<string> $keys */
    private function facilities(array $keys): array
    {
        $catalog = $this->catalog ??= Facility::query()
            ->where('is_active', true)
            ->get()
            ->keyBy('key');

        return collect($keys)
            ->map(fn ($key) => $catalog->get($key))
            ->filter()
            ->map(fn (Facility $facility) => [
                'key' => $facility->key,
                'label' => LocalizedContent::resolve($facility->name_i18n) ?? $facility->key,
                'icon' => $facility->icon,
            ])
            ->values()
            ->all();
    }

    private function text(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}

<?php

namespace App\Domain\HotelGroup\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\HotelGroup\Exceptions\HotelDeletionBlockedException;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Repositories\Contracts\HotelRepositoryInterface;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Location\Models\City;
use App\Domain\Location\Services\CityService;
use App\Support\LocalizedContent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class HotelService
{
    public function __construct(
        private readonly HotelRepositoryInterface $hotels,
        private readonly AuditLogger $auditLogger,
        private readonly CityService $cities,
    ) {}

    /**
     * Hotels visible to $user, resolved from their own stored hotel
     * access / Group Owner bypass — never from a request parameter.
     *
     * @param  array{search?: string|null, is_active?: bool|null, sort?: string|null}  $filters
     */
    public function listAccessibleBy(User $user, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->hotels->paginateAccessibleBy($user, $filters, $this->clampPerPage($perPage));
    }

    public function find(int $id): ?Hotel
    {
        return $this->hotels->find($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor): Hotel
    {
        return DB::transaction(function () use ($data, $actor) {
            $data = $this->normalizeLocation($data);
            $data = $this->syncLegacyName($data);
            $facilityIds = $data['facility_ids'] ?? null;
            [$highlights, $nearbyPlaces] = [$data['highlights'] ?? null, $data['nearby_places'] ?? null];
            unset($data['facility_ids'], $data['highlights'], $data['nearby_places']);

            $hotel = $this->hotels->create($data);

            if ($facilityIds !== null) {
                $hotel->facilities()->sync($facilityIds);
            }
            $this->syncGuestDetailLists($hotel, $highlights, $nearbyPlaces);

            // A Group Owner already passes every hotel-scope check by
            // bypass (HotelAccessService::canAccessHotel); anyone else who
            // holds `hotels.manage` needs an explicit access row or they
            // would be locked out — by view(), update() and manageMedia()
            // alike — of the hotel they just created.
            if ($actor !== null && ! $actor->isGroupOwner()) {
                $actor->hotels()->syncWithoutDetaching([$hotel->id]);
            }

            $this->auditLogger->record($actor, 'hotel.created', $hotel, after: $hotel->toArray(), hotelId: $hotel->id);

            return $hotel->load('countryRef', 'cityRef', 'hotelGroup', 'logo', 'cover', 'galleryMedia', 'facilities', 'highlights', 'nearbyPlaces');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Hotel $hotel, array $data, ?User $actor): Hotel
    {
        return DB::transaction(function () use ($hotel, $data, $actor) {
            $before = $hotel->toArray();

            $data = $this->normalizeLocation($data, $hotel);
            $data = $this->syncLegacyName($data);
            $facilityIds = $data['facility_ids'] ?? null;
            [$highlights, $nearbyPlaces] = [$data['highlights'] ?? null, $data['nearby_places'] ?? null];
            unset($data['facility_ids'], $data['highlights'], $data['nearby_places']);

            $this->hotels->update($hotel, $data);

            if ($facilityIds !== null) {
                $hotel->facilities()->sync($facilityIds);
            }
            $this->syncGuestDetailLists($hotel, $highlights, $nearbyPlaces);

            $this->auditLogger->record($actor, 'hotel.updated', $hotel, before: $before, after: $hotel->toArray(), hotelId: $hotel->id);

            return $hotel->load('countryRef', 'cityRef', 'hotelGroup', 'logo', 'cover', 'galleryMedia', 'facilities', 'highlights', 'nearbyPlaces');
        });
    }

    public function delete(Hotel $hotel, ?User $actor): void
    {
        DB::transaction(function () use ($hotel, $actor): void {
            $reservationCount = $hotel->reservations()->count();
            if ($reservationCount > 0) {
                throw HotelDeletionBlockedException::hasReservations($reservationCount);
            }

            // Preserve operational history; hotel-owned setup data cascades.
            foreach ([
                'service_reviews', 'problem_reports', 'notification_events',
                'invoices', 'access_grants', 'service_orders', 'review_categories',
                'folio_charges', 'hotel_services', 'reviews', 'payments',
                'identity_verification_sessions', 'checkouts',
            ] as $table) {
                if (DB::table($table)->where('hotel_id', $hotel->id)->exists()) {
                    throw HotelDeletionBlockedException::hasOperationalHistory();
                }
            }

            $before = $hotel->toArray();
            $this->auditLogger->record($actor, 'hotel.deleted', $hotel, before: $before, hotelId: $hotel->id);
            $this->hotels->delete($hotel);
        });
    }

    /**
     * Replace the hotel's "why choose" highlights and/or nearby places with
     * exactly the submitted lists (list order = display order). A list that
     * was not submitted (null) is left untouched. Entries with no label in
     * any locale are dropped rather than stored empty.
     *
     * @param  list<array<string, mixed>>|null  $highlights
     * @param  list<array<string, mixed>>|null  $nearbyPlaces
     */
    private function syncGuestDetailLists(Hotel $hotel, ?array $highlights, ?array $nearbyPlaces): void
    {
        if ($highlights !== null) {
            $hotel->highlights()->delete();
            $order = 0;
            foreach ($highlights as $item) {
                $title = $this->cleanI18n($item['title_i18n'] ?? null);
                if ($title === null) {
                    continue;
                }
                $hotel->highlights()->create([
                    'icon' => $item['icon'] ?? null,
                    'title_i18n' => $title,
                    'subtitle_i18n' => $this->cleanI18n($item['subtitle_i18n'] ?? null),
                    'is_active' => (bool) ($item['is_active'] ?? true),
                    'sort_order' => $order++,
                ]);
            }
        }

        if ($nearbyPlaces !== null) {
            $hotel->nearbyPlaces()->delete();
            $order = 0;
            foreach ($nearbyPlaces as $item) {
                $name = $this->cleanI18n($item['name_i18n'] ?? null);
                if ($name === null) {
                    continue;
                }
                $hotel->nearbyPlaces()->create([
                    'icon' => $item['icon'] ?? null,
                    'category' => $item['category'] ?? null,
                    'name_i18n' => $name,
                    'travel_minutes' => $item['travel_minutes'] ?? null,
                    'distance' => $item['distance'] ?? null,
                    'distance_unit' => isset($item['distance']) ? ($item['distance_unit'] ?? null) : null,
                    'latitude' => $item['latitude'] ?? null,
                    'longitude' => $item['longitude'] ?? null,
                    'is_active' => (bool) ($item['is_active'] ?? true),
                    'sort_order' => $order++,
                ]);
            }
        }
    }

    /**
     * Drop blank locale entries; null when nothing is left.
     *
     * @return array<string, string>|null
     */
    private function cleanI18n(mixed $map): ?array
    {
        if (! is_array($map)) {
            return null;
        }
        $clean = array_filter(
            array_map(fn ($v) => is_string($v) ? trim($v) : null, $map),
            fn ($v) => $v !== null && $v !== '',
        );

        return $clean === [] ? null : $clean;
    }

    /**
     * Server-side relationship integrity: the selected City must belong to
     * the selected Country (never trust the frontend). The legacy
     * free-text `country` / `city` columns are then kept in sync from the
     * referenced records so the guest Discovery API keeps working.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeLocation(array $data, ?Hotel $hotel = null): array
    {
        $countryId = $data['country_id'] ?? $hotel?->country_id;
        $cityId = $data['city_id'] ?? $hotel?->city_id;

        if ($countryId && $cityId) {
            $this->cities->assertCityBelongsToCountry((int) $cityId, (int) $countryId);
        }

        if ($cityId) {
            $city = City::query()->with('country')->find($cityId);
            if ($city) {
                $data['city'] = $city->name_en;
                $data['country'] = $city->country?->name_en;
            }
        }

        return $data;
    }

    /**
     * Keep the legacy `name` string column in sync with `name_i18n`: it is
     * what Discovery search and the unique slug derivation still use. When
     * `name_i18n` is provided and `name` is not, derive `name` from the
     * fallback-locale entry (never a fabricated translation).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function syncLegacyName(array $data): array
    {
        if (array_key_exists('name_i18n', $data) && ! array_key_exists('name', $data)) {
            $primary = LocalizedContent::primary($data['name_i18n']);

            if ($primary !== null) {
                $data['name'] = $primary;
            }
        }

        return $data;
    }

    private function clampPerPage(int $perPage): int
    {
        return min(max($perPage, 1), 100);
    }
}

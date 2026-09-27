<?php

namespace Tests\Feature\Hotel;

use App\Domain\HotelGroup\Models\Facility;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\Room;
use App\Domain\Inventory\Models\RoomType;
use Tests\TestCase;

/**
 * Guest Hotel Detail content (check-in/out, suitable for, location, "why
 * choose" highlights, nearby places, room-type view): edited by staff on
 * the hotel / room-type endpoints, exposed read-only on the guest detail.
 */
class HotelGuestDetailFieldsTest extends TestCase
{
    private function owner(): User
    {
        return User::factory()->groupOwner()->create();
    }

    private function payload(): array
    {
        return [
            'check_in_time' => '15:00',
            'check_out_time' => '12:00',
            'suitable_for_i18n' => ['ar' => 'العائلات / رجال الأعمال', 'en' => 'Families / business'],
            'location_note_i18n' => ['ar' => 'يبعد 10 دقائق عن الكورنيش', 'en' => '10 minutes from the Corniche'],
            'latitude' => 21.5433,
            'longitude' => 39.1728,
            'highlights' => [
                ['icon' => 'waves', 'title_i18n' => ['ar' => 'مسبح خارجي', 'en' => 'Outdoor pool'], 'subtitle_i18n' => ['ar' => 'إطلالة ومرافق متكاملة']],
                ['icon' => 'wifi', 'title_i18n' => ['ar' => 'واي فاي مجاني', 'en' => 'Free Wi-Fi'], 'subtitle_i18n' => null],
            ],
            'nearby_places' => [
                ['icon' => 'plane', 'name_i18n' => ['ar' => 'مطار جدة', 'en' => 'Jeddah Airport'], 'travel_minutes' => 25],
                ['icon' => 'map-pin', 'name_i18n' => ['ar' => 'وسط المدينة', 'en' => 'City centre'], 'travel_minutes' => 15],
            ],
        ];
    }

    public function test_staff_can_set_guest_detail_content_on_a_hotel(): void
    {
        $hotel = Hotel::factory()->create();

        $this->actingAs($this->owner(), 'sanctum')
            ->putJson("/api/v1/hotels/{$hotel->id}", $this->payload())
            ->assertOk()
            ->assertJsonPath('data.check_in_time', '15:00')
            ->assertJsonPath('data.check_out_time', '12:00')
            ->assertJsonPath('data.suitable_for_i18n.ar', 'العائلات / رجال الأعمال')
            ->assertJsonPath('data.latitude', 21.5433)
            ->assertJsonPath('data.highlights.0.icon', 'waves')
            ->assertJsonPath('data.highlights.1.title_i18n.en', 'Free Wi-Fi')
            ->assertJsonPath('data.nearby_places.0.travel_minutes', 25)
            ->assertJsonCount(2, 'data.nearby_places');

        $this->assertDatabaseCount('hotel_highlights', 2);
        $this->assertDatabaseHas('hotel_nearby_places', ['hotel_id' => $hotel->id, 'sort_order' => 1, 'travel_minutes' => 15]);
    }

    public function test_lists_are_replaced_on_update_and_left_alone_when_omitted(): void
    {
        $hotel = Hotel::factory()->create();
        $owner = $this->owner();
        $this->actingAs($owner, 'sanctum')->putJson("/api/v1/hotels/{$hotel->id}", $this->payload())->assertOk();

        // Omitted lists are untouched.
        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/v1/hotels/{$hotel->id}", ['check_in_time' => '14:00'])
            ->assertOk()
            ->assertJsonCount(2, 'data.highlights');

        // A submitted list replaces the old one, in the submitted order;
        // entries with no label in any locale are dropped.
        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/v1/hotels/{$hotel->id}", [
                'highlights' => [
                    ['icon' => 'dumbbell', 'title_i18n' => ['ar' => 'نادي رياضي']],
                    ['icon' => 'x', 'title_i18n' => ['ar' => '  ', 'en' => '']],
                ],
                'nearby_places' => [],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.highlights')
            ->assertJsonPath('data.highlights.0.icon', 'dumbbell')
            ->assertJsonCount(0, 'data.nearby_places');

        $this->assertDatabaseCount('hotel_highlights', 1);
        $this->assertDatabaseCount('hotel_nearby_places', 0);
    }

    public function test_invalid_guest_detail_values_are_rejected(): void
    {
        $hotel = Hotel::factory()->create();

        $this->actingAs($this->owner(), 'sanctum')
            ->putJson("/api/v1/hotels/{$hotel->id}", [
                'check_in_time' => '25:99',
                'latitude' => 120,
                'nearby_places' => [['name_i18n' => ['ar' => 'x'], 'travel_minutes' => 0]],
                'highlights' => [['icon' => 'wifi']],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'check_in_time', 'latitude', 'longitude',
                'nearby_places.0.travel_minutes', 'highlights.0.title_i18n',
            ]);
    }

    public function test_guest_detail_exposes_the_content_resolved_to_the_request_locale(): void
    {
        $hotel = Hotel::factory()->create();
        $this->actingAs($this->owner(), 'sanctum')->putJson("/api/v1/hotels/{$hotel->id}", $this->payload())->assertOk();
        $type = RoomType::factory()->create([
            'hotel_id' => $hotel->id,
            'is_active' => true,
            'view_i18n' => ['ar' => 'إطلالة المدينة', 'en' => 'City view'],
        ]);
        Room::factory()->count(3)->create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id]);
        $this->app['auth']->forgetGuards();

        $this->getJson("/api/v1/guest/hotels/{$hotel->id}", ['X-Locale' => 'ar'])
            ->assertOk()
            ->assertJsonPath('data.check_in_time', '15:00')
            ->assertJsonPath('data.check_out_time', '12:00')
            ->assertJsonPath('data.suitable_for', 'العائلات / رجال الأعمال')
            ->assertJsonPath('data.rooms_count', 3)
            ->assertJsonPath('data.highlights.0.title', 'مسبح خارجي')
            ->assertJsonPath('data.highlights.0.subtitle', 'إطلالة ومرافق متكاملة')
            ->assertJsonPath('data.highlights.1.subtitle', null)
            ->assertJsonPath('data.location.note', 'يبعد 10 دقائق عن الكورنيش')
            ->assertJsonPath('data.location.latitude', 21.5433)
            ->assertJsonPath('data.location.nearby_places.0.name', 'مطار جدة')
            ->assertJsonPath('data.location.nearby_places.0.travel_minutes', 25)
            ->assertJsonPath('data.room_types.0.view', 'إطلالة المدينة');

        $this->getJson("/api/v1/guest/hotels/{$hotel->id}", ['X-Locale' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.location.nearby_places.1.name', 'City centre')
            ->assertJsonPath('data.room_types.0.view', 'City view');
    }

    public function test_guest_detail_returns_nulls_and_empty_lists_when_nothing_is_on_file(): void
    {
        $hotel = Hotel::factory()->create();

        $this->getJson("/api/v1/guest/hotels/{$hotel->id}")
            ->assertOk()
            ->assertJsonPath('data.check_in_time', null)
            ->assertJsonPath('data.suitable_for', null)
            ->assertJsonPath('data.rooms_count', 0)
            ->assertJsonPath('data.highlights', [])
            ->assertJsonPath('data.location.note', null)
            ->assertJsonPath('data.location.latitude', null)
            ->assertJsonPath('data.location.nearby_places', []);
    }

    public function test_nearby_places_carry_category_distance_coordinates_and_active_flag(): void
    {
        $hotel = Hotel::factory()->create();

        $this->actingAs($this->owner(), 'sanctum')
            ->putJson("/api/v1/hotels/{$hotel->id}", [
                'highlights' => [
                    ['icon' => 'wifi', 'title_i18n' => ['en' => 'Free Wi-Fi']],
                    ['icon' => 'waves', 'title_i18n' => ['en' => 'Hidden pool'], 'is_active' => false],
                ],
                'nearby_places' => [
                    [
                        'icon' => 'plane', 'category' => 'airport',
                        'name_i18n' => ['ar' => 'مطار جدة', 'en' => 'Jeddah Airport'],
                        'distance' => 18.5, 'distance_unit' => 'km',
                        'latitude' => 21.6796, 'longitude' => 39.1565,
                    ],
                    ['category' => 'beach', 'name_i18n' => ['en' => 'Closed beach'], 'is_active' => false],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.nearby_places.0.category', 'airport')
            ->assertJsonPath('data.nearby_places.0.distance', 18.5)
            ->assertJsonPath('data.nearby_places.0.distance_unit', 'km')
            ->assertJsonPath('data.nearby_places.0.is_active', true)
            ->assertJsonPath('data.nearby_places.1.is_active', false)
            ->assertJsonPath('data.highlights.1.is_active', false);

        $this->app['auth']->forgetGuards();

        // Inactive rows stay in the dashboard but never reach guests.
        $this->getJson("/api/v1/guest/hotels/{$hotel->id}", ['X-Locale' => 'en'])
            ->assertOk()
            ->assertJsonCount(1, 'data.highlights')
            ->assertJsonCount(1, 'data.location.nearby_places')
            ->assertJsonPath('data.location.nearby_places.0.category', 'airport')
            ->assertJsonPath('data.location.nearby_places.0.distance', 18.5)
            ->assertJsonPath('data.location.nearby_places.0.distance_unit', 'km')
            ->assertJsonPath('data.location.nearby_places.0.latitude', 21.6796)
            ->assertJsonPath('data.location.nearby_places.0.travel_minutes', null);
    }

    public function test_invalid_nearby_place_fields_are_rejected(): void
    {
        $hotel = Hotel::factory()->create();

        $this->actingAs($this->owner(), 'sanctum')
            ->putJson("/api/v1/hotels/{$hotel->id}", [
                'nearby_places' => [
                    ['name_i18n' => ['en' => 'a'], 'category' => 'volcano', 'distance' => 3],
                    ['name_i18n' => ['en' => 'b'], 'distance' => 3, 'distance_unit' => 'mi', 'latitude' => 10],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'nearby_places.0.category', 'nearby_places.0.distance_unit',
                'nearby_places.1.distance_unit', 'nearby_places.1.longitude',
            ]);
    }

    public function test_guest_amenities_include_description_and_hide_inactive_facilities(): void
    {
        $hotel = Hotel::factory()->create();
        $pool = Facility::factory()->create([
            'key' => 'test_pool', 'icon' => 'pool', 'sort_order' => 0,
            'name_i18n' => ['ar' => 'مسبح', 'en' => 'Pool'],
            'description_i18n' => ['ar' => 'مسبح خارجي مدفأ', 'en' => 'Heated outdoor pool'],
        ]);
        $spa = Facility::factory()->create(['key' => 'test_spa', 'is_active' => false, 'sort_order' => 1, 'name_i18n' => ['en' => 'Spa']]);
        $hotel->facilities()->sync([$pool->id, $spa->id]);

        $this->getJson("/api/v1/guest/hotels/{$hotel->id}", ['X-Locale' => 'ar'])
            ->assertOk()
            ->assertJsonCount(1, 'data.amenities')
            ->assertJsonPath('data.amenities.0.key', 'test_pool')
            ->assertJsonPath('data.amenities.0.label', 'مسبح')
            ->assertJsonPath('data.amenities.0.description', 'مسبح خارجي مدفأ')
            ->assertJsonPath('data.amenities.0.icon', 'pool');
    }

    public function test_staff_can_set_a_facility_description(): void
    {
        $this->actingAs($this->owner(), 'sanctum')
            ->postJson('/api/v1/facilities', [
                'name_i18n' => ['en' => 'Valet parking', 'ar' => 'صف السيارات'],
                'description_i18n' => ['en' => 'Available 24/7', 'ar' => 'متاح على مدار الساعة'],
                'icon' => 'car',
            ])
            ->assertCreated()
            ->assertJsonPath('data.description_i18n.en', 'Available 24/7')
            ->assertJsonPath('data.icon', 'car');
    }

    public function test_staff_can_edit_room_type_guest_specs(): void
    {
        $hotel = Hotel::factory()->create();
        $type = RoomType::factory()->create(['hotel_id' => $hotel->id]);

        $this->actingAs($this->owner(), 'sanctum')
            ->putJson("/api/v1/hotels/{$hotel->id}/room-types/{$type->id}", [
                'view_i18n' => ['ar' => 'إطلالة البحر', 'en' => 'Sea view'],
                'bed_type_i18n' => ['ar' => 'سرير كينج', 'en' => 'King bed'],
                'area_sqm' => 40,
                'breakfast_included' => true,
                'refundable' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.view_i18n.en', 'Sea view')
            ->assertJsonPath('data.bed_type_i18n.ar', 'سرير كينج')
            ->assertJsonPath('data.area_sqm', 40)
            ->assertJsonPath('data.breakfast_included', true)
            ->assertJsonPath('data.refundable', false);
    }

    public function test_reception_phone_is_saved_validated_and_shown_to_guests(): void
    {
        $hotel = Hotel::factory()->create(['is_active' => true]);
        $owner = $this->owner();

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/hotels/{$hotel->id}", ['reception_phone' => '+966 12 345 6789', 'deposit_percentage' => 20])
            ->assertOk()
            ->assertJsonPath('data.reception_phone', '+966 12 345 6789');

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/hotels/{$hotel->id}", ['reception_phone' => 'call us', 'deposit_percentage' => 20])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reception_phone');

        $this->getJson("/api/v1/guest/hotels/{$hotel->id}")
            ->assertOk()
            ->assertJsonPath('data.reception_phone', '+966 12 345 6789');
    }
}

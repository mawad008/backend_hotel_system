<?php

namespace Tests\Feature\Hotel;

use App\Domain\HotelGroup\Models\Facility;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\Room;
use App\Domain\Inventory\Models\RoomType;
use Tests\TestCase;

/**
 * Room Detail content (Figma v2 `ROOM_Detail_Premium`): the room-type badge,
 * the "rate includes" list, catalog-resolved room facilities and the
 * hotel's "prices include taxes / service fee" flags — edited by staff,
 * shown to guests on the hotel detail and the availability rows.
 */
class RoomDetailContentTest extends TestCase
{
    private function owner(): User
    {
        return User::factory()->groupOwner()->create();
    }

    public function test_staff_edit_the_room_badge_inclusions_and_facilities(): void
    {
        $hotel = Hotel::factory()->create();
        $type = RoomType::factory()->create(['hotel_id' => $hotel->id]);

        $this->actingAs($this->owner(), 'sanctum')
            ->patchJson("/api/v1/hotels/{$hotel->id}/room-types/{$type->id}", [
                'tag_i18n' => ['ar' => 'غرفة مميزة', 'en' => 'Featured room'],
                'inclusions_i18n' => [
                    ['ar' => 'دخول المسبح', 'en' => 'Pool access'],
                    ['ar' => 'خدمة تنظيف يومية', 'en' => 'Daily housekeeping'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.tag_i18n.ar', 'غرفة مميزة')
            ->assertJsonPath('data.inclusions_i18n.1.en', 'Daily housekeeping');
    }

    public function test_invalid_room_detail_content_is_rejected(): void
    {
        $hotel = Hotel::factory()->create();
        $type = RoomType::factory()->create(['hotel_id' => $hotel->id]);

        $this->actingAs($this->owner(), 'sanctum')
            ->patchJson("/api/v1/hotels/{$hotel->id}/room-types/{$type->id}", [
                'tag_i18n' => ['ar' => str_repeat('x', 41)],
                'inclusions_i18n' => array_fill(0, 16, ['en' => 'x']),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tag_i18n.ar', 'inclusions_i18n']);
    }

    public function test_guests_get_the_content_in_their_locale_with_catalog_facilities(): void
    {
        $hotel = Hotel::factory()->create(['prices_include_taxes' => true, 'service_fee_enabled' => true, 'service_fee_type' => 'fixed', 'service_fee_value' => '45.00']);
        Facility::factory()->create(['key' => 'rt_tv', 'icon' => 'tv', 'name_i18n' => ['ar' => 'تلفزيون ذكي', 'en' => 'Smart TV']]);
        Facility::factory()->create(['key' => 'rt_safe', 'icon' => null, 'name_i18n' => ['ar' => 'خزنة', 'en' => 'Safe']]);
        Facility::factory()->create(['key' => 'rt_old', 'is_active' => false, 'name_i18n' => ['en' => 'Retired']]);
        RoomType::factory()->create([
            'hotel_id' => $hotel->id,
            'amenities' => ['rt_tv', 'rt_safe', 'rt_old', 'unknown_key'],
            'tag_i18n' => ['ar' => 'غرفة مميزة', 'en' => 'Featured room'],
            'inclusions_i18n' => [['ar' => 'دخول المسبح', 'en' => 'Pool access'], ['ar' => '  ', 'en' => '']],
        ]);

        $this->getJson("/api/v1/guest/hotels/{$hotel->id}", ['X-Locale' => 'ar'])
            ->assertOk()
            ->assertJsonPath('data.prices_include_taxes', true)
            ->assertJsonPath('data.service_fee', ['type' => 'fixed', 'value' => '45.00'])
            ->assertJsonPath('data.room_types.0.tag', 'غرفة مميزة')
            ->assertJsonPath('data.room_types.0.inclusions', ['دخول المسبح'])
            ->assertJsonCount(2, 'data.room_types.0.facilities')
            ->assertJsonPath('data.room_types.0.facilities.0.label', 'تلفزيون ذكي')
            ->assertJsonPath('data.room_types.0.facilities.0.icon', 'tv')
            ->assertJsonPath('data.room_types.0.facilities.1.key', 'rt_safe');
    }

    public function test_availability_rows_carry_the_same_content(): void
    {
        $hotel = Hotel::factory()->create();
        Facility::factory()->create(['key' => 'rt_coffee', 'icon' => 'coffee', 'name_i18n' => ['en' => 'Coffee machine']]);
        $type = RoomType::factory()->create([
            'hotel_id' => $hotel->id,
            'capacity' => 3,
            'amenities' => ['rt_coffee'],
            'tag_i18n' => ['en' => 'Featured room'],
            'inclusions_i18n' => [['en' => 'Pool access']],
        ]);
        Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id]);

        $this->getJson("/api/v1/guest/hotels/{$hotel->id}/availability?".http_build_query([
            'check_in' => now()->addDays(5)->toDateString(),
            'check_out' => now()->addDays(7)->toDateString(),
            'adults' => 2,
        ]), ['X-Locale' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.rooms.0.tag', 'Featured room')
            ->assertJsonPath('data.rooms.0.inclusions.0', 'Pool access')
            ->assertJsonPath('data.rooms.0.facilities.0.label', 'Coffee machine');
    }

    public function test_hotel_pricing_settings_default_off_and_are_editable(): void
    {
        $hotel = Hotel::factory()->create();
        $this->assertFalse($hotel->fresh()->prices_include_taxes);
        $this->assertFalse($hotel->fresh()->service_fee_enabled);
        $this->getJson("/api/v1/guest/hotels/{$hotel->id}")->assertJsonPath('data.service_fee', null);

        $this->actingAs($this->owner(), 'sanctum')
            ->patchJson("/api/v1/hotels/{$hotel->id}", [
                'prices_include_taxes' => true,
                'service_fee_enabled' => true,
                'service_fee_type' => 'percentage',
                'service_fee_value' => 5,
            ])
            ->assertOk()
            ->assertJsonPath('data.prices_include_taxes', true)
            ->assertJsonPath('data.service_fee_enabled', true)
            ->assertJsonPath('data.service_fee_type', 'percentage')
            ->assertJsonPath('data.service_fee_value', '5.00');

        // Switching it off from the dashboard hides it from guests.
        $this->actingAs($this->owner(), 'sanctum')
            ->patchJson("/api/v1/hotels/{$hotel->id}", ['service_fee_enabled' => false])
            ->assertOk();
        $this->getJson("/api/v1/guest/hotels/{$hotel->id}")->assertJsonPath('data.service_fee', null);
    }

    public function test_an_enabled_service_fee_needs_a_valid_type_and_value(): void
    {
        $hotel = Hotel::factory()->create();

        $this->actingAs($this->owner(), 'sanctum')
            ->patchJson("/api/v1/hotels/{$hotel->id}", ['service_fee_enabled' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['service_fee_type', 'service_fee_value']);

        $this->actingAs($this->owner(), 'sanctum')
            ->patchJson("/api/v1/hotels/{$hotel->id}", ['service_fee_enabled' => true, 'service_fee_type' => 'percentage', 'service_fee_value' => 150])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['service_fee_value']);
    }
}

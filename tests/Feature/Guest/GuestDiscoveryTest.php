<?php

namespace Tests\Feature\Guest;

use App\Domain\HotelGroup\Enums\HotelAmenity;
use App\Domain\HotelGroup\Models\Facility;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Inventory\Models\Room;
use App\Domain\Inventory\Models\RoomMedia;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Review\Models\Review;
use Tests\TestCase;

class GuestDiscoveryTest extends TestCase
{
    public function test_hotel_list_is_public_and_active_only(): void
    {
        $active = Hotel::factory()->create(['name' => 'Oasis', 'city' => 'Riyadh']);
        Hotel::factory()->create(['is_active' => false, 'name' => 'Closed Inn']);

        $res = $this->getJson('/api/v1/guest/hotels');

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id)
            ->assertJsonPath('data.0.name', 'Oasis')
            ->assertJsonMissingPath('data.0.is_active');
    }

    public function test_hotel_list_filters_by_city_and_query(): void
    {
        Hotel::factory()->create(['name' => 'Red Sea Resort', 'city' => 'Jeddah']);
        Hotel::factory()->create(['name' => 'Mountain Lodge', 'city' => 'Abha']);

        $this->getJson('/api/v1/guest/hotels?city=Jeddah')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Red Sea Resort');

        $this->getJson('/api/v1/guest/hotels?q=Mountain')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Mountain Lodge');
    }

    public function test_cities_endpoint_counts_active_hotels(): void
    {
        Hotel::factory()->count(2)->create(['city' => 'Riyadh']);
        Hotel::factory()->create(['city' => 'Dammam']);
        Hotel::factory()->create(['city' => 'Riyadh', 'is_active' => false]);

        $this->getJson('/api/v1/guest/hotels/cities')
            ->assertOk()
            ->assertJsonFragment(['city' => 'Riyadh', 'hotel_count' => 2])
            ->assertJsonFragment(['city' => 'Dammam', 'hotel_count' => 1]);
    }

    public function test_hotel_detail_returns_active_room_types_only(): void
    {
        $hotel = Hotel::factory()->create();
        $rt = RoomType::factory()->create(['hotel_id' => $hotel->id, 'name' => 'Deluxe', 'base_price' => 400]);
        RoomType::factory()->inactive()->create(['hotel_id' => $hotel->id, 'name' => 'Old Wing']);

        $this->getJson("/api/v1/guest/hotels/{$hotel->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.room_types')
            ->assertJsonPath('data.room_types.0.id', $rt->id)
            ->assertJsonPath('data.room_types.0.name', 'Deluxe');
    }

    public function test_hotel_detail_exposes_room_type_gallery_in_order(): void
    {
        $hotel = Hotel::factory()->create();
        $rt = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $second = RoomMedia::factory()->forRoomType($rt)->create(['sort_order' => 1]);
        $first = RoomMedia::factory()->forRoomType($rt)->create(['sort_order' => 0]);

        $res = $this->getJson("/api/v1/guest/hotels/{$hotel->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.room_types.0.gallery');

        $this->assertStringContainsString(
            $first->path,
            $res->json('data.room_types.0.gallery.0.url'),
        );
        $this->assertStringContainsString(
            $second->path,
            $res->json('data.room_types.0.gallery.1.url'),
        );
    }

    public function test_hotel_detail_exposes_room_type_specs(): void
    {
        $hotel = Hotel::factory()->create();
        RoomType::factory()->create([
            'hotel_id' => $hotel->id,
            'bed_type_i18n' => ['en' => 'Double bed', 'ar' => 'سرير مزدوج'],
            'area_sqm' => 28,
            'breakfast_included' => true,
            'refundable' => true,
        ]);

        $this->getJson("/api/v1/guest/hotels/{$hotel->id}")
            ->assertOk()
            ->assertJsonPath('data.room_types.0.bed_type', 'Double bed')
            ->assertJsonPath('data.room_types.0.area_sqm', 28)
            ->assertJsonPath('data.room_types.0.breakfast_included', true)
            ->assertJsonPath('data.room_types.0.refundable', true);

        $this->withHeader('X-Locale', 'ar')
            ->getJson("/api/v1/guest/hotels/{$hotel->id}")
            ->assertOk()
            ->assertJsonPath('data.room_types.0.bed_type', 'سرير مزدوج');
    }

    public function test_hotel_detail_room_type_specs_are_null_when_unset(): void
    {
        $hotel = Hotel::factory()->create();
        RoomType::factory()->create(['hotel_id' => $hotel->id, 'bed_type_i18n' => null, 'area_sqm' => null]);

        $this->getJson("/api/v1/guest/hotels/{$hotel->id}")
            ->assertOk()
            ->assertJsonPath('data.room_types.0.bed_type', null)
            ->assertJsonPath('data.room_types.0.area_sqm', null);
    }

    public function test_availability_exposes_room_type_specs(): void
    {
        $hotel = Hotel::factory()->create();
        $rt = RoomType::factory()->create([
            'hotel_id' => $hotel->id,
            'capacity' => 2,
            'bed_type_i18n' => ['en' => 'Double bed', 'ar' => 'سرير مزدوج'],
            'area_sqm' => 28,
            'breakfast_included' => true,
            'refundable' => false,
        ]);
        Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $rt->id]);

        $this->getJson("/api/v1/guest/hotels/{$hotel->id}/availability?check_in=".now()->addDay()->toDateString().'&check_out='.now()->addDays(2)->toDateString())
            ->assertOk()
            ->assertJsonPath('data.rooms.0.bed_type', 'Double bed')
            ->assertJsonPath('data.rooms.0.area_sqm', 28)
            ->assertJsonPath('data.rooms.0.breakfast_included', true)
            ->assertJsonPath('data.rooms.0.refundable', false);
    }

    public function test_availability_exposes_room_type_gallery(): void
    {
        $hotel = Hotel::factory()->create();
        $rt = RoomType::factory()->create(['hotel_id' => $hotel->id, 'capacity' => 2]);
        Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $rt->id]);
        $media = RoomMedia::factory()->forRoomType($rt)->create();

        $res = $this->getJson("/api/v1/guest/hotels/{$hotel->id}/availability?check_in=".now()->addDay()->toDateString().'&check_out='.now()->addDays(2)->toDateString())
            ->assertOk()
            ->assertJsonCount(1, 'data.rooms.0.gallery');

        $this->assertStringContainsString($media->path, $res->json('data.rooms.0.gallery.0.url'));
    }

    public function test_inactive_or_missing_hotel_is_404(): void
    {
        $inactive = Hotel::factory()->create(['is_active' => false]);

        $this->getJson("/api/v1/guest/hotels/{$inactive->id}")->assertStatus(404);
        $this->getJson('/api/v1/guest/hotels/999999')->assertStatus(404);
        $this->getJson("/api/v1/guest/hotels/{$inactive->id}/availability?check_in=".now()->addDay()->toDateString().'&check_out='.now()->addDays(3)->toDateString())
            ->assertStatus(404);
    }

    public function test_availability_reflects_overlapping_reservations(): void
    {
        $hotel = Hotel::factory()->create();
        $rt = RoomType::factory()->create(['hotel_id' => $hotel->id, 'base_price' => 200, 'capacity' => 2]);
        Room::factory()->count(2)->create(['hotel_id' => $hotel->id, 'room_type_id' => $rt->id]);

        $checkIn = now()->addDays(5)->toDateString();
        $checkOut = now()->addDays(8)->toDateString();

        // One overlapping blocking reservation -> 1 of 2 rooms left.
        Reservation::factory()->create([
            'hotel_id' => $hotel->id,
            'room_type_id' => $rt->id,
            'check_in' => now()->addDays(6)->toDateString(),
            'check_out' => now()->addDays(7)->toDateString(),
            'status' => Reservation::STATUS_DEPOSIT_HELD,
        ]);

        $res = $this->getJson("/api/v1/guest/hotels/{$hotel->id}/availability?check_in={$checkIn}&check_out={$checkOut}&adults=2");

        $res->assertOk()
            ->assertJsonPath('data.nights', 3)
            ->assertJsonPath('data.rooms.0.rooms_total', 2)
            ->assertJsonPath('data.rooms.0.rooms_available', 1)
            ->assertJsonPath('data.rooms.0.is_available', true)
            ->assertJsonPath('data.rooms.0.estimated_total', '600.00');
    }

    public function test_availability_greys_out_a_room_type_that_cannot_seat_the_party(): void
    {
        $hotel = Hotel::factory()->create();
        $rt = RoomType::factory()->create(['hotel_id' => $hotel->id, 'capacity' => 2]);
        Room::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $rt->id]);

        $res = $this->getJson("/api/v1/guest/hotels/{$hotel->id}/availability?check_in=".now()->addDay()->toDateString().'&check_out='.now()->addDays(2)->toDateString().'&adults=4');

        $res->assertOk()
            ->assertJsonPath('data.rooms.0.rooms_available', 0)
            ->assertJsonPath('data.rooms.0.is_available', false);
    }

    public function test_availability_validates_the_date_range(): void
    {
        $hotel = Hotel::factory()->create();

        $this->getJson("/api/v1/guest/hotels/{$hotel->id}/availability?check_in=2000-01-01&check_out=2000-01-05")
            ->assertStatus(422)->assertJsonValidationErrors('check_in');

        $this->getJson("/api/v1/guest/hotels/{$hotel->id}/availability?check_in=".now()->addDays(3)->toDateString().'&check_out='.now()->addDay()->toDateString())
            ->assertStatus(422)->assertJsonValidationErrors('check_out');
    }

    public function test_discovery_needs_no_authentication(): void
    {
        Hotel::factory()->create();

        $this->getJson('/api/v1/guest/hotels')->assertOk();
    }

    public function test_default_sort_is_recommended_by_booking_count(): void
    {
        $quiet = Hotel::factory()->create(['name' => 'Quiet Inn', 'city' => 'Sort City']);
        $popular = Hotel::factory()->create(['name' => 'Popular Stay', 'city' => 'Sort City']);
        $mid = Hotel::factory()->create(['name' => 'Mid Stay', 'city' => 'Sort City']);

        Reservation::factory()->count(5)->create(['hotel_id' => $popular->id, 'status' => Reservation::STATUS_CHECKED_OUT]);
        Reservation::factory()->count(2)->create(['hotel_id' => $mid->id, 'status' => Reservation::STATUS_PENDING]);
        // A cancelled reservation is not a "booking" — must not count.
        Reservation::factory()->count(9)->create(['hotel_id' => $quiet->id, 'status' => Reservation::STATUS_CANCELLED]);

        $res = $this->getJson('/api/v1/guest/hotels?city=Sort+City')->assertOk();
        $names = array_column($res->json('data'), 'name');

        $this->assertSame(['Popular Stay', 'Mid Stay', 'Quiet Inn'], $names);

        // Same ordering when `sort` is omitted vs explicit `recommended`.
        $this->getJson('/api/v1/guest/hotels?city=Sort+City&sort=recommended')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Popular Stay');
    }

    public function test_sort_highest_rated_orders_by_average_published_rating(): void
    {
        $low = Hotel::factory()->create(['name' => 'Low Rated', 'city' => 'Rated City']);
        $high = Hotel::factory()->create(['name' => 'High Rated', 'city' => 'Rated City']);
        $none = Hotel::factory()->create(['name' => 'Unrated', 'city' => 'Rated City']);

        Review::factory()->for($low)->create(['rating' => 2, 'status' => Review::STATUS_PUBLISHED]);
        Review::factory()->for($high)->create(['rating' => 5, 'status' => Review::STATUS_PUBLISHED]);

        $res = $this->getJson('/api/v1/guest/hotels?city=Rated+City&sort=highest_rated')->assertOk();
        $names = array_column($res->json('data'), 'name');

        // Unrated hotels sort after rated ones regardless of rating value.
        $this->assertSame(['High Rated', 'Low Rated', 'Unrated'], $names);
    }

    public function test_sort_cheapest_orders_by_lowest_price_from(): void
    {
        $expensive = Hotel::factory()->create(['name' => 'Expensive', 'city' => 'Price City']);
        $cheap = Hotel::factory()->create(['name' => 'Cheap', 'city' => 'Price City']);
        $noRooms = Hotel::factory()->create(['name' => 'No Rooms', 'city' => 'Price City']);

        RoomType::factory()->create(['hotel_id' => $expensive->id, 'base_price' => 900]);
        RoomType::factory()->create(['hotel_id' => $cheap->id, 'base_price' => 200]);

        $res = $this->getJson('/api/v1/guest/hotels?city=Price+City&sort=cheapest')->assertOk();
        $names = array_column($res->json('data'), 'name');

        // A hotel with no active room types (no price_from) sorts last.
        $this->assertSame(['Cheap', 'Expensive', 'No Rooms'], $names);
    }

    public function test_price_range_filter_uses_the_price_from_aggregate(): void
    {
        $hotelA = Hotel::factory()->create(['city' => 'Range City']);
        $hotelB = Hotel::factory()->create(['city' => 'Range City']);
        RoomType::factory()->create(['hotel_id' => $hotelA->id, 'base_price' => 300]);
        RoomType::factory()->create(['hotel_id' => $hotelB->id, 'base_price' => 800]);

        $this->getJson('/api/v1/guest/hotels?city=Range+City&min_price=500')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $hotelB->id);

        $this->getJson('/api/v1/guest/hotels?city=Range+City&max_price=500')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $hotelA->id);
    }

    public function test_facilities_filter_requires_all_requested_facilities(): void
    {
        $wifi = Facility::query()->where('key', HotelAmenity::FreeWifi->value)->firstOrFail();
        $pool = Facility::query()->where('key', HotelAmenity::Pool->value)->firstOrFail();

        $both = Hotel::factory()->create(['city' => 'Facility City']);
        $both->facilities()->sync([$wifi->id, $pool->id]);

        $wifiOnly = Hotel::factory()->create(['city' => 'Facility City']);
        $wifiOnly->facilities()->sync([$wifi->id]);

        $this->getJson('/api/v1/guest/hotels?city=Facility+City&facilities='.HotelAmenity::FreeWifi->value.','.HotelAmenity::Pool->value)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $both->id);
    }

    public function test_combined_filter_and_sort_and_pagination_compose_correctly(): void
    {
        $wifi = Facility::query()->where('key', HotelAmenity::FreeWifi->value)->firstOrFail();

        $matchHigh = Hotel::factory()->create(['name' => 'Match High', 'city' => 'Combo City']);
        $matchLow = Hotel::factory()->create(['name' => 'Match Low', 'city' => 'Combo City']);
        $noFacility = Hotel::factory()->create(['name' => 'No Facility', 'city' => 'Combo City']);

        $matchHigh->facilities()->sync([$wifi->id]);
        $matchLow->facilities()->sync([$wifi->id]);
        RoomType::factory()->create(['hotel_id' => $matchHigh->id, 'base_price' => 500]);
        RoomType::factory()->create(['hotel_id' => $matchLow->id, 'base_price' => 500]);
        RoomType::factory()->create(['hotel_id' => $noFacility->id, 'base_price' => 500]);

        Review::factory()->for($matchHigh)->create(['rating' => 5, 'status' => Review::STATUS_PUBLISHED]);
        Review::factory()->for($matchLow)->create(['rating' => 2, 'status' => Review::STATUS_PUBLISHED]);
        Review::factory()->for($noFacility)->create(['rating' => 5, 'status' => Review::STATUS_PUBLISHED]);

        $res = $this->getJson(
            '/api/v1/guest/hotels?city=Combo+City&facilities='.HotelAmenity::FreeWifi->value.'&sort=highest_rated&per_page=1'
        )->assertOk();

        // Facility filter excludes "No Facility" even though it rates highest;
        // sort picks the higher-rated of the two matches; pagination caps at 1.
        $res->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Match High');
        $this->assertSame(2, $res->json('meta.total'));
        $this->assertSame(2, $res->json('meta.last_page'));
    }

    public function test_guest_hotel_list_and_detail_never_leak_reservation_or_guest_identifiers(): void
    {
        $hotel = Hotel::factory()->create(['city' => 'Privacy City']);
        Reservation::factory()->create(['hotel_id' => $hotel->id, 'status' => Reservation::STATUS_CHECKED_OUT]);

        $forbiddenKeys = ['reservation_id', 'reservations', 'guest_id', 'bookings_count', 'reviews'];

        foreach ([
            $this->getJson('/api/v1/guest/hotels?city=Privacy+City')->assertOk()->json('data.0'),
            $this->getJson("/api/v1/guest/hotels/{$hotel->id}")->assertOk()->json('data'),
        ] as $hotelPayload) {
            foreach ($forbiddenKeys as $key) {
                $this->assertArrayNotHasKey($key, $hotelPayload, "Guest discovery leaked '{$key}'");
            }
        }
    }
}

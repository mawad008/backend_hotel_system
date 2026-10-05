<?php

namespace Tests\Feature\Reservation;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use Tests\TestCase;

class ReservationListFilterApiTest extends TestCase
{
    private function reservationIn(Hotel $hotel, array $attributes = []): Reservation
    {
        return Reservation::factory()->create(array_merge([
            'room_type_id' => RoomType::factory()->create(['hotel_id' => $hotel->id])->id,
        ], $attributes));
    }

    private function ids(string $query, User $user): array
    {
        return collect($this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reservations'.$query)
            ->assertOk()
            ->json('data'))->pluck('id')->sort()->values()->all();
    }

    public function test_status_filter_applies_across_all_pages(): void
    {
        $hotel = Hotel::factory()->create();
        $cancelled = $this->reservationIn($hotel, ['status' => Reservation::STATUS_CANCELLED]);
        for ($i = 0; $i < 20; $i++) {
            $this->reservationIn($hotel);
        }
        $owner = User::factory()->groupOwner()->create();

        $this->assertSame([$cancelled->id], $this->ids('?status=cancelled', $owner));
    }

    public function test_hotel_filter_narrows_but_never_widens_scope(): void
    {
        $hotelA = Hotel::factory()->create();
        $hotelB = Hotel::factory()->create();
        $a = $this->reservationIn($hotelA);
        $this->reservationIn($hotelB);
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotelA);

        $this->assertSame([$a->id], $this->ids("?hotel_id={$hotelA->id}", $manager));
        $this->assertSame([], $this->ids("?hotel_id={$hotelB->id}", $manager));
    }

    public function test_search_matches_id_and_guest_name_or_phone(): void
    {
        $hotel = Hotel::factory()->create();
        $sara = $this->reservationIn($hotel, ['guest_id' => Guest::factory()->create(['name' => 'Sara Ali', 'phone' => '+201000000111'])->id]);
        $other = $this->reservationIn($hotel, ['guest_id' => Guest::factory()->create(['name' => 'Omar Zaki', 'phone' => '+201000000222'])->id]);
        $owner = User::factory()->groupOwner()->create();

        $this->assertSame([$sara->id], $this->ids('?search=sara', $owner));
        $this->assertSame([$other->id], $this->ids('?search=0222', $owner));
        $this->assertSame([$other->id], $this->ids('?search=%23'.$other->id, $owner));
    }

    public function test_check_in_range_and_per_page(): void
    {
        $hotel = Hotel::factory()->create();
        $inRange = $this->reservationIn($hotel, ['check_in' => '2026-12-10', 'check_out' => '2026-12-12']);
        $this->reservationIn($hotel, ['check_in' => '2026-12-20', 'check_out' => '2026-12-22']);
        $owner = User::factory()->groupOwner()->create();

        $this->assertSame([$inRange->id], $this->ids('?check_in_from=2026-12-01&check_in_to=2026-12-15', $owner));

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/reservations?per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2);
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/reservations?status=bogus&per_page=500')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status', 'per_page']);
    }
}

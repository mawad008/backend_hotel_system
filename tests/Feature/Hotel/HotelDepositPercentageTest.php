<?php

namespace Tests\Feature\Hotel;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Location\Models\City;
use App\Domain\Location\Models\Country;
use Tests\TestCase;

class HotelDepositPercentageTest extends TestCase
{
    private function owner(): User
    {
        return User::factory()->groupOwner()->create();
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        $country = Country::factory()->create();
        $city = City::factory()->create(['country_id' => $country->id]);

        return [
            'hotel_group_id' => HotelGroup::factory()->create()->id,
            'name' => 'Deposit Hotel',
            'slug' => 'deposit-hotel',
            'country_id' => $country->id,
            'city_id' => $city->id,
            ...$overrides,
        ];
    }

    public function test_a_hotel_is_created_with_its_own_deposit_percentage(): void
    {
        $this->actingAs($this->owner(), 'sanctum')
            ->postJson('/api/v1/hotels', $this->payload(['deposit_percentage' => 10]))
            ->assertCreated()
            ->assertJsonPath('data.deposit_percentage', '10.00');

        $this->assertDatabaseHas('hotels', ['slug' => 'deposit-hotel', 'deposit_percentage' => '10.00']);
    }

    public function test_deposit_percentage_is_required_and_bounded_on_create(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/hotels', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('deposit_percentage');

        // Approved range 0–100 (0 = no deposit hold).
        foreach ([100.5, -5, 12.345, 'abc'] as $bad) {
            $this->actingAs($owner, 'sanctum')
                ->postJson('/api/v1/hotels', $this->payload(['deposit_percentage' => $bad]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('deposit_percentage');
        }
    }

    public function test_deposit_percentage_can_be_updated_but_not_cleared(): void
    {
        $owner = $this->owner();
        $hotel = Hotel::factory()->create(['deposit_percentage' => 20]);

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/hotels/{$hotel->id}", ['deposit_percentage' => 15])
            ->assertOk()
            ->assertJsonPath('data.deposit_percentage', '15.00');

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/hotels/{$hotel->id}", ['deposit_percentage' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('deposit_percentage');
    }

    public function test_guests_see_the_hotels_deposit_percentage_before_booking(): void
    {
        $hotel = Hotel::factory()->create(['deposit_percentage' => 10]);

        $this->getJson("/api/v1/guest/hotels/{$hotel->id}")
            ->assertOk()
            ->assertJsonPath('data.deposit_percentage', '10.00');
    }

    public function test_zero_deposit_is_allowed_and_partial_updates_need_no_deposit(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/hotels', $this->payload(['deposit_percentage' => 0]))
            ->assertCreated()
            ->assertJsonPath('data.deposit_percentage', '0.00');

        $hotel = Hotel::factory()->create(['deposit_percentage' => 15]);
        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/hotels/{$hotel->id}", ['check_in_mode' => 'reception'])
            ->assertOk()
            ->assertJsonPath('data.deposit_percentage', '15.00')
            ->assertJsonPath('data.check_in_mode', 'reception');

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/hotels/{$hotel->id}", ['check_in_mode' => 'robots'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('check_in_mode');
    }
}

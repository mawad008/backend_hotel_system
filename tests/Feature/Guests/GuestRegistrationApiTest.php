<?php

namespace Tests\Feature\Guests;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Reservation\Models\Guest;
use Tests\TestCase;

class GuestRegistrationApiTest extends TestCase
{
    public function test_staff_registers_a_walk_in_guest(): void
    {
        $owner = User::factory()->groupOwner()->create();

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/guests', [
                'name' => 'Omar Youssef',
                'phone' => '+201234567890',
                'email' => 'omar@example.com',
            ])
            ->assertStatus(201);

        $response->assertJsonPath('data.name', 'Omar Youssef')
            ->assertJsonPath('data.phone', '+201234567890')
            ->assertJsonPath('data.phone_verified_at', null)
            ->assertJsonPath('data.profile_complete', false);

        $this->assertDatabaseHas('guests', ['phone' => '+201234567890', 'name' => 'Omar Youssef']);
    }

    public function test_phone_is_normalized_and_required(): void
    {
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/guests', ['name' => 'No Phone'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_duplicate_phone_is_rejected(): void
    {
        $owner = User::factory()->groupOwner()->create();
        Guest::factory()->create(['phone' => '+201234567890']);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/guests', ['name' => 'Dup', 'phone' => '+201234567890'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_reception_can_register_a_walk_in_guest(): void
    {
        $hotel = Hotel::factory()->create();
        $reception = User::factory()->reception()->create();
        $reception->hotels()->attach($hotel);

        $this->actingAs($reception, 'sanctum')
            ->postJson('/api/v1/guests', ['name' => 'Walk In', 'phone' => '+15551234567'])
            ->assertStatus(201);
    }

    public function test_staff_without_guests_manage_permission_is_forbidden(): void
    {
        $user = User::factory()->guest()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/guests', ['name' => 'X', 'phone' => '+15551234567'])
            ->assertStatus(403);
    }

    public function test_a_later_otp_verification_with_the_same_phone_resolves_to_the_staff_created_guest(): void
    {
        $owner = User::factory()->groupOwner()->create();
        $phone = '+201112223334';

        $staffCreated = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/guests', ['name' => 'Layla Hassan', 'phone' => $phone])
            ->json('data');

        $challengeRes = $this->postJson('/api/v1/guest/auth/otp/request', ['phone' => $phone])->assertOk();

        $this->postJson('/api/v1/guest/auth/otp/verify', [
            'challenge_id' => $challengeRes->json('data.challenge_id'),
            'phone' => $phone,
            'code' => '123456',
        ])->assertOk()
            ->assertJsonPath('data.outcome', 'authenticated')
            ->assertJsonPath('data.guest.id', $staffCreated['id']);

        $this->assertSame(1, Guest::query()->where('phone', $phone)->count());
    }
}

<?php

namespace Tests\Feature\Loyalty;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Loyalty\Models\LoyaltyAccount;
use App\Domain\Loyalty\Models\LoyaltyTransaction;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use Tests\TestCase;

class GuestLoyaltyDashboardApiTest extends TestCase
{
    public function test_staff_views_a_guests_loyalty_account_across_hotels(): void
    {
        $owner = User::factory()->groupOwner()->create();
        $guest = Guest::factory()->create();
        $account = LoyaltyAccount::factory()->create(['guest_id' => $guest->id, 'points_balance' => 120]);

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/guests/{$guest->id}/loyalty")
            ->assertOk()
            ->assertJsonPath('data.points_balance', 120)
            ->assertJsonPath('data.guest_id', $guest->id);
    }

    public function test_staff_views_a_guests_loyalty_ledger(): void
    {
        $owner = User::factory()->groupOwner()->create();
        $guest = Guest::factory()->create();
        $account = LoyaltyAccount::factory()->create(['guest_id' => $guest->id]);
        LoyaltyTransaction::factory()->count(2)->create(['loyalty_account_id' => $account->id]);

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/guests/{$guest->id}/loyalty/transactions")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_account_is_created_lazily_for_a_guest_with_no_history(): void
    {
        $owner = User::factory()->groupOwner()->create();
        $guest = Guest::factory()->create();

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/guests/{$guest->id}/loyalty")
            ->assertOk()
            ->assertJsonPath('data.points_balance', 0);
    }

    public function test_a_hotel_manager_without_a_hotel_relation_to_the_guest_can_still_view_the_dashboard(): void
    {
        // Loyalty is group-wide, not hotel-scoped (mirrors the Guest
        // directory itself) — a manager with `loyalty.view` sees any
        // guest's account, same as they can look the guest up at all.
        $group = HotelGroup::factory()->create();
        $hotelA = Hotel::factory()->create(['hotel_group_id' => $group->id]);
        $hotelB = Hotel::factory()->create(['hotel_group_id' => $group->id]);
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotelA);

        $guest = Guest::factory()->create();
        Reservation::factory()->create([
            'hotel_id' => $hotelB->id,
            'room_type_id' => RoomType::factory()->create(['hotel_id' => $hotelB->id])->id,
            'guest_id' => $guest->id,
        ]);

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/guests/{$guest->id}/loyalty")
            ->assertOk();
    }

    public function test_staff_without_loyalty_permission_is_forbidden(): void
    {
        $user = User::factory()->guest()->create();
        $guest = Guest::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/guests/{$guest->id}/loyalty")
            ->assertStatus(403);
    }

    public function test_dashboard_requires_authentication(): void
    {
        $guest = Guest::factory()->create();

        $this->getJson("/api/v1/guests/{$guest->id}/loyalty")->assertStatus(401);
    }

    public function test_unknown_guest_returns_404(): void
    {
        $owner = User::factory()->groupOwner()->create();

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/guests/999999/loyalty')
            ->assertStatus(404);
    }
}

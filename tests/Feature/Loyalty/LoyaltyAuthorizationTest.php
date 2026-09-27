<?php

namespace Tests\Feature\Loyalty;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Loyalty\Models\LoyaltyAccount;
use App\Domain\Loyalty\Models\LoyaltyRule;
use App\Domain\Loyalty\Models\LoyaltyTransaction;
use App\Domain\Reservation\Models\Reservation;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LoyaltyAuthorizationTest extends TestCase
{
    private function reservation(Hotel $hotel, string $status = Reservation::STATUS_INVOICED): Reservation
    {
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);

        if (LoyaltyRule::query()->where('hotel_group_id', $hotel->hotel_group_id)->doesntExist()) {
            LoyaltyRule::factory()->active()->create(['hotel_group_id' => $hotel->hotel_group_id]);
        }

        return Reservation::factory()->create([
            'hotel_id' => $hotel->id, 'room_type_id' => $roomType->id,
            'status' => $status, 'price_snapshot' => '200.00',
        ]);
    }

    /**
     * @return array<string, array{string, bool, bool}>
     *                                                  factory => [can view, can manage]
     */
    public static function matrix(): array
    {
        return [
            'group owner' => ['groupOwner', true, true],
            'hotel manager' => ['hotelManager', true, true],
            'reception' => ['reception', true, false],
            'guest' => ['guest', false, false],
        ];
    }

    #[DataProvider('matrix')]
    public function test_role_matrix_across_the_reservation_loyalty_endpoints(string $factory, bool $canView, bool $canManage): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $user = User::factory()->{$factory}()->create();
        if ($factory !== 'guest') {
            $user->hotels()->attach($hotel);
        }
        $reservation = $this->reservation($hotel);

        // guest -> scope 404 (never resolves); other denied roles -> 403.
        $deniedView = $factory === 'guest' ? 404 : 403;
        $deniedManage = $factory === 'guest' ? 404 : 403;

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/reservations/{$reservation->id}/loyalty")
            ->assertStatus($canView ? 200 : $deniedView);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/reservations/{$reservation->id}/loyalty/transactions")
            ->assertStatus($canView ? 200 : $deniedView);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/reservations/{$reservation->id}/loyalty/earn")
            ->assertStatus($canManage ? 201 : $deniedManage);
    }

    public function test_reception_can_view_but_not_earn_or_redeem(): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $reception = User::factory()->reception()->create();
        $reception->hotels()->attach($hotel);
        $reservation = $this->reservation($hotel);
        // give the guest a balance so redeem would otherwise be possible
        $active = $this->reservation($hotel, Reservation::STATUS_IN_STAY);
        $active->update(['guest_id' => $reservation->guest_id]);
        LoyaltyTransaction::factory()->earn(500)->create([
            'loyalty_account_id' => LoyaltyAccount::factory()->create(['guest_id' => $reservation->guest_id])->id,
            'source_id' => 1,
        ]);

        $this->actingAs($reception, 'sanctum')
            ->getJson("/api/v1/reservations/{$reservation->id}/loyalty")->assertOk();
        $this->actingAs($reception, 'sanctum')
            ->postJson("/api/v1/reservations/{$reservation->id}/loyalty/earn")->assertStatus(403);
        $this->actingAs($reception, 'sanctum')
            ->postJson("/api/v1/reservations/{$active->id}/loyalty/redeem", ['points' => 100])->assertStatus(403);
    }

    public function test_client_supplied_hotel_id_cannot_widen_scope(): void
    {
        $hotelA = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $hotelB = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotelA);
        $reservation = $this->reservation($hotelB);

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/reservations/{$reservation->id}/loyalty/earn", ['hotel_id' => $hotelA->id])
            ->assertStatus(404);
    }
}

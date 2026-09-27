<?php

namespace Tests\Feature\Notification;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\IdentityAccess\Models\Permission;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Models\Notification;
use App\Domain\Reservation\Models\Reservation;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NotificationAuthorizationTest extends TestCase
{
    private function reservation(Hotel $hotel): Reservation
    {
        return Reservation::factory()->create([
            'hotel_id' => $hotel->id,
            'status' => Reservation::STATUS_DEPOSIT_HELD,
        ]);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function matrix(): array
    {
        return [
            'group owner' => ['groupOwner', true],
            'hotel manager' => ['hotelManager', true],
            'reception' => ['reception', true],
            'guest' => ['guest', false],
        ];
    }

    #[DataProvider('matrix')]
    public function test_role_matrix_across_the_feed(string $factory, bool $allowed): void
    {
        $hotel = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $user = User::factory()->{$factory}()->create();
        if ($factory !== 'guest') {
            $user->hotels()->attach($hotel);
        }
        $reservation = $this->reservation($hotel);
        $row = Notification::factory()->forReservation($reservation)->channel(NotificationChannel::InApp)->create();

        // guest never resolves the scope -> 404; other denied roles -> 403.
        $denied = $factory === 'guest' ? 404 : 403;

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/reservations/{$reservation->id}/notifications")
            ->assertStatus($allowed ? 200 : $denied);

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/reservations/{$reservation->id}/notifications/{$row->id}/read")
            ->assertStatus($allowed ? 200 : $denied);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/reservations/{$reservation->id}/notifications/read-all")
            ->assertStatus($allowed ? 200 : $denied);
    }

    public function test_a_manager_without_notifications_view_is_forbidden(): void
    {
        $hotel = Hotel::factory()->create();
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotel);
        $manager->role->permissions()->detach(
            Permission::query()->where('slug', 'notifications.view')->value('id'),
        );
        $reservation = $this->reservation($hotel);

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/reservations/{$reservation->id}/notifications")
            ->assertStatus(403);
    }

    public function test_client_supplied_hotel_id_cannot_widen_scope(): void
    {
        $hotelA = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $hotelB = Hotel::factory()->create(['hotel_group_id' => HotelGroup::factory()->create()->id]);
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotelA);
        $reservation = $this->reservation($hotelB);

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/reservations/{$reservation->id}/notifications?hotel_id={$hotelA->id}")
            ->assertStatus(404);
    }
}

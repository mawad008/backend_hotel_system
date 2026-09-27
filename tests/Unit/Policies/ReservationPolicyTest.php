<?php

namespace Tests\Unit\Policies;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\Permission;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityAccess\Services\HotelAccessService;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\Policies\ReservationPolicy;
use Tests\TestCase;

/**
 * `reservations.view` is not part of RolePermissionSeeder yet (adding it
 * is out of Phase 3B's scope — mirrors how RoomTypePolicyTest handled
 * `inventory.view` before Phase 2C), so each test grants it directly to
 * the role under test rather than touching the shared seeder.
 */
class ReservationPolicyTest extends TestCase
{
    private ReservationPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new ReservationPolicy(app(HotelAccessService::class));
    }

    private function grant(User $user, string $slug): void
    {
        $permission = Permission::firstOrCreate(['slug' => $slug], ['name_en' => $slug, 'description_en' => $slug]);
        $user->role->permissions()->syncWithoutDetaching([$permission->id]);
        $user->unsetRelation('role');
    }

    private function reservationFor(Hotel $hotel): Reservation
    {
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);

        return Reservation::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $roomType->id]);
    }

    public function test_group_owner_can_access_reservations_across_hotels(): void
    {
        $hotel = Hotel::factory()->create();
        $reservation = $this->reservationFor($hotel);
        $owner = User::factory()->groupOwner()->create();
        $this->grant($owner, 'reservations.view');

        $this->assertTrue($this->policy->viewAny($owner));
        $this->assertTrue($this->policy->view($owner, $reservation));
    }

    public function test_assigned_hotel_manager_can_view_their_hotels_reservation(): void
    {
        $hotel = Hotel::factory()->create();
        $reservation = $this->reservationFor($hotel);
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotel);
        $this->grant($manager, 'reservations.view');

        $this->assertTrue($this->policy->viewAny($manager));
        $this->assertTrue($this->policy->view($manager, $reservation));
    }

    public function test_unassigned_hotel_manager_is_denied_on_another_hotels_reservation(): void
    {
        $hotelA = Hotel::factory()->create();
        $hotelB = Hotel::factory()->create();
        $reservation = $this->reservationFor($hotelB);
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotelA);
        $this->grant($manager, 'reservations.view');

        $this->assertFalse($this->policy->view($manager, $reservation));
    }

    public function test_reception_can_view_their_assigned_hotels_reservation(): void
    {
        $hotel = Hotel::factory()->create();
        $reservation = $this->reservationFor($hotel);
        $reception = User::factory()->reception()->create();
        $reception->hotels()->attach($hotel);
        $this->grant($reception, 'reservations.view');

        $this->assertTrue($this->policy->viewAny($reception));
        $this->assertTrue($this->policy->view($reception, $reservation));
    }

    public function test_reception_is_denied_on_an_unassigned_hotels_reservation(): void
    {
        $hotelA = Hotel::factory()->create();
        $hotelB = Hotel::factory()->create();
        $reservation = $this->reservationFor($hotelB);
        $reception = User::factory()->reception()->create();
        $reception->hotels()->attach($hotelA);
        $this->grant($reception, 'reservations.view');

        $this->assertFalse($this->policy->view($reception, $reservation));
    }

    /**
     * Guest authentication does not exist yet — this asserts the Guest
     * *role* (a staff-side RBAC role with no permissions) receives no
     * reservation access, not any "own reservations" behavior, which
     * cannot be implemented without guest authentication.
     */
    public function test_guest_role_has_no_staff_reservation_access(): void
    {
        $hotel = Hotel::factory()->create();
        $reservation = $this->reservationFor($hotel);
        $guest = User::factory()->guest()->create();

        $this->assertFalse($this->policy->viewAny($guest));
        $this->assertFalse($this->policy->view($guest, $reservation));
    }

    public function test_cross_hotel_access_is_denied_even_with_the_permission_granted(): void
    {
        $hotelA = Hotel::factory()->create();
        $hotelB = Hotel::factory()->create();
        $reservationB = $this->reservationFor($hotelB);
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($hotelA);
        $this->grant($manager, 'reservations.view');

        $this->assertFalse($this->policy->view($manager, $reservationB));
    }
}

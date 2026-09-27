<?php

namespace Tests\Unit\StayServices;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\StayServices\Models\HotelService;
use App\Domain\StayServices\Models\ServiceCategory;
use App\Domain\StayServices\Policies\FolioPolicy;
use App\Domain\StayServices\Policies\HotelServicePolicy;
use App\Domain\StayServices\Policies\ServiceCategoryPolicy;
use App\Domain\StayServices\Policies\ServiceOrderPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StayServicesPolicyTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function roles(): array
    {
        return [
            'group owner' => ['groupOwner'],
            'hotel manager' => ['hotelManager'],
            'reception' => ['reception'],
            'guest' => ['guest'],
        ];
    }

    private function user(string $factory, Hotel $hotel): User
    {
        $user = User::factory()->{$factory}()->create();
        $user->hotels()->attach($hotel);

        return $user;
    }

    #[DataProvider('roles')]
    public function test_catalog_view_is_owner_manager_reception_only(string $factory): void
    {
        $hotel = Hotel::factory()->create();
        $user = $this->user($factory, $hotel);
        $category = ServiceCategory::factory()->create(['hotel_id' => $hotel->id]);
        $service = HotelService::factory()->create(['hotel_id' => $hotel->id]);

        $expected = $factory !== 'guest';

        $this->assertSame($expected, app(ServiceCategoryPolicy::class)->viewAny($user, $hotel));
        $this->assertSame($expected, app(ServiceCategoryPolicy::class)->view($user, $category));
        $this->assertSame($expected, app(HotelServicePolicy::class)->viewAny($user, $hotel));
        $this->assertSame($expected, app(HotelServicePolicy::class)->view($user, $service));
    }

    #[DataProvider('roles')]
    public function test_catalog_manage_excludes_reception_and_guest(string $factory): void
    {
        $hotel = Hotel::factory()->create();
        $user = $this->user($factory, $hotel);
        $service = HotelService::factory()->create(['hotel_id' => $hotel->id]);

        $expected = in_array($factory, ['groupOwner', 'hotelManager'], true);

        $this->assertSame($expected, app(HotelServicePolicy::class)->create($user, $hotel));
        $this->assertSame($expected, app(HotelServicePolicy::class)->update($user, $service));
        $this->assertSame($expected, app(ServiceCategoryPolicy::class)->create($user, $hotel));
    }

    #[DataProvider('roles')]
    public function test_service_orders_and_folio_are_owner_manager_reception(string $factory): void
    {
        $hotel = Hotel::factory()->create();
        $user = $this->user($factory, $hotel);
        $reservation = Reservation::factory()->create(['hotel_id' => $hotel->id]);

        $expected = $factory !== 'guest';

        $this->assertSame($expected, app(ServiceOrderPolicy::class)->viewAny($user, $reservation));
        $this->assertSame($expected, app(ServiceOrderPolicy::class)->create($user, $reservation));
        $this->assertSame($expected, app(ServiceOrderPolicy::class)->transition($user, $reservation));
        $this->assertSame($expected, app(FolioPolicy::class)->view($user, $reservation));
    }

    public function test_cross_hotel_is_denied_even_with_the_permission(): void
    {
        $assigned = Hotel::factory()->create();
        $other = Hotel::factory()->create();
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($assigned);

        $service = HotelService::factory()->create(['hotel_id' => $other->id]);
        $reservation = Reservation::factory()->create(['hotel_id' => $other->id]);

        $this->assertFalse(app(HotelServicePolicy::class)->update($manager, $service));
        $this->assertFalse(app(HotelServicePolicy::class)->viewAny($manager, $other));
        $this->assertFalse(app(ServiceOrderPolicy::class)->create($manager, $reservation));
        $this->assertFalse(app(FolioPolicy::class)->view($manager, $reservation));
    }

    public function test_group_owner_bypasses_hotel_scope(): void
    {
        $owner = User::factory()->groupOwner()->create();
        $hotel = Hotel::factory()->create();
        $reservation = Reservation::factory()->create(['hotel_id' => $hotel->id]);

        $this->assertTrue(app(HotelServicePolicy::class)->create($owner, $hotel));
        $this->assertTrue(app(ServiceOrderPolicy::class)->create($owner, $reservation));
        $this->assertTrue(app(FolioPolicy::class)->view($owner, $reservation));
    }
}

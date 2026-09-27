<?php

namespace Tests\Unit\Policies;

use App\Domain\DigitalAccess\Policies\AccessGrantPolicy;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Reservation\Models\Reservation;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccessGrantPolicyTest extends TestCase
{
    private function policy(): AccessGrantPolicy
    {
        return app(AccessGrantPolicy::class);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function roleMatrix(): array
    {
        return [
            'group owner' => ['groupOwner', true],
            'hotel manager' => ['hotelManager', true],
            'reception' => ['reception', true],
            'guest' => ['guest', false],
        ];
    }

    #[DataProvider('roleMatrix')]
    public function test_role_permissions_for_every_capability(string $factory, bool $allowed): void
    {
        $hotel = Hotel::factory()->create();
        $user = User::factory()->{$factory}()->create();
        $user->hotels()->attach($hotel);
        $reservation = Reservation::factory()->create(['hotel_id' => $hotel->id]);

        foreach (['checkIn', 'view', 'revoke'] as $ability) {
            $this->assertSame($allowed, $this->policy()->{$ability}($user, $reservation), "{$factory} {$ability}");
        }
    }

    public function test_cross_hotel_access_is_denied_even_with_the_permission(): void
    {
        $assigned = Hotel::factory()->create();
        $other = Hotel::factory()->create();
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($assigned);
        $reservation = Reservation::factory()->create(['hotel_id' => $other->id]);

        $this->assertFalse($this->policy()->checkIn($manager, $reservation));
        $this->assertFalse($this->policy()->view($manager, $reservation));
        $this->assertFalse($this->policy()->revoke($manager, $reservation));
    }

    public function test_group_owner_bypasses_hotel_scope(): void
    {
        $owner = User::factory()->groupOwner()->create();
        $reservation = Reservation::factory()->create(['hotel_id' => Hotel::factory()->create()->id]);

        $this->assertTrue($this->policy()->checkIn($owner, $reservation));
    }
}

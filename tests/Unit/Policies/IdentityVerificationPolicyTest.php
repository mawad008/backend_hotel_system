<?php

namespace Tests\Unit\Policies;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityVerification\Policies\IdentityVerificationPolicy;
use App\Domain\Reservation\Models\Reservation;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IdentityVerificationPolicyTest extends TestCase
{
    private function policy(): IdentityVerificationPolicy
    {
        return app(IdentityVerificationPolicy::class);
    }

    private function reservationInHotel(Hotel $hotel): Reservation
    {
        return Reservation::factory()->create(['hotel_id' => $hotel->id]);
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
        $reservation = $this->reservationInHotel($hotel);

        foreach (['view', 'submit', 'review'] as $ability) {
            $this->assertSame($allowed, $this->policy()->{$ability}($user, $reservation), "{$factory} {$ability}");
        }
    }

    public function test_cross_hotel_access_is_denied_even_with_the_permission(): void
    {
        $assigned = Hotel::factory()->create();
        $other = Hotel::factory()->create();
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($assigned);

        $reservation = $this->reservationInHotel($other);

        $this->assertFalse($this->policy()->view($manager, $reservation));
        $this->assertFalse($this->policy()->submit($manager, $reservation));
        $this->assertFalse($this->policy()->review($manager, $reservation));
    }

    public function test_group_owner_bypasses_hotel_scope(): void
    {
        $owner = User::factory()->groupOwner()->create();
        $reservation = $this->reservationInHotel(Hotel::factory()->create());

        $this->assertTrue($this->policy()->review($owner, $reservation));
    }
}

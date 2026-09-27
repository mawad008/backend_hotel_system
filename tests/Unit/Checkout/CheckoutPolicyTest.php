<?php

namespace Tests\Unit\Checkout;

use App\Domain\Checkout\Policies\CheckoutPolicy;
use App\Domain\Checkout\Policies\InvoicePolicy;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Reservation\Models\Reservation;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CheckoutPolicyTest extends TestCase
{
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
    public function test_checkout_and_invoice_permissions(string $factory, bool $allowed): void
    {
        $hotel = Hotel::factory()->create();
        $user = User::factory()->{$factory}()->create();
        $user->hotels()->attach($hotel);
        $reservation = Reservation::factory()->create(['hotel_id' => $hotel->id]);

        $this->assertSame($allowed, app(CheckoutPolicy::class)->perform($user, $reservation));
        $this->assertSame($allowed, app(CheckoutPolicy::class)->view($user, $reservation));
        $this->assertSame($allowed, app(InvoicePolicy::class)->view($user, $reservation));
    }

    public function test_cross_hotel_is_denied_with_the_permission(): void
    {
        $assigned = Hotel::factory()->create();
        $other = Hotel::factory()->create();
        $manager = User::factory()->hotelManager()->create();
        $manager->hotels()->attach($assigned);
        $reservation = Reservation::factory()->create(['hotel_id' => $other->id]);

        $this->assertFalse(app(CheckoutPolicy::class)->perform($manager, $reservation));
        $this->assertFalse(app(InvoicePolicy::class)->view($manager, $reservation));
    }

    public function test_group_owner_bypasses_hotel_scope(): void
    {
        $owner = User::factory()->groupOwner()->create();
        $reservation = Reservation::factory()->create(['hotel_id' => Hotel::factory()->create()->id]);

        $this->assertTrue(app(CheckoutPolicy::class)->perform($owner, $reservation));
    }
}

<?php

namespace Tests\Unit\Models;

use App\Domain\Reservation\Models\Guest;
use Illuminate\Database\QueryException;
use Tests\TestCase;

/**
 * Slice 0 flipped the Guest contract: `phone` (E.164) is now the required,
 * unique login identifier and `name`/`email` are nullable until the guest
 * completes their profile after phone verification.
 */
class GuestTest extends TestCase
{
    public function test_guest_can_be_created(): void
    {
        $guest = Guest::create([
            'name' => 'Jane Doe',
            'email' => 'jane.doe@example.com',
            'phone' => '+15550000100',
        ]);

        $this->assertDatabaseHas('guests', [
            'id' => $guest->id,
            'name' => 'Jane Doe',
            'email' => 'jane.doe@example.com',
            'phone' => '+15550000100',
        ]);
    }

    public function test_name_and_email_are_nullable_before_profile_completion(): void
    {
        $guest = Guest::factory()->unregistered()->create();

        $this->assertNull($guest->fresh()->name);
        $this->assertNull($guest->fresh()->email);
        $this->assertFalse($guest->isProfileComplete());
    }

    public function test_phone_is_required(): void
    {
        $this->expectException(QueryException::class);

        Guest::factory()->create(['phone' => null]);
    }

    public function test_phone_is_unique(): void
    {
        Guest::factory()->create(['phone' => '+15550000111']);

        $this->expectException(QueryException::class);

        Guest::factory()->create(['phone' => '+15550000111']);
    }

    /**
     * Deliberate: Guest email is not unique (unlike staff `users.email`) —
     * two family members may share one address. Uniqueness lives on `phone`.
     */
    public function test_email_is_not_required_to_be_unique(): void
    {
        Guest::factory()->create(['email' => 'shared@example.com']);
        $second = Guest::factory()->create(['email' => 'shared@example.com']);

        $this->assertDatabaseHas('guests', ['id' => $second->id, 'email' => 'shared@example.com']);
        $this->assertSame(2, Guest::where('email', 'shared@example.com')->count());
    }

    public function test_profile_complete_requires_name_email_and_timestamp(): void
    {
        $guest = Guest::factory()->create();

        $this->assertTrue($guest->isProfileComplete());
    }

    public function test_factory_creates_a_valid_guest(): void
    {
        $guest = Guest::factory()->create();

        $this->assertDatabaseHas('guests', ['id' => $guest->id]);
        $this->assertNotEmpty($guest->phone);
    }

    public function test_multiple_factory_created_guests_are_all_persisted_and_distinct(): void
    {
        $guests = Guest::factory()->count(5)->create();

        $this->assertCount(5, $guests);
        $this->assertSame(5, Guest::count());
        $this->assertSame(5, $guests->pluck('id')->unique()->count());
    }
}

<?php

namespace Database\Factories;

use App\Domain\IdentityAccess\Models\Role;
use App\Domain\IdentityAccess\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password;

    public function definition(): array
    {
        return [
            'role_id' => RoleFactory::new(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'is_active' => true,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function withRole(string $slug): static
    {
        return $this->state(fn (array $attributes) => [
            'role_id' => Role::where('slug', $slug)->first()?->id
                ?? RoleFactory::new()->state(['slug' => $slug]),
        ]);
    }

    public function groupOwner(): static
    {
        return $this->withRole(Role::GROUP_OWNER);
    }

    public function hotelManager(): static
    {
        return $this->withRole(Role::HOTEL_MANAGER);
    }

    public function reception(): static
    {
        return $this->withRole(Role::RECEPTION);
    }

    public function guest(): static
    {
        return $this->withRole(Role::GUEST);
    }
}

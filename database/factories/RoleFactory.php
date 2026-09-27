<?php

namespace Database\Factories;

use App\Domain\IdentityAccess\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'name_en' => ucfirst($name),
            'name_ar' => ucfirst($name),
            'slug' => $name,
            'description_en' => null,
            'description_ar' => null,
            'is_system' => false,
        ];
    }
}

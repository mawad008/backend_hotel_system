<?php

namespace Database\Factories;

use App\Domain\IdentityAccess\Models\Permission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Permission>
 */
class PermissionFactory extends Factory
{
    protected $model = Permission::class;

    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'name_en' => ucfirst($name),
            'name_ar' => ucfirst($name),
            'slug' => $name,
            'description_en' => null,
            'description_ar' => null,
        ];
    }
}

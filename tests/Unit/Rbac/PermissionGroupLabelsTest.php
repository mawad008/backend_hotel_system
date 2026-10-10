<?php

namespace Tests\Unit\Rbac;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Every permission group in the catalog needs an EN + AR label in
 * `lang/{en,ar}/permission_groups.php` — otherwise the dashboard roles
 * matrix shows the raw `permission_groups.<group>` key. DB-free.
 */
class PermissionGroupLabelsTest extends BaseTestCase
{
    public function test_every_permission_group_has_an_english_and_arabic_label(): void
    {
        $groups = array_unique(array_map(
            fn (string $slug) => explode('.', $slug, 2)[0],
            array_keys(RolePermissionSeeder::permissionCatalog()),
        ));

        foreach ($groups as $group) {
            foreach (['en', 'ar'] as $locale) {
                $key = 'permission_groups.'.$group;
                $this->assertNotSame($key, __($key, [], $locale), "Missing {$locale} label for permission group [{$group}].");
            }
        }
    }

    public function test_every_permission_has_an_arabic_name_and_description(): void
    {
        foreach (RolePermissionSeeder::permissionCatalog() as $slug => [$nameEn, $nameAr, $descriptionEn, $descriptionAr]) {
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $nameAr, "[{$slug}] name_ar is not Arabic.");
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $descriptionAr, "[{$slug}] description_ar is not Arabic.");
        }
    }
}

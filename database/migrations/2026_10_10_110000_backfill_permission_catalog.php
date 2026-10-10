<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Brings already-seeded databases up to the current permission catalog.
 *
 * Several permissions (e.g. `app-content.manage` for the dashboard's Guest
 * App editor) were only ever added to RolePermissionSeeder, so a database
 * seeded before them never got them — they were missing from the roles
 * matrix and nobody could be granted them. This inserts every missing
 * permission, refreshes the EN/AR names/descriptions of existing ones, and
 * grants the full catalog to the Group Owner system role (which holds every
 * permission by definition).
 *
 * Additive on purpose: other roles' permission sets are left untouched, since
 * re-running the seeder would re-sync every system role and discard admin
 * edits.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach (RolePermissionSeeder::permissionCatalog() as $slug => [$nameEn, $nameAr, $descriptionEn, $descriptionAr]) {
            $labels = [
                'name_en' => $nameEn,
                'name_ar' => $nameAr,
                'description_en' => $descriptionEn,
                'description_ar' => $descriptionAr,
                'updated_at' => $now,
            ];

            if (DB::table('permissions')->where('slug', $slug)->exists()) {
                DB::table('permissions')->where('slug', $slug)->update($labels);
            } else {
                DB::table('permissions')->insert(['slug' => $slug, 'created_at' => $now] + $labels);
            }
        }

        $ownerId = DB::table('roles')->where('slug', 'group_owner')->value('id');

        if ($ownerId !== null) {
            DB::table('permission_role')->insertOrIgnore(
                DB::table('permissions')->pluck('id')
                    ->map(fn ($permissionId) => ['role_id' => $ownerId, 'permission_id' => $permissionId])
                    ->all(),
            );
        }
    }

    public function down(): void
    {
        // Data backfill only — nothing to undo safely.
    }
};

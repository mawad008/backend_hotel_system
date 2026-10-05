<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds `hotels.update` (edit an assigned hotel's profile/content without
 * create/delete/regroup/deactivate rights) to existing databases and grants
 * it — plus `facilities.view` for the hotel form's facility picker — to the
 * Hotel Manager system role. Group Owner holds every permission, so it gets
 * `hotels.update` too.
 *
 * Additive on purpose: re-running RolePermissionSeeder would re-sync every
 * system role and discard any admin edits to their permission sets.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('permissions')->insertOrIgnore([
            'slug' => 'hotels.update',
            'name_en' => 'Edit assigned hotels',
            'name_ar' => 'تعديل الفنادق المسندة',
            'description_en' => 'Edit the profile and content of assigned hotels (no create, delete, group change or deactivation)',
            'description_ar' => 'تعديل بيانات ومحتوى الفنادق المسندة (دون الإنشاء أو الحذف أو تغيير المجموعة أو إيقاف التفعيل)',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->grant('hotel_manager', ['hotels.update', 'facilities.view']);
        $this->grant('group_owner', ['hotels.update']);
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('slug', 'hotels.update')->value('id');

        if ($id !== null) {
            DB::table('permission_role')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
    }

    /**
     * @param  list<string>  $permissionSlugs
     */
    private function grant(string $roleSlug, array $permissionSlugs): void
    {
        $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');

        if ($roleId === null) {
            return;
        }

        $rows = DB::table('permissions')
            ->whereIn('slug', $permissionSlugs)
            ->pluck('id')
            ->map(fn ($permissionId) => ['role_id' => $roleId, 'permission_id' => $permissionId])
            ->all();

        DB::table('permission_role')->insertOrIgnore($rows);
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds `reservations.override` (move a reservation to its next status from
 * the dashboard by running that step's real flow on the guest's behalf) to
 * existing databases and grants it to the Group Owner and Hotel Manager
 * system roles. Other roles get it from the roles page.
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
            'slug' => 'reservations.override',
            'name_en' => 'Override reservation flow',
            'name_ar' => 'تجاوز مسار الحجز',
            'description_en' => "Move a reservation to its next status from the dashboard, running that step's real flow (deposit, identity approval without documents, check-in, checkout) on the guest's behalf",
            'description_ar' => 'نقل الحجز إلى مرحلته التالية من لوحة التحكم مع تنفيذ مسار المرحلة الفعلي (التأمين، اعتماد الهوية دون مستندات، الدخول، المغادرة) نيابةً عن النزيل',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->grant('group_owner', ['reservations.override']);
        $this->grant('hotel_manager', ['reservations.override']);
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('slug', 'reservations.override')->value('id');

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

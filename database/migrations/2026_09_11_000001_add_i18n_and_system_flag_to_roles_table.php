<?php

use App\Domain\IdentityAccess\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Roles become dynamically manageable (create/update/delete) instead of a
 * fixed, code-only catalog. This migration:
 *  - renames the existing English `name`/`description` columns to
 *    `name_en`/`description_en` (mirrors the name_en/name_ar convention
 *    already used by Country/City/Hotel), preserving the existing English
 *    text for every existing row;
 *  - adds `name_ar`/`description_ar` for the Arabic translation;
 *  - adds `is_system` to protect the four approved roles (Group Owner,
 *    Hotel Manager, Reception, Guest) from deletion while still allowing
 *    their translations/permissions to be edited.
 *
 * The Arabic backfill for the four known system roles runs in the same
 * migration so existing data is immediately usable, not left null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->renameColumn('name', 'name_en');
            $table->renameColumn('description', 'description_en');
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->string('name_ar')->nullable()->after('name_en');
            $table->text('description_ar')->nullable()->after('description_en');
            $table->boolean('is_system')->default(false)->after('slug');
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->text('description_en')->nullable()->change();
        });

        $translations = [
            Role::GROUP_OWNER => [
                'name_ar' => 'مالك المجموعة',
                'description_ar' => 'إشراف على مستوى المجموعة عبر جميع الفنادق.',
            ],
            Role::HOTEL_MANAGER => [
                'name_ar' => 'مدير الفندق',
                'description_ar' => 'يدير فندقًا واحدًا أو أكثر من الفنادق المسندة إليه.',
            ],
            Role::RECEPTION => [
                'name_ar' => 'الاستقبال',
                'description_ar' => 'دعم مكتب الاستقبال لفندق واحد مسند إليه.',
            ],
            Role::GUEST => [
                'name_ar' => 'نزيل',
                'description_ar' => 'هوية خاصة بالنزلاء فقط. لا تملك أي صلاحيات إدارية في لوحة التحكم.',
            ],
        ];

        foreach ($translations as $slug => $data) {
            DB::table('roles')->where('slug', $slug)->update([
                'name_ar' => $data['name_ar'],
                'description_ar' => $data['description_ar'],
                'is_system' => true,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['name_ar', 'description_ar', 'is_system']);
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->renameColumn('name_en', 'name');
            $table->renameColumn('description_en', 'description');
        });
    }
};

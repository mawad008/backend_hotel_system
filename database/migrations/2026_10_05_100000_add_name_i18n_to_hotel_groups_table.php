<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bilingual hotel group names — same additive pattern as `hotels.name_i18n`:
 * the legacy `name` string stays (kept in sync from the fallback locale by
 * HotelGroupService) and `name_i18n` holds `{"en": "...", "ar": "..."}`.
 *
 * Existing groups are backfilled with their current name as the fallback-
 * locale entry only — no Arabic name is invented; the dashboard falls back
 * to `name` until an admin fills it in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotel_groups', function (Blueprint $table) {
            $table->json('name_i18n')->nullable()->after('name');
        });

        $fallback = config('app.fallback_locale', 'en');

        DB::table('hotel_groups')->select('id', 'name')->get()->each(function ($group) use ($fallback) {
            DB::table('hotel_groups')->where('id', $group->id)->update([
                'name_i18n' => json_encode([$fallback => $group->name], JSON_UNESCAPED_UNICODE),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('hotel_groups', function (Blueprint $table) {
            $table->dropColumn('name_i18n');
        });
    }
};

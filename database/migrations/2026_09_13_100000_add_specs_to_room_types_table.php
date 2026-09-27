<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            // Bilingual bed-type label (e.g. "Double bed" / "سرير مزدوج"),
            // same `{locale: value}` convention as `hotels.name_i18n` —
            // resolved server-side by `LocalizedContent` in the guest
            // resources, never fabricated client-side.
            $table->json('bed_type_i18n')->nullable()->after('capacity');
            $table->unsignedSmallInteger('area_sqm')->nullable()->after('bed_type_i18n');
            $table->boolean('breakfast_included')->default(false)->after('area_sqm');
            $table->boolean('refundable')->default(false)->after('breakfast_included');
        });
    }

    public function down(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->dropColumn(['bed_type_i18n', 'area_sqm', 'breakfast_included', 'refundable']);
        });
    }
};

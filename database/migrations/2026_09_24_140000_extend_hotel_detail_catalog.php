<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rounds out the dashboard-managed guest Hotel Detail content on the
 * existing tables (no parallel catalog):
 *
 * - `facilities.description_i18n` — optional per-locale description of a
 *   catalog facility / service (the Hotel Detail facilities section).
 * - `hotel_highlights.is_active` — hide a "why choose" card without
 *   deleting it.
 * - `hotel_nearby_places` — `category` (NearbyPlaceCategory), `distance` +
 *   `distance_unit` (m / km), the place's own `latitude` / `longitude`, and
 *   `is_active`. `travel_minutes` stays: the Figma row shows travel time;
 *   distance is shown when no travel time is on file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facilities', function (Blueprint $table) {
            $table->json('description_i18n')->nullable()->after('name_i18n');
        });

        Schema::table('hotel_highlights', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('subtitle_i18n');
        });

        Schema::table('hotel_nearby_places', function (Blueprint $table) {
            $table->string('category', 32)->nullable()->after('icon');
            $table->decimal('distance', 8, 2)->nullable()->after('travel_minutes');
            $table->string('distance_unit', 4)->nullable()->after('distance');
            $table->decimal('latitude', 10, 7)->nullable()->after('distance_unit');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->boolean('is_active')->default(true)->after('longitude');
        });
    }

    public function down(): void
    {
        Schema::table('hotel_nearby_places', function (Blueprint $table) {
            $table->dropColumn(['category', 'distance', 'distance_unit', 'latitude', 'longitude', 'is_active']);
        });

        Schema::table('hotel_highlights', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });

        Schema::table('facilities', function (Blueprint $table) {
            $table->dropColumn('description_i18n');
        });
    }
};

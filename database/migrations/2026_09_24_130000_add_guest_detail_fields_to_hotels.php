<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The guest Hotel Detail (Figma `HOTEL_Detail_Premium`) content that the
 * catalog did not store yet. Everything is admin-managed from the dashboard
 * hotel form and nullable / empty by default — the guest app hides a row or
 * section that has no data, it never fabricates one.
 *
 * - `hotels.check_in_time` / `check_out_time` — the property's standard
 *   check-in / check-out times (quick-info card).
 * - `hotels.suitable_for_i18n` — short "مناسب لـ" copy (e.g. "العائلات /
 *   رجال الأعمال"), per locale.
 * - `hotels.location_note_i18n` — the location section's one-liner (e.g.
 *   "يبعد 10 دقائق عن الكورنيش"), per locale.
 * - `hotels.latitude` / `longitude` — the map pin.
 * - `hotel_highlights` — the "why choose this hotel" feature cards (icon,
 *   title, subtitle), ordered.
 * - `hotel_nearby_places` — the location section's nearby places (icon,
 *   name, travel time in minutes), ordered.
 * - `room_types.view_i18n` — a room type's view ("إطلالة المدينة"), next to
 *   the existing `bed_type_i18n` / `area_sqm` specs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->time('check_in_time')->nullable()->after('star_rating');
            $table->time('check_out_time')->nullable()->after('check_in_time');
            $table->json('suitable_for_i18n')->nullable()->after('check_out_time');
            $table->json('location_note_i18n')->nullable()->after('suitable_for_i18n');
            $table->decimal('latitude', 10, 7)->nullable()->after('location_note_i18n');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });

        Schema::table('room_types', function (Blueprint $table) {
            $table->json('view_i18n')->nullable()->after('bed_type_i18n');
        });

        Schema::create('hotel_highlights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('icon', 64)->nullable();
            $table->json('title_i18n');
            $table->json('subtitle_i18n')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['hotel_id', 'sort_order']);
        });

        Schema::create('hotel_nearby_places', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('icon', 64)->nullable();
            $table->json('name_i18n');
            $table->unsignedSmallInteger('travel_minutes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['hotel_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_nearby_places');
        Schema::dropIfExists('hotel_highlights');

        Schema::table('room_types', function (Blueprint $table) {
            $table->dropColumn('view_i18n');
        });

        Schema::table('hotels', function (Blueprint $table) {
            $table->dropColumn([
                'check_in_time', 'check_out_time', 'suitable_for_i18n',
                'location_note_i18n', 'latitude', 'longitude',
            ]);
        });
    }
};

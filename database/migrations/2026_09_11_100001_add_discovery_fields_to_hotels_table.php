<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Discovery enrichment (guest booking slice). Adds the hotel presentation
 * fields the guest Discovery screens need and that the schema did not carry.
 *
 * i18n: `name_i18n` / `description_i18n` / `tagline_i18n` are JSON maps keyed
 * by locale (`{"en": "...", "ar": "..."}`). They are ADDITIVE — the legacy
 * `name` string column stays authoritative for search/slug and is kept in
 * sync from `name_i18n` by HotelService. Existing rows keep working with
 * `*_i18n` null (the API resource falls back to `name`).
 *
 * Reviews are intentionally NOT added here: there is no Review domain, and
 * inventing a review aggregate on the hotel row is out of scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->json('name_i18n')->nullable()->after('name');
            $table->json('tagline_i18n')->nullable()->after('name_i18n');
            $table->json('description_i18n')->nullable()->after('tagline_i18n');

            // Hotel class (1..5). Nullable — an unrated/unclassified hotel is
            // valid; the guest card hides the star row when null.
            $table->unsignedTinyInteger('star_rating')->nullable()->after('description_i18n');

            // Guest-facing amenity slugs, e.g. ["free_wifi","pool"]. Same
            // free-form JSON-array shape room_types.amenities already uses.
            $table->json('amenities')->nullable()->after('star_rating');
        });
    }

    public function down(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->dropColumn([
                'name_i18n',
                'tagline_i18n',
                'description_i18n',
                'star_rating',
                'amenities',
            ]);
        });
    }
};

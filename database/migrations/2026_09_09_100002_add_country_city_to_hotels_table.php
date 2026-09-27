<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Normalize the hotel location onto the Country/City master data.
 *
 * The legacy free-text `country` / `city` columns are intentionally kept:
 * the guest Discovery API still exposes `city` as a string, and they are
 * the source for the one-off backfill in the next migration. New/updated
 * hotels are validated to reference a City that belongs to the Country.
 *
 * Both FKs are nullable (existing rows have no reference yet) and
 * nullOnDelete — removing a referenced location must never delete a hotel
 * (the service also blocks deleting a referenced location outright).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->foreignId('country_id')->nullable()->after('slug')
                ->constrained('countries')->nullOnDelete();
            $table->foreignId('city_id')->nullable()->after('country_id')
                ->constrained('cities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('city_id');
            $table->dropConstrainedForeignId('country_id');
        });
    }
};

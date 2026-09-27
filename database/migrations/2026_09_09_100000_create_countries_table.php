<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Country + City master data (global reference data — NOT hotel-scoped).
 * Reused by Hotels, Discovery, search/filters and future guest/reporting
 * flows. MariaDB-compatible: no ENUM columns, explicit index names kept
 * short.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('name_en');
            $table->string('name_ar');
            // ISO-3166 alpha-2/alpha-3 or an internal short code — a plain
            // string, uppercased by the service. Unique across the table.
            $table->string('code', 8)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};

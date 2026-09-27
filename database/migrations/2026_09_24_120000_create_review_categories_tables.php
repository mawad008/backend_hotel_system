<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dynamic, per-hotel review categories (the criteria a guest rates a stay
 * on — "النظافة", "الإفطار", …) and the per-category ratings attached to a
 * guest's review. Categories are database entities managed from the
 * dashboard; nothing about their number or names is hardcoded anywhere.
 *
 * ── Design ──
 * - `review_categories` belongs to one hotel; two hotels can have completely
 *   different sets. `is_active` hides a category from *new* reviews without
 *   touching history. A category that has ever been rated cannot be hard
 *   deleted (`restrictOnDelete` on the rating FK, and the service refuses
 *   first) — deactivate it instead.
 * - `review_category_ratings` is the junction between a review and the
 *   categories it rated (one row per category, UNIQUE per review). It keeps
 *   the category reference (the stable identity every average groups by)
 *   **and** a snapshot of the category's names at rating time, so an old
 *   review stays readable exactly as the guest saw it even after an admin
 *   renames the category.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            // Canonical label, plus optional per-locale overrides.
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->string('description', 500)->nullable();
            // Optional icon key (the same open key vocabulary as facilities).
            $table->string('icon', 64)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['hotel_id', 'name']);
            $table->index(['hotel_id', 'is_active', 'sort_order']);
        });

        Schema::create('review_category_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            // Rated categories are never deleted (history) — restrict.
            $table->foreignId('review_category_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('rating');
            // Snapshot of the category's labels when the guest rated it.
            $table->string('category_name');
            $table->string('category_name_ar')->nullable();
            $table->string('category_name_en')->nullable();
            $table->timestamps();

            $table->unique(['review_id', 'review_category_id']);
            $table->index('review_category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_category_ratings');
        Schema::dropIfExists('review_categories');
    }
};

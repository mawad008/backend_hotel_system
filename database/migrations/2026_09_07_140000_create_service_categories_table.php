<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 8 — an OPTIONAL, hotel-scoped grouping for a hotel's service
     * catalog (Phase 0 §6.1 "Hotel ... owns its ... Services catalog";
     * §4 "Guest Services & Folio" context). Categories carry no business
     * rules of their own and none are seeded — a hotel configures its own.
     *
     * MariaDB 10.4-compatible: plain indexes only, identifier names <= 64
     * chars, no MySQL-8-only features.
     */
    public function up(): void
    {
        Schema::create('service_categories', function (Blueprint $table) {
            $table->id();

            // Hotel-scoped: full per-hotel data isolation (Phase 0 §5, R2).
            // A category belongs to exactly one hotel; deleting the hotel is
            // blocked rather than cascaded, the same posture every other
            // hotel-scoped table uses.
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();

            $table->string('name');
            $table->string('description', 500)->nullable();

            // Deactivation, never deletion, is the way a category leaves the
            // catalog — historical services keep referencing it.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // hotel_id is already indexed by its foreign key. The unique
            // constraint guarantees no duplicate category name per hotel and
            // also serves hotel-scoped lookups; the (hotel_id, is_active)
            // index serves the "active categories for this hotel" list.
            $table->unique(['hotel_id', 'name']);
            $table->index(['hotel_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_categories');
    }
};

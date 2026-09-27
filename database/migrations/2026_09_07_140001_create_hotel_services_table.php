<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 8 — a single thing a guest can request/consume during a stay
     * (Phase 0 §4 "Guest Services & Folio", §6.4 `hotel_services`, R17/R20).
     *
     * Simple pricing only (Phase 0 R54 "no dynamic/occupancy pricing"):
     * one `price` per service. No taxes, discounts, commissions, tiers, or
     * inventory rules — those are deliberately out of Phase 8 scope.
     *
     * MariaDB 10.4-compatible.
     */
    public function up(): void
    {
        Schema::create('hotel_services', function (Blueprint $table) {
            $table->id();

            // Hotel-scoped: a service belongs to exactly one hotel (R2).
            // Restricted delete — a hotel with a catalog cannot be dropped.
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();

            // Optional grouping. Nulled (not cascaded) if the category is
            // removed — the service survives without a category.
            $table->foreignId('service_category_id')->nullable()
                ->constrained('service_categories')->nullOnDelete();

            $table->string('name');
            $table->string('description', 1000)->nullable();

            // Money is DECIMAL, never float (Phase 0 §17 spirit / guardrail).
            // DECIMAL(12,2) matches `payments.amount`. Non-negative; the
            // Form Request caps the staff-settable magnitude.
            $table->decimal('price', 12, 2);

            // ISO-4217 alpha code. Nullable and never defaulted — the
            // platform has no chosen business currency yet (Phase 0 §20
            // item 7, mirrored from `payments.currency`).
            $table->char('currency', 3)->nullable();

            // Deactivation is the catalog-exit path; a service referenced by
            // historical orders is never deleted (see the service layer).
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // hotel_id / service_category_id are already indexed by their
            // foreign keys. Unique name per hotel; (hotel_id, is_active)
            // serves the "active services for this hotel" catalog list.
            $table->unique(['hotel_id', 'name']);
            $table->index(['hotel_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_services');
    }
};

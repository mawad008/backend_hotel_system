<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 8 — a financial amount attached to a reservation's folio
     * (Phase 0 §4 "Guest Services & Folio", §6.4 `guest_charges`, R26-R31).
     *
     * FolioCharge = what the guest owes / was charged for. It is NOT a
     * Payment (money movement) — the two have different responsibilities and
     * are never mixed. No card / CVV / provider secret is ever stored here.
     *
     * `source_type` + `source_id` point back to what produced the charge.
     * Phase 8 produces charges from `service_order` only; the checkout phase
     * will add its own source(s) via a follow-up migration when defined.
     *
     * MariaDB 10.4-compatible: DECIMAL money, enum + plain indexes only.
     */
    public function up(): void
    {
        Schema::create('folio_charges', function (Blueprint $table) {
            $table->id();

            // Financial history — deleting the reservation is blocked.
            $table->foreignId('reservation_id')->constrained()->restrictOnDelete();

            // Denormalized from the reservation, never a client value.
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();

            // What produced this charge. `source_id` is a soft reference
            // (not a DB foreign key — the source table varies by type).
            $table->enum('source_type', ['service_order']);
            $table->unsignedBigInteger('source_id')->nullable();

            $table->string('description', 500);

            $table->unsignedInteger('quantity');
            $table->decimal('unit_amount', 12, 2);

            // quantity * unit_amount, computed server-side. Never client-set.
            $table->decimal('total_amount', 12, 2);

            $table->char('currency', 3)->nullable();

            // Charge lifecycle — distinct from any payment status. `posted`
            // = a live amount the guest owes; `cancelled` = voided (e.g. the
            // originating service order was cancelled). A charge is NEVER
            // implicitly "paid" just because it exists.
            $table->enum('status', ['posted', 'cancelled'])->default('posted');

            $table->timestamp('charged_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // Staff member who caused the charge; NULL for system-originated.
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // Safe, non-sensitive context only.
            $table->json('metadata')->nullable();

            $table->timestamps();

            // One charge per (source_type, source_id): the idempotency guard
            // that makes charge creation safe to retry.
            $table->unique(['source_type', 'source_id']);

            // reservation_id / hotel_id already indexed by their FKs; these
            // composites serve the folio view and hotel-scoped reporting.
            $table->index(['reservation_id', 'status']);
            $table->index(['hotel_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folio_charges');
    }
};

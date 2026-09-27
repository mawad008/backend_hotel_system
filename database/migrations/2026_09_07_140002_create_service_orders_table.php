<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 8 — a service requested/ordered for one reservation during the
     * stay (Phase 0 §6.4 `service_orders`, R17 "in-stay requests", R20).
     *
     * Historical pricing is preserved on the row itself
     * (`unit_price_snapshot` / `currency_snapshot` / `total_amount`) — a
     * historical charge is NEVER reconstructed from the service's current
     * price.
     *
     * Lifecycle (Phase 8 technical decision — no explicit lifecycle is in
     * the approved baseline, so the smallest practical one is used; rules
     * live only in ServiceOrderStateMachine):
     *   requested -> confirmed -> fulfilled
     *   requested -> cancelled
     *   confirmed -> cancelled
     *
     * MariaDB 10.4-compatible: enum + plain indexes only.
     */
    public function up(): void
    {
        Schema::create('service_orders', function (Blueprint $table) {
            $table->id();

            // The reservation this service is for. Operational/financial
            // history — deleting the reservation is blocked, never cascaded.
            $table->foreignId('reservation_id')->constrained()->restrictOnDelete();

            // Denormalized from the reservation for hotel-scoped
            // listing/reporting. Always set from the reservation's hotel,
            // never a client value.
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();

            // The ordered service. Restricted delete — a service with orders
            // is deactivated, never removed, so the snapshot's source stays
            // resolvable.
            $table->foreignId('service_id')->constrained('hotel_services')->restrictOnDelete();

            // >= 1, validated in the Form Request and the service layer.
            $table->unsignedInteger('quantity');

            // Price captured at order time — the historical source of truth.
            $table->decimal('unit_price_snapshot', 12, 2);
            $table->char('currency_snapshot', 3)->nullable();

            // unit_price_snapshot * quantity, computed server-side with
            // decimal-safe arithmetic. Never accepted from the client.
            $table->decimal('total_amount', 12, 2);

            $table->enum('status', [
                'requested',
                'confirmed',
                'fulfilled',
                'cancelled',
            ])->default('requested');

            $table->string('notes', 500)->nullable();

            // The staff member who recorded the order; NULL for a
            // system-originated one. Nulled if the user is deleted.
            $table->foreignId('requested_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamp('requested_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();

            $table->timestamps();

            // reservation_id / hotel_id / service_id already indexed by FKs.
            // These composites serve the reservation folio view and
            // hotel-scoped operational lists filtered by status.
            $table->index(['reservation_id', 'status']);
            $table->index(['hotel_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_orders');
    }
};

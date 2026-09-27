<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5A — the Payment lifecycle record for one Reservation (Phase 0
     * §6.1: "Reservation ... has one Payment record"; Phase 5 plan v2 §7.1).
     * Schema/domain foundation only — no workflow, no provider, no HTTP.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            // Approved 1:1 Reservation -> Payment. Hard financial record —
            // deleting the Reservation is blocked rather than cascaded, the
            // same reasoning reservations already apply to their own FKs.
            $table->foreignId('reservation_id')->unique()->constrained()->restrictOnDelete();

            // Denormalized on purpose (Phase 5 plan v2 §14.3): hotel scope is
            // still resolved server-side from the owning Reservation / a
            // Policy, exactly as the Reservation domain does — this column
            // exists for efficient hotel-scoped listing/reporting, and is set
            // from the Reservation's hotel, never a client value.
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();

            // The approved persistent Payment statuses (Phase 0 §9, Phase 5
            // plan v2 §8.1). CALLBACK / WEBHOOK / VERIFICATION are process
            // stages, never statuses, and are deliberately absent.
            $table->enum('status', [
                'not_started',
                'hold_requested',
                'hold_active',
                'hold_failed',
                'capture_requested',
                'captured',
                'capture_failed',
                'final_settlement_requested',
                'settled',
                'settlement_failed',
                'cancelled',
                'expired',
                'refund_requested',
                'refunded',
                'refund_failed',
            ])->default('not_started');

            // No currency value is chosen and no default is set (Phase 5 plan
            // C1 — genuinely unresolved). CHAR(3) for an ISO-4217 code.
            $table->char('currency', 3)->nullable();

            // The deposit/hold amount. DECIMAL(12,2); no FX, no fees, no tax
            // (all deferred). Nullable because it is only set once the hold
            // is actually initiated in a later sub-phase.
            $table->decimal('amount', 12, 2)->nullable();

            $table->timestamp('hold_expires_at')->nullable();

            // Provider-issued reference/token ONLY — never card data, CVV, PIN
            // or any payment credential (Phase 0 §17, R38-R42).
            $table->string('provider_customer_ref')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index(['hotel_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 9 — the checkout process record for one reservation (Phase 0
     * §12 Checkout State Machine, R20/R26-R31). One checkout per reservation.
     *
     * It holds the checkout lifecycle status, the authoritative folio
     * snapshot taken at checkout time, the link to the final-settlement
     * PaymentTransaction (when a settlement was required), and the checkout
     * timestamps. It is NOT a payment record and never stores card / provider
     * secret data.
     *
     * MariaDB 10.4-compatible: enum + plain indexes only, DECIMAL money.
     */
    public function up(): void
    {
        Schema::create('checkouts', function (Blueprint $table) {
            $table->id();

            // One checkout per reservation. Financial/operational history —
            // deleting the reservation is blocked, never cascaded.
            $table->foreignId('reservation_id')->unique()->constrained()->restrictOnDelete();

            // Denormalized from the reservation for hotel-scoped
            // listing/reporting. Always set from the reservation, never a
            // client value.
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();

            // Checkout lifecycle (Phase 9A). Transition rules live only in
            // CheckoutStateMachine.
            //   in_progress        -> awaiting_settlement | settlement_failed | completed
            //   awaiting_settlement-> in_progress | settlement_failed | completed
            //   settlement_failed  -> in_progress | awaiting_settlement | completed
            //   completed          -> (terminal)
            $table->enum('status', [
                'in_progress',
                'awaiting_settlement',
                'settlement_failed',
                'completed',
            ])->default('in_progress');

            // Authoritative folio snapshot at the latest checkout attempt
            // (from FolioService — the same semantics Phase 8 established).
            $table->decimal('charges_total', 12, 2)->default(0);
            $table->decimal('payments_total', 12, 2)->default(0);
            $table->decimal('outstanding_total', 12, 2)->default(0);

            // ISO-4217 alpha code. Null until a settlement (which needs one)
            // is required — never defaulted.
            $table->char('currency', 3)->nullable();

            // The final-settlement PaymentTransaction, when a settlement was
            // required. Null for a zero/credit-balance checkout. Nulled if
            // the transaction row is somehow removed (it never is).
            $table->foreignId('settlement_transaction_id')->nullable()
                ->constrained('payment_transactions')->nullOnDelete();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Staff member who performed the checkout; NULL for a
            // system-originated one.
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            // reservation_id / hotel_id already indexed by FK / unique.
            $table->index(['hotel_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkouts');
    }
};

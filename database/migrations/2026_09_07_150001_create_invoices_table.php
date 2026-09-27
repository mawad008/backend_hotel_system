<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 9 — the final invoice for one reservation (Phase 0 §6.4
     * `invoices`, §12, R20 "… Auto Checkout → E-Invoice").
     *
     * Exactly one final invoice per reservation (unique `reservation_id`).
     * Totals are copied from the authoritative final folio at checkout time —
     * never from the client. No tax / discount / fee fields: none are defined
     * by the approved requirements and none are invented (Phase 9 scope).
     *
     * MariaDB 10.4-compatible: enum + plain indexes only, DECIMAL money.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('reservation_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();

            // Technical format `INV-<zero-padded id>` (no approved legal
            // numbering scheme exists — see the Phase 9 report). Unique at the
            // database level; set immediately after insert from the row id, so
            // it is deterministic and collision-free under concurrency.
            $table->string('invoice_number')->nullable()->unique();

            // Minimal lifecycle (Phase 0 §12): a draft is created during
            // checkout and only becomes `issued` once checkout finalizes
            // successfully. No void/cancel states — none are approved.
            $table->enum('status', ['draft', 'issued'])->default('draft');

            $table->char('currency', 3)->nullable();

            // Copied from the final folio: subtotal = charges_total.
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('payments_total', 12, 2)->default(0);
            $table->decimal('outstanding_total', 12, 2)->default(0);

            $table->timestamp('issued_at')->nullable();

            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['hotel_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};

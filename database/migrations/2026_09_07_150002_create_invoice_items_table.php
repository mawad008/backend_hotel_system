<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 9 — a frozen line of an invoice, snapshotted from the
     * authoritative folio at checkout time so a later folio-charge change
     * never rewrites invoice history.
     *
     * Phase 9 only has one folio source (Phase 8 `folio_charges`, posted
     * only). `source_type` = `folio_charge`, `source_id` = the folio charge
     * id. No tax / discount / fee columns — none are approved.
     *
     * MariaDB 10.4-compatible.
     */
    public function up(): void
    {
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();

            // A pure child of the invoice. Restricted delete keeps parity
            // with the financial-record posture used across the project;
            // invoices are never deleted.
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();

            $table->enum('source_type', ['folio_charge']);
            $table->unsignedBigInteger('source_id')->nullable();

            $table->string('description', 500);
            $table->unsignedInteger('quantity');
            $table->decimal('unit_amount', 12, 2);
            $table->decimal('total_amount', 12, 2);

            $table->timestamps();

            // One item per (invoice, source) — the snapshot is deterministic
            // and cannot be duplicated on a checkout retry.
            $table->unique(['invoice_id', 'source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};

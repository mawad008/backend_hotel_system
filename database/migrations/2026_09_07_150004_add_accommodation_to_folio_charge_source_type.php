<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Phase 9 (review fix) — the Phase 8 `folio_charges.source_type` enum
     * anticipated this ("the checkout phase will add its own source(s) via a
     * follow-up migration"). Checkout posts the reservation accommodation
     * charge (amount = `reservation.price_snapshot`, deterministic
     * `source_id = reservation.id`) so it participates in `charges_total`,
     * the invoice subtotal, `outstanding_total`, and appears as an invoice
     * item — never duplicated (the existing `(source_type, source_id)` UNIQUE).
     *
     * Raw ALTER (mirrors the raw CHECK constraint in the room_types
     * migration) — MariaDB 10.4-compatible, no dbal enum mangling.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE folio_charges MODIFY source_type ENUM('service_order', 'accommodation') NOT NULL");
    }

    public function down(): void
    {
        // Remove any accommodation charges before shrinking the enum back.
        DB::table('folio_charges')->where('source_type', 'accommodation')->delete();

        DB::statement("ALTER TABLE folio_charges MODIFY source_type ENUM('service_order') NOT NULL");
    }
};

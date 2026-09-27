<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Extend Stay — a second follow-up to the Phase 8 `folio_charges.source_type`
     * enum (the first added `accommodation`; see
     * 2026_09_07_150004_add_accommodation_to_folio_charge_source_type.php).
     * Each stay extension posts its own charge (amount = `nights_added *
     * room_types.base_price`, `source_id` = the `reservation_extensions` row
     * id) so it participates in `charges_total`, `outstanding_total` and the
     * invoice exactly like every other folio charge — never duplicated (the
     * existing `(source_type, source_id)` UNIQUE).
     *
     * Raw ALTER, same MariaDB 10.4-compatible approach as the accommodation
     * follow-up.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE folio_charges MODIFY source_type ENUM('service_order', 'accommodation', 'stay_extension') NOT NULL");
    }

    public function down(): void
    {
        // Remove any stay-extension charges before shrinking the enum back.
        DB::table('folio_charges')->where('source_type', 'stay_extension')->delete();

        DB::statement("ALTER TABLE folio_charges MODIFY source_type ENUM('service_order', 'accommodation') NOT NULL");
    }
};

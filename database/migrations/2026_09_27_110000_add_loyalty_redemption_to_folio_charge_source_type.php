<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Loyalty redemption becomes a real booking discount: a negative
     * `loyalty_redemption` folio line (`source_id` = reservation id, one per
     * booking via the existing `(source_type, source_id)` UNIQUE), capped at
     * the accommodation total so points never discount services (R59).
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE folio_charges MODIFY source_type ENUM('service_order', 'accommodation', 'stay_extension', 'loyalty_redemption') NOT NULL");
    }

    public function down(): void
    {
        DB::table('folio_charges')->where('source_type', 'loyalty_redemption')->delete();

        DB::statement("ALTER TABLE folio_charges MODIFY source_type ENUM('service_order', 'accommodation', 'stay_extension') NOT NULL");
    }
};

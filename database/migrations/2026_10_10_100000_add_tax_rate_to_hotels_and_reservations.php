<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-hotel tax rate ("نسبة الضريبة"), dashboard-managed:
 *
 * - hotels.tax_rate — percentage added on top of the stay price + service
 *   fee when the hotel's rates do NOT already include taxes
 *   (`prices_include_taxes = false`). Null / 0 = no tax line.
 * - reservations.tax_rate — the effective rate snapshotted at booking time
 *   (0 when rates include taxes), so a later dashboard change never
 *   re-prices an existing booking. The amount is derived from the current
 *   stay price, so extensions / early departure are taxed correctly; it is
 *   billed as its own `tax` folio line at checkout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->decimal('tax_rate', 5, 2)->nullable()->after('prices_include_taxes');
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->decimal('tax_rate', 5, 2)->default(0)->after('service_fee_amount');
        });

        DB::statement("ALTER TABLE folio_charges MODIFY source_type ENUM('service_order', 'accommodation', 'stay_extension', 'loyalty_redemption', 'service_fee', 'tax') NOT NULL");
    }

    public function down(): void
    {
        DB::table('folio_charges')->where('source_type', 'tax')->delete();
        DB::statement("ALTER TABLE folio_charges MODIFY source_type ENUM('service_order', 'accommodation', 'stay_extension', 'loyalty_redemption', 'service_fee') NOT NULL");

        Schema::table('reservations', fn (Blueprint $table) => $table->dropColumn('tax_rate'));
        Schema::table('hotels', fn (Blueprint $table) => $table->dropColumn('tax_rate'));
    }
};

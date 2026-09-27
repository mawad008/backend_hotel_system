<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Room Detail + booking pricing content (Figma v2 `ROOM_Detail_Premium` /
 * `BOOKING_Summary`), all dashboard-managed:
 *
 * - room_types.tag_i18n        — optional badge ("غرفة مميزة"), {en, ar}.
 * - room_types.inclusions_i18n — what the rate includes ("السعر يشمل").
 * - hotels.prices_include_taxes — displayed rates already include taxes.
 * - hotels.service_fee_*       — the hotel's booking service fee ("رسوم
 *   الخدمة"): off by default; when on, a fixed amount per booking or a
 *   percentage of the stay price.
 * - reservations.service_fee_amount — the fee snapshotted at booking time
 *   (a later dashboard change never re-prices an existing booking); billed
 *   as its own `service_fee` folio line at checkout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->json('tag_i18n')->nullable()->after('view_i18n');
            $table->json('inclusions_i18n')->nullable()->after('custom_specs');
        });

        Schema::table('hotels', function (Blueprint $table) {
            $table->boolean('prices_include_taxes')->default(false)->after('deposit_percentage');
            $table->boolean('service_fee_enabled')->default(false)->after('prices_include_taxes');
            $table->string('service_fee_type', 16)->nullable()->after('service_fee_enabled');
            $table->decimal('service_fee_value', 10, 2)->nullable()->after('service_fee_type');
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->decimal('service_fee_amount', 10, 2)->default(0)->after('price_snapshot');
        });

        DB::statement("ALTER TABLE folio_charges MODIFY source_type ENUM('service_order', 'accommodation', 'stay_extension', 'loyalty_redemption', 'service_fee') NOT NULL");
    }

    public function down(): void
    {
        DB::table('folio_charges')->where('source_type', 'service_fee')->delete();
        DB::statement("ALTER TABLE folio_charges MODIFY source_type ENUM('service_order', 'accommodation', 'stay_extension', 'loyalty_redemption') NOT NULL");

        Schema::table('reservations', fn (Blueprint $table) => $table->dropColumn('service_fee_amount'));
        Schema::table('hotels', fn (Blueprint $table) => $table->dropColumn([
            'prices_include_taxes', 'service_fee_enabled', 'service_fee_type', 'service_fee_value',
        ]));
        Schema::table('room_types', fn (Blueprint $table) => $table->dropColumn(['tag_i18n', 'inclusions_i18n']));
    }
};

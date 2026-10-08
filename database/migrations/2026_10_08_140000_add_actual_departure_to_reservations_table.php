<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Early departure — a guest may check out before the booked check-out date.
 *
 * - `checked_out_at`: the moment the guest actually left (checkout started).
 * - `original_check_out` / `original_price_snapshot`: what was booked, kept
 *   when checkout shortens `check_out` / `price_snapshot` to the nights
 *   actually stayed. NULL when the stay ran its full length.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->timestamp('checked_out_at')->nullable()->after('check_out');
            $table->date('original_check_out')->nullable()->after('checked_out_at');
            $table->decimal('original_price_snapshot', 10, 2)->nullable()->after('price_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn(['checked_out_at', 'original_check_out', 'original_price_snapshot']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Party size on a reservation. The guest booking flow collects adults +
 * children (Figma stay-dates / guests picker) and the availability preview
 * already greys a room type out when `capacity < adults + children`, so the
 * booking record needs to carry the party it was made for.
 *
 * Defaults (`adults` 1, `children` 0) keep every existing row and the staff
 * create path — which does not send these — valid and unchanged.
 * ReservationService persists them via its existing `$data` passthrough; no
 * service signature changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->unsignedTinyInteger('adults')->default(1)->after('check_out');
            $table->unsignedTinyInteger('children')->default(0)->after('adults');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn(['adults', 'children']);
        });
    }
};

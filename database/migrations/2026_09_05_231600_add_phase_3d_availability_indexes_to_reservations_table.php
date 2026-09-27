<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Supports EloquentReservationRepository::countOverlappingForRoom()/
     * countOverlappingForRoomType() (approved Phase 3D availability
     * check). `status` is deliberately NOT part of either index: 9 of the
     * 10 possible statuses are blocking (Reservation::BLOCKING_STATUSES),
     * so a status prefix/suffix would filter out only ~10% of rows and
     * isn't worth the extra index width — the equality column
     * (room_id/room_type_id) is what actually narrows the scan, with the
     * date columns supporting the range predicate on top of it.
     */
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->index(['room_id', 'check_in', 'check_out'], 'reservations_room_id_check_in_check_out_index');
            $table->index(['room_type_id', 'check_in', 'check_out'], 'reservations_room_type_id_check_in_check_out_index');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropIndex('reservations_room_id_check_in_check_out_index');
            $table->dropIndex('reservations_room_type_id_check_in_check_out_index');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Abandoned-booking expiry + duplicate-create protection.
 *
 * - `completion_deadline_at`: until when a PENDING / DEPOSIT_HELD booking
 *   may stay unfinished (set on create and again when the deposit is held,
 *   cleared once it is VERIFIED). Past it, `reservations:expire-abandoned`
 *   cancels it, releases the room and the hold.
 * - `idempotency_key`: the guest app's Idempotency-Key for the create call;
 *   unique per guest, so a retried / double-tapped Confirm returns the same
 *   reservation instead of a second one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->timestamp('completion_deadline_at')->nullable()->after('status');
            $table->string('idempotency_key', 255)->nullable()->after('created_by_staff_id');

            $table->index(['status', 'completion_deadline_at']);
            $table->unique(['guest_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropUnique(['guest_id', 'idempotency_key']);
            $table->dropIndex(['status', 'completion_deadline_at']);
            $table->dropColumn(['completion_deadline_at', 'idempotency_key']);
        });
    }
};

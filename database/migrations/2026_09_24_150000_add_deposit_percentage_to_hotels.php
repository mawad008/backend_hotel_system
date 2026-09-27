<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-hotel pre-booking deposit (التأمين). Set on the dashboard hotel form
 * as a percentage of the booked room price: a 100 SAR booking at 10% holds
 * a 10 SAR deposit. GuestPaymentController reads it instead of the old
 * app-wide `guest_booking.deposit.percentage` placeholder.
 *
 * Existing hotels are backfilled with that previous app-wide value (20%) so their
 * guest deposit flow keeps working unchanged until an admin edits them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->decimal('deposit_percentage', 5, 2)->nullable()->after('star_rating');
        });

        DB::table('hotels')->update([
            'deposit_percentage' => 20,
        ]);
    }

    public function down(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->dropColumn('deposit_percentage');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Launch business rules (approved 2026-09-26):
 *
 * - Currency: every reservation and payment transaction snapshots its
 *   currency permanently; history never follows a later platform change.
 * - Cancellation: each reservation snapshots whether its room/rate was
 *   refundable and until when it can be cancelled for a full refund.
 * - Room assignment: who assigned the room, and when.
 * - Check-in mode per hotel (self / reception / both).
 * - Guest identity-image retention preference (default: delete after
 *   checkout; the hotel's stay copy is purged after the retention window).
 * - Loyalty maximum redemption (launch: program disabled, all zero).
 * - Deposit percentage never NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        $platformCurrency = strtoupper((string) (config('payment.currency') ?: 'SAR'));

        Schema::table('reservations', function (Blueprint $table) {
            $table->char('currency', 3)->nullable()->after('price_snapshot');
            $table->boolean('is_refundable')->default(true)->after('currency');
            $table->timestamp('free_cancellation_until')->nullable()->after('is_refundable');
            $table->foreignId('room_assigned_by_user_id')->nullable()->after('room_id')->constrained('users')->nullOnDelete();
            $table->timestamp('room_assigned_at')->nullable()->after('room_assigned_by_user_id');
        });

        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->char('currency', 3)->nullable()->after('amount');
        });

        Schema::table('hotels', function (Blueprint $table) {
            $table->string('check_in_mode', 16)->default('both')->after('check_out_time');
        });

        Schema::table('guests', function (Blueprint $table) {
            $table->string('identity_retention', 32)->default('delete_after_checkout')->after('preferences');
        });

        Schema::table('loyalty_rules', function (Blueprint $table) {
            $table->unsignedInteger('max_redeem_points')->default(0)->after('redeem_currency_per_point');
        });

        // ── Backfills ────────────────────────────────────────────────────
        // Payments without a currency take the platform currency; then each
        // reservation snapshots its payment's currency (else the platform's).
        DB::table('payments')->whereNull('currency')->update(['currency' => $platformCurrency]);
        DB::table('reservations')->update([
            'currency' => DB::raw("COALESCE((select p.currency from payments p where p.reservation_id = reservations.id limit 1), '{$platformCurrency}')"),
        ]);
        DB::table('payment_transactions')->update([
            'currency' => DB::raw("COALESCE((select p.currency from payments p where p.id = payment_transactions.payment_id), '{$platformCurrency}')"),
        ]);

        // Refundability follows the booked room type; the free window is
        // 24h from booking, capped at the check-in day.
        DB::table('reservations')->update([
            'is_refundable' => DB::raw('COALESCE((select rt.refundable from room_types rt where rt.id = reservations.room_type_id), 1)'),
        ]);
        DB::table('reservations')->where('is_refundable', true)->update([
            'free_cancellation_until' => DB::raw('LEAST(DATE_ADD(created_at, INTERVAL 24 HOUR), check_in)'),
        ]);

        // The deposit backfill value is the one the per-hotel deposit
        // migration (2026_09_24_150000) already used for existing hotels.
        DB::table('hotels')->whereNull('deposit_percentage')->update(['deposit_percentage' => 20]);

        // Launch: loyalty exists but is disabled with zero rates.
        DB::table('loyalty_rules')->update([
            'is_active' => false,
            'earn_points_per_currency' => DB::raw('COALESCE(earn_points_per_currency, 0)'),
            'redeem_currency_per_point' => DB::raw('COALESCE(redeem_currency_per_point, 0)'),
            'max_redeem_points' => 0,
        ]);
    }

    public function down(): void
    {
        Schema::table('loyalty_rules', fn (Blueprint $table) => $table->dropColumn('max_redeem_points'));
        Schema::table('guests', fn (Blueprint $table) => $table->dropColumn('identity_retention'));
        Schema::table('hotels', fn (Blueprint $table) => $table->dropColumn('check_in_mode'));
        Schema::table('payment_transactions', fn (Blueprint $table) => $table->dropColumn('currency'));
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('room_assigned_by_user_id');
            $table->dropColumn(['currency', 'is_refundable', 'free_cancellation_until', 'room_assigned_at']);
        });
    }
};

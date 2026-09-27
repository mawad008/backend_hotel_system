<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 9 (review fix) — record the money amount of each provider
     * operation on its append-only PaymentTransaction row.
     *
     * A `capture` transaction records the deposit amount that was captured;
     * a `settlement` transaction records the outstanding balance collected at
     * checkout. `payments_total` on the folio is then the SUM of these
     * succeeded amounts — the payment history is preserved and never
     * overwritten (`payments.amount` keeps its Phase 5 meaning: the deposit
     * requested at booking).
     *
     * Nullable — a `hold` / `verify` / `cancel_hold` attempt carries no
     * collected amount, and rows created before this migration have none.
     * DECIMAL(12,2), MariaDB 10.4-compatible.
     */
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->decimal('amount', 12, 2)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropColumn('amount');
        });
    }
};

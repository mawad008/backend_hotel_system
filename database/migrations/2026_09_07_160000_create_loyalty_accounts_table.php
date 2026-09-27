<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 10 — the loyalty account for one Guest (Phase 0 §6.4
     * `loyalty_accounts`, §13, R43). Exactly one per Guest; group-wide, NOT
     * per-hotel (a Guest keeps one balance across every hotel they stay at).
     *
     * `points_balance` is a denormalised cache — the `loyalty_transactions`
     * ledger is the single source of truth (Phase 0 guardrail #8). Every
     * mutation locks this row, appends a ledger entry, and adjusts the cache
     * in the same transaction; `recomputeBalance()` re-derives it from the
     * ledger.
     *
     * MariaDB 10.4-compatible: integer points (points are counts, not
     * money — never DECIMAL/FLOAT here), plain indexes only.
     */
    public function up(): void
    {
        Schema::create('loyalty_accounts', function (Blueprint $table) {
            $table->id();

            // One account per Guest. A Guest with loyalty history cannot be
            // hard-deleted — blocked, never cascaded.
            $table->foreignId('guest_id')->unique()->constrained()->restrictOnDelete();

            // Signed cache of SUM(loyalty_transactions.points). A redeem can
            // never take it below zero (enforced in the service); a future
            // reverse/adjust could, so the column itself is signed.
            $table->bigInteger('points_balance')->default(0);

            // Reserved for a future "close account" capability — no MVP path
            // sets it false.
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_accounts');
    }
};

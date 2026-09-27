<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 10 — the append-only loyalty ledger (Phase 0 §6.4
     * `loyalty_transactions`, §13, R43). The single source of truth for a
     * guest's balance.
     *
     * Types (§13): `earn`, `redeem`, `reverse`, `adjust`, `expire`.
     * MVP implements `earn` (a completed booking) and `redeem` (against an
     * eligible booking). `reverse` / `adjust` are reserved staff-correction
     * types with no MVP endpoint; `expire` is reserved and NEVER written
     * (R51: "no loyalty point expiration in MVP").
     *
     * `points` is a signed delta (earn > 0, redeem < 0). Append-only — no
     * `updated_at`, mirroring `audit_logs`.
     *
     * MariaDB 10.4-compatible: enum + plain indexes only, integer points.
     */
    public function up(): void
    {
        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('loyalty_account_id')->constrained()->restrictOnDelete();

            $table->enum('type', ['earn', 'redeem', 'reverse', 'adjust', 'expire']);

            // Signed point delta applied to the account balance.
            $table->bigInteger('points');

            // What the entry is tied to. Only `reservation` in the MVP;
            // nullable for a source-less `adjust`.
            $table->enum('source_type', ['reservation'])->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            // For a future `reverse` — the ledger entry it undoes.
            $table->foreignId('reverses_transaction_id')->nullable()
                ->constrained('loyalty_transactions')->restrictOnDelete();

            $table->string('description', 500)->nullable();

            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // Safe, non-sensitive context only (e.g. the earn base amount,
            // the notional redeemed value).
            $table->json('metadata')->nullable();

            $table->timestamp('created_at')->useCurrent();

            // One `earn` and one `redeem` per (account, source) — the
            // idempotency guard for accrual and redemption against a booking.
            // Many NULL-source `adjust`/`reverse` rows are allowed (NULLs are
            // distinct in a MariaDB unique index).
            $table->unique(['loyalty_account_id', 'type', 'source_type', 'source_id'], 'loyalty_txn_account_source_unique');

            $table->index(['loyalty_account_id', 'id'], 'loyalty_txn_account_ledger_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_transactions');
    }
};

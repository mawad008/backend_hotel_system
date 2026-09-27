<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 10 — the configurable loyalty economics for one Hotel Group
     * (Phase 0 §6.4 `loyalty_rules`, §13, R43).
     *
     * NOTHING is seeded and NO value is defaulted (Phase 0 §13: "no values
     * invented; must be populated by an admin before go-live"). Loyalty is
     * OFF for a group until a Group Owner activates a rule with an earn rate.
     * No tiers, no multi-rule stacking (§13) — exactly one row per group.
     *
     * MariaDB 10.4-compatible.
     */
    public function up(): void
    {
        Schema::create('loyalty_rules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('hotel_group_id')->unique()->constrained()->restrictOnDelete();

            // Loyalty accrual/redemption only happens while this is true AND
            // the relevant rate is set. Default false — explicitly activated.
            $table->boolean('is_active')->default(false);

            // Points earned per 1.00 of eligible booking value. Nullable and
            // never defaulted — a genuinely unresolved business value
            // (Phase 0 §20 item 6).
            $table->decimal('earn_points_per_currency', 12, 4)->nullable();

            // Monetary value of one point when redeemed. Nullable and never
            // defaulted (Phase 0 §20 item 6). The actual discount mechanism
            // is deferred (§13 "no folio/service redemption") — Phase 10
            // records the ledger movement + this notional value only.
            $table->decimal('redeem_currency_per_point', 12, 4)->nullable();

            // The structural set of earn sources the group recognises. The
            // only implemented source is `reservation`; this is a shape, not
            // a business rate.
            $table->json('eligible_source_types')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_rules');
    }
};

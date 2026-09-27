<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5A — one row per provider operation attempt against a Payment
     * (Phase 0 §6.4, Phase 5 plan v2 §7.2). Retry granularity + the place a
     * provider reference / idempotency key is stored. No workflow logic.
     */
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();

            // Child of a financial record — restricted, never cascaded.
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();

            // The approved operation set (Phase 5 plan v2 §7.2 / §10).
            // capture / settlement / refund are reserved for later phases;
            // the value set is fixed here so the schema is complete.
            $table->enum('type', [
                'hold',
                'cancel_hold',
                'capture',
                'settlement',
                'refund',
                'verify',
            ]);

            $table->enum('status', [
                'pending',
                'succeeded',
                'failed',
                'cancelled',
                'expired',
            ])->default('pending');

            // First-class idempotency (Phase 5 plan v2 §12): every attempt
            // carries a globally-unique key so a retried request returns the
            // stored result instead of re-calling the provider.
            $table->string('idempotency_key')->unique();

            $table->string('provider');

            // Provider transaction reference. Nullable (not known until the
            // provider responds). Unique per (provider, provider_reference) —
            // a NULL leaves the tuple non-unique, so many un-referenced rows
            // per provider are allowed, which is the intended behaviour.
            $table->string('provider_reference')->nullable();

            // Staff member who triggered the attempt; NULL for a
            // system/provider-originated operation (webhook, reconciliation).
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Safe normalized metadata ONLY — never card number, CVV, PIN,
            // expiry, cardholder, or any credential/secret (Phase 0 §17).
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->unique(['provider', 'provider_reference']);
            $table->index(['payment_id', 'type']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};

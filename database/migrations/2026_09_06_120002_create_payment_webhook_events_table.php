<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5A — the normalized inbound webhook/callback boundary (Phase 0
     * §9/§16, Phase 5 plan v2 §7.3). Stores only a sanitized normalized
     * representation + a hash of the raw body — never the raw provider
     * request body. No controller, no processing logic in this phase.
     */
    public function up(): void
    {
        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();

            $table->string('provider');

            // Primary deduplication key WHEN PRESENT. Nullable, because not
            // every provider event carries one. The unique index below
            // treats (provider, NULL) tuples as distinct (MariaDB/MySQL
            // semantics), so it enforces uniqueness only when an id exists.
            $table->string('provider_event_id')->nullable();

            // sha256 hex of the raw body. FALLBACK dedup input only (used
            // when provider_event_id is absent) — carries a NON-unique index,
            // never a universal unique constraint: two legitimately distinct
            // events may share an identical payload.
            $table->char('payload_hash', 64);

            // Normalized event type. Nullable: an event that fails signature
            // or parsing may never get one. The authoritative vocabulary is
            // defined by the normalizer in a later sub-phase.
            $table->string('event_type')->nullable();

            $table->enum('processing_status', [
                'received',
                'processed',
                'duplicate_ignored',
                'unmatched',
                'failed',
            ])->default('received');

            // An event may arrive before (or without) a matching local
            // Payment, so this is nullable.
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();

            $table->string('provider_reference')->nullable();

            // Sanitized normalized representation needed by the internal
            // workflow — NOT the raw provider body, NO credentials/secrets.
            $table->json('normalized_payload');

            $table->timestamp('processed_at')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();

            // Enforced only when provider_event_id is non-NULL.
            $table->unique(['provider', 'provider_event_id']);
            // Non-unique — supports the fallback dedup lookup.
            $table->index(['provider', 'payload_hash']);
            $table->index('provider_reference');
            $table->index('processing_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
    }
};

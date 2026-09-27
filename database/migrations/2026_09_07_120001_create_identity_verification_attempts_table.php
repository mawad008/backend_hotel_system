<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 6 — one row per verification attempt cycle
     * (Phase 0 §6.4: `verification_attempts`). Retry granularity + where the
     * private document/selfie references, the provider reference, and the
     * match idempotency key are stored. No raw provider payload, ever.
     */
    public function up(): void
    {
        Schema::create('identity_verification_attempts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('session_id')
                ->constrained('identity_verification_sessions')
                ->cascadeOnDelete();

            $table->unsignedInteger('attempt_number');

            $table->enum('status', [
                'document_uploaded',
                'selfie_captured',
                'matching_in_progress',
                'completed',
            ])->default('document_uploaded');

            $table->string('provider');

            // Provider match reference. Nullable — not known until the
            // provider responds. Unique per (provider, provider_reference):
            // a NULL leaves the tuple non-unique so many un-referenced rows
            // per provider are allowed, which is intended.
            $table->string('provider_reference')->nullable();

            // First-class idempotency for the match operation: a retried
            // selfie submission returns the stored result instead of
            // re-calling the provider.
            $table->string('idempotency_key')->unique();

            // A short, non-PII label (e.g. "passport"). Never a number.
            $table->string('document_type')->nullable();

            // PRIVATE-disk relative paths ONLY — never a URL, never a public
            // path, never the file bytes (Phase 0 §17, R38-R42).
            $table->string('document_path')->nullable();
            $table->string('selfie_path')->nullable();

            // Normalized match result: band label + provider-normalized
            // 0..100 score.
            $table->string('outcome')->nullable();
            $table->unsignedTinyInteger('score')->nullable();

            // Safe normalized metadata ONLY — never a document number, MRZ,
            // date of birth, image, or raw provider payload (Phase 0 §17).
            $table->json('metadata')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            // Explicit short index names — the auto-generated names exceed
            // MariaDB's 64-char identifier limit.
            $table->unique(['session_id', 'attempt_number'], 'idv_attempts_session_attempt_unique');
            $table->unique(['provider', 'provider_reference'], 'idv_attempts_provider_ref_unique');
            $table->index('status', 'idv_attempts_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_verification_attempts');
    }
};

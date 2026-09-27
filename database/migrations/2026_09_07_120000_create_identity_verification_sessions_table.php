<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 6 — the identity verification lifecycle record for one
     * Reservation (Phase 0 §6.1/§6.4/§10). MariaDB 10.4-compatible: enum +
     * plain indexes only, no MySQL-8-only features.
     */
    public function up(): void
    {
        Schema::create('identity_verification_sessions', function (Blueprint $table) {
            $table->id();

            // Approved 1:1 Reservation -> Verification session. Compliance
            // record — deleting the Reservation is blocked, never cascaded.
            $table->foreignId('reservation_id')->unique()->constrained()->restrictOnDelete();

            // The guest whose identity is being verified — referenced, never
            // duplicated (Phase 6 Guest-integration rule). Restricted delete.
            $table->foreignId('guest_id')->constrained()->restrictOnDelete();

            // Denormalized from the Reservation for efficient hotel-scoped
            // listing/reporting. Set from the Reservation's hotel, never a
            // client value. Restricted delete.
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();

            // The approved persistent session statuses (Phase 0 §10). The
            // transition rules live only in IdentityVerificationStateMachine.
            $table->enum('status', [
                'not_started',
                'document_uploaded',
                'selfie_captured',
                'matching_in_progress',
                'auto_approved',
                'pending_manual_review',
                'staff_approved',
                'staff_rejected',
                'retry_allowed',
            ])->default('not_started');

            $table->string('provider');

            // Count of completed match attempts. Drives the configurable
            // retry policy (config('verification.max_retries')) — the limit
            // itself is never stored here.
            $table->unsignedInteger('attempts')->default(0);

            // Safe, non-PII summary of the latest match: the band label and
            // the provider-normalized 0..100 score. A score is a confidence
            // number, not identity data.
            $table->string('latest_outcome')->nullable();
            $table->unsignedTinyInteger('latest_score')->nullable();

            $table->timestamp('decided_at')->nullable();

            $table->timestamps();

            // guest_id / hotel_id are already indexed by their foreign keys
            // (MariaDB auto-creates the referencing index). Only the query
            // patterns not covered by an FK index are added here.
            $table->index('status');
            $table->index(['hotel_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_verification_sessions');
    }
};

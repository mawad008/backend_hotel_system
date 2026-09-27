<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 6 — an append-only log of every automated and manual decision
     * on a verification session (Phase 0 §6.4: `verification_decisions`).
     * No updated_at: a row is written once and never changed.
     */
    public function up(): void
    {
        Schema::create('identity_verification_decisions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('session_id')
                ->constrained('identity_verification_sessions')
                ->cascadeOnDelete();

            $table->foreignId('attempt_id')
                ->nullable()
                ->constrained('identity_verification_attempts')
                ->nullOnDelete();

            $table->enum('type', ['automated', 'manual']);

            $table->enum('result', [
                'auto_approved',
                'manual_review_required',
                'retry_allowed',
                'retry_exhausted',
                'staff_approved',
                'staff_rejected',
            ]);

            // NULL for an automated decision; the acting staff member for a
            // manual one.
            $table->foreignId('decided_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->unsignedTinyInteger('score')->nullable();
            $table->enum('band', ['high', 'medium', 'low'])->nullable();

            // Optional short staff note. Not a place for PII by policy;
            // length-capped by the Form Request.
            $table->string('reason', 500)->nullable();

            // Append-only — created_at only.
            $table->timestamp('created_at')->nullable();

            // decided_by_user_id is already indexed by its foreign key.
            $table->index(['session_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_verification_decisions');
    }
};

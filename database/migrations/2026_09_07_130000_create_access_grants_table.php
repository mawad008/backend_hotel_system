<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 7 — the digital access lifecycle record for one Reservation
     * (Phase 0 §6.1/§6.4: `access_grants`; §11 Digital Access State Machine).
     * MariaDB 10.4-compatible: enum + plain indexes only, no MySQL-8-only
     * features.
     */
    public function up(): void
    {
        Schema::create('access_grants', function (Blueprint $table) {
            $table->id();

            // Approved 1:1 Reservation -> Access grant (§6.1). Access record —
            // deleting the Reservation is blocked, never cascaded.
            $table->foreignId('reservation_id')->unique()->constrained()->restrictOnDelete();

            // Denormalized from the Reservation for efficient hotel-scoped
            // listing/reporting. Set from the Reservation's hotel, never a
            // client value. Restricted delete.
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();

            // The guest the access is for — referenced, never duplicated.
            $table->foreignId('guest_id')->constrained()->restrictOnDelete();

            // The approved persistent statuses (Phase 0 §11 + the three
            // architecture-derived staged/failure nodes). Transition rules
            // live only in DigitalAccessStateMachine.
            $table->enum('status', [
                'not_issued',
                'issue_requested',
                'active',
                'failed',
                'revoke_requested',
                'revoked',
                'expired',
            ])->default('not_issued');

            // §11: pin_code now (app-delivered code); smart_lock is future,
            // same interface, no schema change needed to switch.
            $table->enum('access_mode', ['pin_code', 'smart_lock'])->default('pin_code');

            $table->string('provider');

            // Provider-issued reference/token ONLY — never the credential.
            // Unique per (provider, provider_reference); a NULL leaves the
            // tuple non-unique, which is the intended behaviour.
            $table->string('provider_reference')->nullable();

            // The app-delivered PIN (§11 pin_code). ENCRYPTED at rest via the
            // model's `encrypted` cast; NULL until the grant is ACTIVE and
            // nulled again the moment it is revoked or expires. Never written
            // to an audit row or a log line. TEXT because the ciphertext is
            // longer than the plaintext.
            $table->text('credential')->nullable();

            // First-class idempotency for the issue operation (part of
            // check-in): a retried request returns the stored grant instead
            // of re-calling the provider.
            $table->string('idempotency_key')->unique();

            $table->timestamp('issued_at')->nullable();
            $table->timestamp('activated_at')->nullable();

            // Derived from the reservation's check_out (end of day) at
            // activation — §11 "stay end reached, auto". No invented duration.
            $table->timestamp('expires_at')->nullable();

            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason', 500)->nullable();

            // Safe, fixed machine reason for a FAILED issuance — never a raw
            // provider error string.
            $table->string('failure_reason', 500)->nullable();

            // Safe normalized provider context ONLY — never the credential,
            // a secret, or a raw provider payload (Phase 0 §17).
            $table->json('metadata')->nullable();

            $table->timestamps();

            // guest_id / hotel_id / reservation_id are already indexed by
            // their foreign keys / the unique constraint. Only the query
            // patterns not covered by an FK index are added here.
            $table->unique(['provider', 'provider_reference'], 'access_grants_provider_ref_unique');
            $table->index('status', 'access_grants_status_index');
            $table->index(['hotel_id', 'status'], 'access_grants_hotel_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_grants');
    }
};

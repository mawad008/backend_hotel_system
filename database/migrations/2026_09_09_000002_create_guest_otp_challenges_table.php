<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 0 — one row per OTP challenge. The code is stored only as a hash
 * (never plaintext, never logged). `public_id` (uuid) is what the client
 * carries as `challenge_id` so the bigint PK is never enumerable. A challenge
 * is single-use: `consumed_at` is set the moment a correct code is verified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_otp_challenges', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('phone')->index();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts');
            $table->unsignedTinyInteger('resend_count')->default(0);
            // Nullable only to satisfy MariaDB strict-mode timestamp defaults;
            // both are always set by GuestAuthService on create.
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['phone', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_otp_challenges');
    }
};

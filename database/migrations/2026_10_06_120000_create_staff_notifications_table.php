<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The dashboard staff inbox — one in-app row per eligible staff user per
 * operational event. Separate from `notification_events` (the guest delivery
 * pipeline): staff rows have no provider / delivery lifecycle, and their text
 * is rendered by the dashboard from `type` + `context` so it follows the
 * viewer's current language.
 *
 * Idempotency: one row per (recipient, audit entry) — the event that
 * produced it can never notify the same user twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('audit_log_id')->constrained('audit_logs')->cascadeOnDelete();
            $table->string('type', 64);
            $table->json('context')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'audit_log_id'], 'staff_notifications_user_event_unique');
            $table->index(['user_id', 'read_at'], 'staff_notifications_user_unread_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_notifications');
    }
};

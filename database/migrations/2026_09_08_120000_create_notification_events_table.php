<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 11 — the notification record/history (Phase 0 §4 "Notifications"
     * bounded context; §15 "event logged internally to `notification_events`
     * … no external call").
     *
     * One row per (business event, recipient, channel). It answers every
     * question the phase requires: who the recipient is, what hotel/group
     * context it belongs to, what event/type produced it, what channel was
     * requested, the delivery status, the provider reference returned, when
     * it was sent, why it failed, and the idempotency key that makes the
     * whole thing safe to retry.
     *
     * The table is named `notification_events` (the Phase 0 §15 name) rather
     * than `notifications` so it never collides with Laravel's own optional
     * database-notifications table used by the `Notifiable` trait.
     *
     * NOT stored: any recipient contact value (email/phone) — the address is
     * resolved from the `guests` row at send time, handed to the provider,
     * and never persisted here (Phase 0 §17 "avoid storing sensitive
     * information unnecessarily"). No secret, credential, or raw provider
     * payload is ever written to `context`.
     *
     * MariaDB 10.4-compatible: enum + plain indexes only, no MySQL-8-only
     * features.
     */
    public function up(): void
    {
        Schema::create('notification_events', function (Blueprint $table) {
            $table->id();

            // The business entity that caused the notification. Nullable so a
            // future non-reservation notification (e.g. a staff digest) fits
            // the same table without a schema change. Restricted delete — a
            // notification is history.
            $table->foreignId('reservation_id')->nullable()->constrained()->restrictOnDelete();

            // Denormalized hotel context for hotel-scoped listing / reporting.
            // Always derived server-side from the reservation, never a client
            // value. Nullable for a future group-level notification.
            $table->foreignId('hotel_id')->nullable()->constrained()->restrictOnDelete();

            // The recipient, addressed generically so a future staff-user
            // recipient reuses this table (the loyalty reserved-enum-value
            // precedent). Only `guest` is produced this phase.
            $table->enum('recipient_type', ['guest', 'staff_user']);
            $table->unsignedBigInteger('recipient_id');

            // The approved workflow milestone that produced this notification
            // (Phase 0 R20 guest journey + R33 cancellation edge case). No
            // business event is invented — every value maps to an existing
            // Reservation state transition.
            $table->enum('type', [
                'reservation_deposit_held',
                'identity_verified',
                'reservation_checked_in',
                'reservation_invoiced',
                'reservation_cancelled',
            ]);

            // Logical delivery channel (Phase 0 §15). `in_app` is the
            // reservation notification feed; `email` / `sms` are simulated by
            // the dummy provider (no external call this phase).
            $table->enum('channel', ['in_app', 'email', 'sms']);

            // Delivery lifecycle. Transition rules live only in
            // NotificationDeliveryStateMachine.
            $table->enum('status', ['pending', 'sending', 'sent', 'failed'])->default('pending');

            // The rendered, locale-resolved message — snapshotted so history
            // stays stable even if a template later changes.
            $table->string('locale', 8);
            $table->string('subject');
            $table->text('body');

            // Provider boundary bookkeeping. `provider` is the adapter name
            // ("dummy" this phase); the reference/code are the normalized,
            // non-sensitive values the provider returns.
            $table->string('provider');
            $table->string('provider_reference')->nullable();
            $table->string('provider_code')->nullable();

            // Fixed, safe machine reason for a FAILED delivery — never a raw
            // provider error string.
            $table->string('failure_reason', 500)->nullable();

            // Deterministic idempotency guard: one row per
            // (business event, recipient, channel). A repeated event or a
            // concurrent double both collide here.
            $table->string('idempotency_key')->unique();

            // Safe, non-sensitive context only (from/to reservation status,
            // reservation reference). Never a secret or a provider payload.
            $table->json('context')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            // `in_app` read state (null = unread). Only meaningful for the
            // in_app channel; email/sms rows keep it null.
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            // The notification-feed query: a reservation's rows for one
            // channel, newest first.
            $table->index(['reservation_id', 'channel', 'id'], 'notification_events_reservation_channel_index');
            // The unread-count / unread-filter query.
            $table->index(['reservation_id', 'channel', 'read_at'], 'notification_events_reservation_unread_index');
            // Recipient-centric lookups (future staff feed).
            $table->index(['recipient_type', 'recipient_id'], 'notification_events_recipient_index');
            // Ops / future delivery-retry sweeps.
            $table->index(['hotel_id', 'status'], 'notification_events_hotel_status_index');
            $table->index('status', 'notification_events_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_events');
    }
};

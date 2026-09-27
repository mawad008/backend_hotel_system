<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Guest-facing post-stay review — one per completed/stayed reservation
     * (mobile/docs/mobile-phase-10-loyalty-reviews.md "Review domain"):
     * numeric rating 1-5, optional free text, moderation state
     * pending -> published | rejected. Reservation-scoped, so `hotel_id` is
     * denormalised from the reservation for cheap staff-side hotel-scoped
     * listing/moderation without a join.
     *
     * MariaDB 10.4-compatible: plain indexes only.
     */
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();

            // One review per reservation — a completed stay cannot be
            // reviewed twice. A reservation with a review cannot be
            // hard-deleted — blocked, never cascaded.
            $table->foreignId('reservation_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('guest_id')->constrained()->restrictOnDelete();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();

            $table->unsignedTinyInteger('rating');
            $table->text('text')->nullable();

            $table->string('status')->default('pending');
            $table->foreignId('moderated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();

            $table->timestamps();

            $table->index(['hotel_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A guest's review of one fulfilled service order — the service-level
     * analogue of `reviews` (hotel-level). Deliberately a separate table:
     * a service review must never affect a hotel's overall rating, and a
     * hotel review must never affect a service's own rating (see
     * ServiceReviewService).
     *
     * One review per service order — enforced by the
     * `service_reviews.service_order_id` UNIQUE constraint, not just the
     * service layer. `hotel_id` / `service_id` are denormalised from the
     * order for cheap staff-side hotel/service-scoped listing without a
     * join, exactly mirroring `reviews.hotel_id`.
     *
     * MariaDB 10.4-compatible: plain indexes only.
     */
    public function up(): void
    {
        Schema::create('service_reviews', function (Blueprint $table) {
            $table->id();

            $table->foreignId('service_order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('guest_id')->constrained()->restrictOnDelete();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_id')->constrained('hotel_services')->restrictOnDelete();

            $table->unsignedTinyInteger('rating');
            $table->text('text')->nullable();

            $table->string('status')->default('pending');
            $table->foreignId('moderated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();

            $table->timestamps();

            $table->index(['service_id', 'status']);
            $table->index(['hotel_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_reviews');
    }
};

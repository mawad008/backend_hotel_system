<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Guest-facing in-stay problem report (mobile/Design/13 · Report a
     * problem.png): a category + urgency + optional free-text note, tied to
     * the reservation the issue was reported during. `hotel_id` is
     * denormalised from the reservation for cheap staff-side hotel-scoped
     * listing without a join, mirroring the `reviews` table's shape.
     *
     * MariaDB 10.4-compatible: plain indexes only.
     */
    public function up(): void
    {
        Schema::create('problem_reports', function (Blueprint $table) {
            $table->id();

            $table->foreignId('reservation_id')->constrained()->restrictOnDelete();
            $table->foreignId('guest_id')->constrained()->restrictOnDelete();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();

            $table->string('category');
            $table->string('urgency');
            $table->text('notes')->nullable();

            $table->string('status')->default('open');
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index(['hotel_id', 'status']);
            $table->index(['reservation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('problem_reports');
    }
};

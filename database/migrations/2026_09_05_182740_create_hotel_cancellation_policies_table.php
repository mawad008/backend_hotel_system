<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_cancellation_policies', function (Blueprint $table) {
            $table->id();

            // Owned config row for the Hotel — matches the existing
            // hotel-owned-config convention (room_types/rooms cascade with
            // their Hotel). No uniqueness on hotel_id: Phase 0 §12
            // anticipates future tiered policies per hotel (e.g. by notice
            // window), so a Hotel may hold more than one policy row.
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('notice_period_hours');
            $table->enum('penalty_type', ['percentage', 'flat', 'none']);
            $table->decimal('penalty_value', 10, 2);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_cancellation_policies');
    }
};

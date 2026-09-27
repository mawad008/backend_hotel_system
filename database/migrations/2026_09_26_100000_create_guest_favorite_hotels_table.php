<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A guest's saved ("favourite") hotels — the heart on the Guest App's hotel
 * cards and Hotel Detail hero. One row per (guest, hotel); deleting either
 * side removes the favourite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_favorite_hotels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guest_id')->constrained('guests')->cascadeOnDelete();
            $table->foreignId('hotel_id')->constrained('hotels')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['guest_id', 'hotel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_favorite_hotels');
    }
};

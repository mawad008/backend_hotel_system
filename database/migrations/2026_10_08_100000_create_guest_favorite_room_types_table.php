<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A guest's saved ("favourite") rooms — the heart on the Guest App's Room
 * Detail hero. A guest-facing "room" is a room type. One row per
 * (guest, room type); deleting either side removes the favourite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_favorite_room_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guest_id')->constrained('guests')->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained('room_types')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['guest_id', 'room_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_favorite_room_types');
    }
};

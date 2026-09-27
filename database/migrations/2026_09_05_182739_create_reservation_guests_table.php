<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_guests', function (Blueprint $table) {
            $table->id();

            // A pure detail/assignment row of its Reservation — deleting the
            // Reservation removes its guest assignments with it.
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();

            // Same historical-integrity reasoning as reservations.guest_id:
            // a Guest referenced by an assignment row cannot be deleted out
            // from under it.
            $table->foreignId('guest_id')->constrained('guests')->restrictOnDelete();

            $table->boolean('is_primary')->default(false);

            $table->timestamps();

            $table->unique(['reservation_id', 'guest_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_guests');
    }
};

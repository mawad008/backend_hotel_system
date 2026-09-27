<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();

            // Hard historical/financial record — deleting the Hotel, Room
            // Type, Room, or Guest a Reservation references is blocked
            // rather than cascaded or nulled, so booking history (including
            // which physical Room was involved) can never be silently
            // destroyed (approved Hybrid model, §6.2 rules 2-3: room_type_id
            // is required, room_id is optional).
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('rooms')->restrictOnDelete();
            $table->foreignId('guest_id')->constrained('guests')->restrictOnDelete();

            $table->date('check_in');
            $table->date('check_out');

            $table->enum('status', [
                'pending',
                'deposit_held',
                'verified',
                'checked_in',
                'in_stay',
                'checkout_in_progress',
                'checkout_blocked',
                'checked_out',
                'invoiced',
                'cancelled',
            ])->default('pending');

            $table->decimal('price_snapshot', 10, 2);

            // Staff member who created the reservation on the guest's
            // behalf; nullable because a guest-initiated booking has none.
            // Mirrors audit_logs.actor_id's nullOnDelete() convention — the
            // reservation record survives if the staff account is removed.
            $table->foreignId('created_by_staff_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};

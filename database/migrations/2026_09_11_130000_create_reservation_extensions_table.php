<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extend Stay — the audit ledger for a reservation's checkout-date
     * extensions (new checkout date, nights added, the authoritative
     * incremental amount from `room_types.base_price`). A row here is also
     * what makes repeated extensions possible: `folio_charges` enforces one
     * charge per `(source_type, source_id)`, and a reservation can only ever
     * have one `accommodation` charge, so a `stay_extension` charge is keyed
     * to this table's own id instead of the reservation id.
     *
     * MariaDB 10.4-compatible: DECIMAL money, plain indexes only.
     */
    public function up(): void
    {
        Schema::create('reservation_extensions', function (Blueprint $table) {
            $table->id();

            // Financial history — deleting the reservation is blocked.
            $table->foreignId('reservation_id')->constrained()->restrictOnDelete();

            // Denormalized from the reservation, never a client value.
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();

            $table->date('previous_check_out');
            $table->date('new_check_out');
            $table->unsignedInteger('nights_added');

            // room_types.base_price at the time of the extension, and
            // nights_added * unit_price — no other pricing input.
            $table->decimal('unit_price', 12, 2);
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->nullable();

            // Soft reference (not a DB foreign key, mirroring folio_charges'
            // own source_id) — set once the folio charge is posted.
            $table->unsignedBigInteger('folio_charge_id')->nullable();

            // The `Idempotency-Key` header value, when supplied — unique so a
            // retried request can never post two charges for one extension.
            $table->string('idempotency_key', 255)->nullable()->unique();

            // Staff member who performed the extension from the dashboard;
            // NULL for a guest-initiated extension.
            $table->foreignId('created_by_staff_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['reservation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_extensions');
    }
};

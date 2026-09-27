<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('base_price', 10, 2);
            $table->unsignedSmallInteger('capacity');
            $table->json('amenities')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['hotel_id', 'name']);
        });

        // capacity >= 1 is a data-integrity decision (not a Phase 0-stated
        // business rule) — enforced at the database level as a defense-in-depth
        // complement to the Form Request validation planned for Phase 2B.
        DB::statement('ALTER TABLE room_types ADD CONSTRAINT room_types_capacity_min CHECK (capacity >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('room_types');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            // A city with cities of its own makes no sense; a country with
            // no cities is normal. Deleting a country that still has cities
            // is blocked at the service layer AND by the DB (restrict).
            $table->foreignId('country_id')->constrained('countries')->restrictOnDelete();
            $table->string('name_en');
            $table->string('name_ar');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // A country never has two cities with the same localized name.
            $table->unique(['country_id', 'name_en']);
            $table->unique(['country_id', 'name_ar']);
            $table->index('is_active');
            // country_id already indexed by the FK constraint.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cities');
    }
};

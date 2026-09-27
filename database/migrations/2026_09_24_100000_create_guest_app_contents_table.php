<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guest App branding + entry content (splash / onboarding), managed from the
 * dashboard. A single-row table: this deployment runs one guest app, so the
 * content is app-wide, not per hotel. Text is stored as `{"en","ar"}` maps
 * (same `*_i18n` convention as hotels); images store a disk-relative path +
 * the disk they were written to (same convention as hotel_media).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_app_contents', function (Blueprint $table) {
            $table->id();
            $table->json('app_name_i18n')->nullable();
            $table->json('onboarding_title_i18n')->nullable();
            $table->json('onboarding_body_i18n')->nullable();
            $table->json('onboarding_cta_i18n')->nullable();
            $table->string('logo_disk')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('onboarding_image_disk')->nullable();
            $table->string('onboarding_image_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_app_contents');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guest App basic branding controls managed on the dashboard Guest app page:
 * the app icon (web favicon / home-screen icon + in-app), the brand colour,
 * the support contacts and the legal links. Every column is nullable —
 * null means "use the app's bundled default / hide the control".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guest_app_contents', function (Blueprint $table) {
            $table->string('app_icon_disk')->nullable();
            $table->string('app_icon_path')->nullable();
            $table->string('primary_color', 7)->nullable();
            $table->string('support_phone', 30)->nullable();
            $table->string('support_whatsapp', 30)->nullable();
            $table->string('support_email', 190)->nullable();
            $table->string('website_url', 500)->nullable();
            $table->string('terms_url', 500)->nullable();
            $table->string('privacy_url', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('guest_app_contents', function (Blueprint $table) {
            $table->dropColumn([
                'app_icon_disk', 'app_icon_path', 'primary_color',
                'support_phone', 'support_whatsapp', 'support_email',
                'website_url', 'terms_url', 'privacy_url',
            ]);
        });
    }
};

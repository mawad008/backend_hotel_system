<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guest App `PROFILE_Support` "الأسئلة الشائعة": an ordered list of
 * `{question_i18n, answer_i18n}` pairs managed on the dashboard Guest app
 * page. Null / empty = no FAQ yet (the app says so).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guest_app_contents', function (Blueprint $table) {
            $table->json('faq')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('guest_app_contents', function (Blueprint $table) {
            $table->dropColumn('faq');
        });
    }
};

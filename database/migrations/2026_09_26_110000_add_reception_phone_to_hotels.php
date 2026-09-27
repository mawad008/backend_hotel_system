<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The hotel's front-desk phone — the Guest App's "تواصل مع الاستقبال"
 * actions call it. Optional: without one the app shows the reception
 * information screen only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->string('reception_phone', 32)->nullable()->after('check_out_time');
        });
    }

    public function down(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->dropColumn('reception_phone');
        });
    }
};

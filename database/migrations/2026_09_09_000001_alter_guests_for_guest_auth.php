<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 0 — Guest Authentication (approved Phase 0 §5/§6.1: the Guest is a
 * person-account with its own auth surface, separate from staff `users`).
 *
 * `phone` (E.164) becomes the login identifier: required + unique. `name` and
 * `email` become nullable because a first-time guest exists (with a verified
 * phone + an issued token) before the "complete your details" step. Two
 * timestamps record the lifecycle: phone proven, profile finished.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Any pre-existing demo guest without a phone gets a deterministic
        // placeholder so the NOT NULL + UNIQUE change can apply on a
        // non-fresh database. A fresh migrate never hits this.
        DB::table('guests')->whereNull('phone')->orWhere('phone', '')->get()->each(function ($row): void {
            DB::table('guests')->where('id', $row->id)->update(['phone' => '+0000000'.str_pad((string) $row->id, 4, '0', STR_PAD_LEFT)]);
        });

        Schema::table('guests', function (Blueprint $table): void {
            $table->string('name')->nullable()->change();
            $table->string('email')->nullable()->change();
            $table->string('phone')->nullable(false)->change();
            $table->timestamp('phone_verified_at')->nullable()->after('phone');
            $table->timestamp('profile_completed_at')->nullable()->after('phone_verified_at');
        });

        Schema::table('guests', function (Blueprint $table): void {
            $table->unique('phone');
        });
    }

    public function down(): void
    {
        Schema::table('guests', function (Blueprint $table): void {
            $table->dropUnique(['phone']);
            $table->dropColumn(['phone_verified_at', 'profile_completed_at']);
            $table->string('phone')->nullable()->change();
            $table->string('email')->nullable(false)->change();
            $table->string('name')->nullable(false)->change();
        });
    }
};

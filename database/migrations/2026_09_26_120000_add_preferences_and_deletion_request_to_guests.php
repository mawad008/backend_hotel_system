<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guest App `PROFILE_Preferences` / `PROFILE_Privacy`:
 *
 * - `preferences` — stay preferences every hotel of the group reads to
 *   prepare the room (high floor, extra pillows) plus the guest's opt-out of
 *   email/SMS lifecycle messages. Null = nothing chosen yet (defaults apply).
 * - `data_deletion_requested_at` — the guest asked for their data to be
 *   deleted ("طلب حذف بياناتي"); staff see it on the guest profile. The
 *   deletion itself is a staff/legal process, not automated here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->json('preferences')->nullable()->after('profile_completed_at');
            $table->timestamp('data_deletion_requested_at')->nullable()->after('preferences');
        });
    }

    public function down(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->dropColumn(['preferences', 'data_deletion_requested_at']);
        });
    }
};

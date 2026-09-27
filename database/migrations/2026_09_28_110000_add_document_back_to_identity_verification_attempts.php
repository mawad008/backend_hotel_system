<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Front/back document images (Egyptian / Saudi cards). The back image is
     * stored exactly like the front: encrypted, private disk, relative path
     * only, same retention. Still no column for any extracted identity value.
     *
     * provider_artifact_ref widens to hold several comma-joined
     * "{model}/{resultId}" references (front + back analyses).
     */
    public function up(): void
    {
        Schema::table('identity_verification_attempts', function (Blueprint $table) {
            $table->string('document_back_path')->nullable()->after('document_path');
            $table->string('provider_artifact_ref', 512)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('identity_verification_attempts', function (Blueprint $table) {
            $table->dropColumn('document_back_path');
            $table->string('provider_artifact_ref', 128)->nullable()->change();
        });
    }
};

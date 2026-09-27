<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * OCR document check on each attempt. Deliberately stores OUTCOME CODES
     * ONLY — there is still no column for a document number, name, date of
     * birth, MRZ or OCR text (Phase 0 §17 minimisation). The guest's typed
     * values and the extracted values are compared in memory and discarded.
     */
    public function up(): void
    {
        Schema::table('identity_verification_attempts', function (Blueprint $table) {
            // verified | needs_review | mismatch | ocr_failed | document_expired
            // | document_unsupported | processing
            $table->string('document_check_status', 32)->nullable()->after('document_path');

            // Safe summary: reason codes, per-field match outcome codes, name
            // score, document kind, issuing country, MRZ validity, provider.
            $table->json('document_check')->nullable()->after('document_check_status');

            $table->string('document_check_provider', 64)->nullable()->after('document_check');

            // Keyed HMAC (app key) of document bytes + claim — detects an
            // identical re-upload; not reversible.
            $table->char('document_fingerprint', 64)->nullable()->after('document_check_provider');

            // How many documents were OCR-checked on this attempt (cost cap).
            $table->unsignedTinyInteger('document_uploads')->default(0)->after('document_fingerprint');

            $table->timestamp('document_checked_at')->nullable()->after('document_uploads');

            // A provider-side copy (e.g. Azure analyze result) whose deletion
            // failed — retried by the retention job until gone.
            $table->string('provider_artifact_ref', 128)->nullable()->after('document_checked_at');

            $table->index('document_check_status', 'idv_attempts_doc_check_index');
            $table->index('provider_artifact_ref', 'idv_attempts_artifact_index');
        });
    }

    public function down(): void
    {
        Schema::table('identity_verification_attempts', function (Blueprint $table) {
            $table->dropIndex('idv_attempts_doc_check_index');
            $table->dropIndex('idv_attempts_artifact_index');
            $table->dropColumn([
                'document_check_status',
                'document_check',
                'document_check_provider',
                'document_fingerprint',
                'document_uploads',
                'document_checked_at',
                'provider_artifact_ref',
            ]);
        });
    }
};

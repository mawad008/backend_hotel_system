<?php

use App\Domain\IdentityVerification\Services\IdentityImageRetentionService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Identity image retention (Phase 0 §17, R58) — daily; the hotel keeps a
// stay's ID images for IDENTITY_VERIFICATION_RETENTION_DAYS (30, the maximum).
Artisan::command('identity:purge-expired-images', function (IdentityImageRetentionService $retention) {
    $purged = $retention->purgeExpired();
    $this->info($purged === null
        ? 'Identity image retention is not configured (IDENTITY_VERIFICATION_RETENTION_DAYS) — nothing purged.'
        : "Purged images of {$purged} identity verification attempt(s).");
})->purpose('Delete stored ID/selfie images of stays that ended beyond the retention period');

Schedule::command('identity:purge-expired-images')->daily();

// OCR by-products — hourly: provider-side analysis copies whose immediate
// deletion failed (Azure otherwise keeps them up to 24 h) and documents left
// behind by an interrupted OCR check.
Artisan::command('identity:purge-ocr-artifacts', function (IdentityImageRetentionService $retention) {
    $r = $retention->purgeOcrArtifacts();
    $this->info("Provider artifacts deleted: {$r['artifacts_deleted']} (still pending: {$r['artifacts_pending']}); interrupted-check documents deleted: {$r['stale_documents_deleted']}.");
})->purpose('Delete leftover OCR provider artifacts and interrupted-check documents');

Schedule::command('identity:purge-ocr-artifacts')->hourly();

// Identity document routes — makes a missing production model obvious.
// Exit code 1 when an ENABLED route has no model while the real provider is
// active (wire it into deploy checks / monitoring).
Artisan::command('identity:document-providers', function (\App\Domain\IdentityVerification\DocumentCheck\IdentityDocumentCatalog $catalog) {
    $provider = (string) config('verification.document_provider');
    $rows = [];
    $missing = 0;

    foreach ($catalog->all() as $s) {
        $gap = $s->enabled && ! $s->modelConfigured();
        $missing += $gap ? 1 : 0;
        $rows[] = [
            $s->type->value,
            $s->type->issuingCountry() ?? '—',
            $s->enabled ? 'yes' : 'no',
            $s->model ?? ($gap ? 'MISSING' : '—'),
            $s->back,
            $s->autoVerify ? 'auto' : 'manual review',
        ];
    }

    $this->info("Document provider: {$provider}");
    $this->table(['Type', 'Country', 'Enabled', 'Model', 'Back image', 'Decision'], $rows);

    if ($provider !== 'dummy' && $missing > 0) {
        $this->error("{$missing} enabled document type(s) have no model: those uploads go to manual review (provider_not_configured).");

        return 1;
    }

    return 0;
})->purpose('Show the identity-document OCR routes and flag missing model configuration');

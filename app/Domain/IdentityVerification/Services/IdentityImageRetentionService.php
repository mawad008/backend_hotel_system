<?php

namespace App\Domain\IdentityVerification\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\IdentityVerification\DocumentCheck\DocumentCheckStatus;
use App\Domain\IdentityVerification\Models\IdentityVerificationAttempt;
use App\Domain\IdentityVerification\Models\IdentityVerificationSession;
use App\Domain\IdentityVerification\Support\IdentityFileStore;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;

/**
 * The scheduled identity-image cleanup Phase 0 §17 / R58 calls for
 * ("a scheduled cleanup job reads a retention-period config value") and the
 * Guest App promises ("تُحذف بعد المغادرة").
 *
 * Deletes the stored ID document + selfie files of attempts whose
 * reservation ended (checked out / invoiced / cancelled) more than
 * `verification.retention_days` days ago, and clears their paths. The
 * verification outcome, score and decisions are kept — only the images go.
 *
 * Approved 2026-09-26: the hotel keeps a stay's copy for
 * `verification.retention_days` (30) after the stay ends, and never longer
 * than {@see self::MAX_RETENTION_DAYS} — a larger configured value is capped.
 * A guest who chose "keep for future bookings" keeps their most recent
 * approved images (the copy reused next time); every other image is purged.
 * A negative / unset value disables the purge.
 */
class IdentityImageRetentionService
{
    /** Approved 2026-09-26: the hotel may keep a stay's ID images 30 days at most. */
    public const MAX_RETENTION_DAYS = 30;

    private const ENDED_STATUSES = [
        Reservation::STATUS_CHECKED_OUT,
        Reservation::STATUS_INVOICED,
        Reservation::STATUS_CANCELLED,
    ];

    /** In-flight OCR checks older than this are treated as abandoned. */
    public const STALE_PROCESSING_MINUTES = 60;

    public function __construct(
        private readonly IdentityFileStore $files,
        private readonly AuditLogger $auditLogger,
        private readonly IdentityDocumentCheckService $documentChecks,
    ) {}

    /**
     * OCR by-products, independent of the stay-based image retention:
     *  - provider-side copies (e.g. Azure analyze results) whose immediate
     *    deletion failed are deleted now — retried every run until gone;
     *  - documents stuck in an interrupted OCR check (worker died mid-call)
     *    are deleted and the check marked `ocr_failed`, so the guest re-uploads.
     *
     * No temporary OCR file is ever written to disk (documents are sent to
     * the provider from memory), so there is no temp directory to sweep.
     *
     * @return array{artifacts_deleted: int, artifacts_pending: int, stale_documents_deleted: int}
     */
    public function purgeOcrArtifacts(): array
    {
        $deleted = 0;
        $pending = 0;

        IdentityVerificationAttempt::query()
            ->whereNotNull('provider_artifact_ref')
            ->chunkById(100, function ($attempts) use (&$deleted, &$pending): void {
                foreach ($attempts as $attempt) {
                    $still = $this->documentChecks->deleteArtifacts((string) $attempt->provider_artifact_ref);

                    if ($still === null) {
                        $deleted++;
                    } else {
                        $pending++;
                    }

                    if ($still !== $attempt->provider_artifact_ref) {
                        $attempt->forceFill(['provider_artifact_ref' => $still])->save();
                    }
                }
            });

        $stale = 0;

        IdentityVerificationAttempt::query()
            ->where('document_check_status', DocumentCheckStatus::Processing->value)
            ->where('updated_at', '<=', now()->subMinutes(self::STALE_PROCESSING_MINUTES))
            ->chunkById(100, function ($attempts) use (&$stale): void {
                foreach ($attempts as $attempt) {
                    $this->files->delete($attempt->document_path);
                    $this->files->delete($attempt->document_back_path);
                    $attempt->forceFill([
                        'document_path' => null,
                        'document_back_path' => null,
                        'document_check_status' => DocumentCheckStatus::OcrFailed->value,
                        'document_check' => ['status' => DocumentCheckStatus::OcrFailed->value, 'reasons' => ['ocr_interrupted']],
                        'document_checked_at' => now(),
                    ])->save();
                    $this->auditLogger->record(null, 'identity.document.interrupted_check_purged', $attempt);
                    $stale++;
                }
            });

        return ['artifacts_deleted' => $deleted, 'artifacts_pending' => $pending, 'stale_documents_deleted' => $stale];
    }

    /** @return int|null purged attempts, or null when retention is not configured */
    public function purgeExpired(): ?int
    {
        $days = config('verification.retention_days');

        if ($days === null || $days === '' || ! is_numeric($days) || (int) $days < 0) {
            return null;
        }

        $cutoff = now()->subDays(min((int) $days, self::MAX_RETENTION_DAYS));
        $purged = 0;

        IdentityVerificationAttempt::query()
            ->where(fn ($q) => $q->whereNotNull('document_path')->orWhereNotNull('document_back_path')->orWhereNotNull('selfie_path'))
            ->whereHas('session.reservation', fn ($q) => $q
                ->whereIn('status', self::ENDED_STATUSES)
                ->where('updated_at', '<=', $cutoff))
            ->with('session.guest')
            ->chunkById(100, function ($attempts) use (&$purged): void {
                foreach ($attempts as $attempt) {
                    if ($this->isGuestsKeptCopy($attempt)) {
                        continue;
                    }

                    $this->files->delete($attempt->document_path);
                    $this->files->delete($attempt->document_back_path);
                    $this->files->delete($attempt->selfie_path);
                    $attempt->forceFill(['document_path' => null, 'document_back_path' => null, 'selfie_path' => null])->save();
                    $this->auditLogger->record(null, 'identity.images.purged', $attempt);
                    $purged++;
                }
            });

        return $purged;
    }

    /** The latest approved attempt of a guest who opted to keep their ID. */
    private function isGuestsKeptCopy(IdentityVerificationAttempt $attempt): bool
    {
        $guest = $attempt->session?->guest;

        if ($guest === null || $guest->identity_retention !== Guest::IDENTITY_KEEP_FOR_FUTURE) {
            return false;
        }

        $latestApproved = IdentityVerificationAttempt::query()
            ->whereHas('session', fn ($q) => $q->where('guest_id', $guest->id)
                ->whereIn('status', IdentityVerificationSession::APPROVED_STATUSES))
            ->whereNotNull('document_path')
            ->latest('id')
            ->value('id');

        return $latestApproved === $attempt->id;
    }
}

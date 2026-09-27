<?php

namespace App\Domain\IdentityVerification\Services;

use App\Domain\IdentityVerification\DocumentCheck\DocumentCheckEvaluator;
use App\Domain\IdentityVerification\DocumentCheck\DocumentCheckOutcome;
use App\Domain\IdentityVerification\DocumentCheck\DocumentCheckStatus;
use App\Domain\IdentityVerification\DocumentCheck\IdentityClaim;
use App\Domain\IdentityVerification\DocumentCheck\IdentityDocumentCatalog;
use App\Domain\IdentityVerification\DocumentCheck\IdentityDocumentType;
use App\Domain\IdentityVerification\Provider\Contracts\IdentityDocumentProviderInterface;
use App\Domain\IdentityVerification\Provider\Data\DocumentExtractionRequest;
use App\Domain\IdentityVerification\Provider\Data\DocumentExtractionResult;
use App\Domain\IdentityVerification\Support\IdentityFileStore;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one OCR document check: decrypt the stored document in memory →
 * provider extraction → delete the provider's copy → evaluate against the
 * guest's claim. MUST be called with no DB transaction open (it makes a
 * network call); persisting the outcome is the workflow service's job.
 *
 * Observability: exactly one log line per check, carrying only safe
 * metadata — attempt id, provider, provider status code, outcome status,
 * reason codes, duration. Never a name, number, date, MRZ, OCR text or image.
 */
class IdentityDocumentCheckService
{
    public function __construct(
        private readonly IdentityDocumentProviderInterface $provider,
        private readonly IdentityFileStore $files,
        private readonly IdentityDocumentCatalog $catalog = new IdentityDocumentCatalog,
    ) {}

    public function providerName(): string
    {
        return $this->provider->name();
    }

    /**
     * @return array{outcome: DocumentCheckOutcome, pending_artifact: string|null, duration_ms: int}
     */
    public function check(
        int $attemptId,
        string $documentPath,
        ?string $documentTypeHint,
        IdentityClaim $claim,
        DateTimeImmutable $validOn,
        ?IdentityDocumentType $documentType = null,
        ?string $backPath = null,
    ): array {
        $started = hrtime(true);
        $type = $documentType ?? IdentityDocumentType::fromInput($documentTypeHint);
        $settings = $this->catalog->settings($type);

        try {
            $bytes = $this->files->read($documentPath);
            $backBytes = $backPath !== null ? $this->files->read($backPath) : null;
            $extraction = $this->provider->extract(new DocumentExtractionRequest(
                reference: (string) $attemptId,
                bytes: $bytes,
                mimeType: $this->files->detectMime($bytes) ?? 'application/octet-stream',
                documentTypeHint: $documentTypeHint,
                documentType: $type,
                backBytes: $backBytes,
                backMimeType: $backBytes !== null ? $this->files->detectMime($backBytes) : null,
            ));
            unset($bytes, $backBytes);
        } catch (Throwable $e) {
            $extraction = DocumentExtractionResult::failed(DocumentExtractionResult::FAILURE_PROVIDER_ERROR, 'exception:'.class_basename($e));
        }

        $pending = array_values(array_filter(
            $extraction->artifactReferences,
            fn (string $ref) => ! $this->safeDelete($ref),
        ));

        $outcome = $this->evaluator()->evaluate($extraction, $claim, $validOn, $type, $settings->autoVerify);
        $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);

        $context = [
            'attempt_id' => $attemptId,
            'provider' => $this->provider->name(),
            'document_type' => $type->value,
            'provider_status' => $extraction->providerStatus,
            'status' => $outcome->status->value,
            'reasons' => $outcome->reasons,
            'duration_ms' => $durationMs,
            'artifact_deleted' => $extraction->artifactReferences === [] ? null : $pending === [],
        ];

        // A missing production route must be loud: an error every time.
        in_array($extraction->failure, [
            DocumentExtractionResult::FAILURE_AUTH_ERROR,
            DocumentExtractionResult::FAILURE_PROVIDER_ERROR,
            DocumentExtractionResult::FAILURE_NOT_CONFIGURED,
        ], true)
            ? Log::error('identity.document_check', $context)
            : Log::info('identity.document_check', $context);

        return [
            'outcome' => $outcome,
            'pending_artifact' => $pending === [] ? null : implode(',', $pending),
            'duration_ms' => $durationMs,
        ];
    }

    /**
     * Retry deleting pending provider artifacts (retention job). Accepts the
     * comma-joined list stored on the attempt; returns what is still pending.
     */
    public function deleteArtifacts(string $references): ?string
    {
        $pending = array_values(array_filter(
            array_filter(explode(',', $references)),
            fn (string $ref) => ! $this->safeDelete($ref),
        ));

        return $pending === [] ? null : implode(',', $pending);
    }

    /** Retry deleting one provider artifact. */
    public function deleteArtifact(string $reference): bool
    {
        return $this->safeDelete($reference);
    }

    public static function unreadable(string $reason): DocumentCheckOutcome
    {
        return new DocumentCheckOutcome(DocumentCheckStatus::OcrFailed, [$reason]);
    }

    private function safeDelete(string $reference): bool
    {
        try {
            return $this->provider->deleteArtifact($reference);
        } catch (Throwable) {
            return false;
        }
    }

    private function evaluator(): DocumentCheckEvaluator
    {
        return new DocumentCheckEvaluator(
            minFieldConfidence: (float) config('verification.document_check.min_field_confidence', 0.80),
            autoVerifyWithoutMrz: (bool) config('verification.document_check.auto_verify_without_mrz', false),
        );
    }
}

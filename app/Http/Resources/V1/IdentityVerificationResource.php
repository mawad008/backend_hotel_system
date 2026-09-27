<?php

namespace App\Http\Resources\V1;

use App\Domain\IdentityVerification\Models\IdentityVerificationAttempt;
use App\Domain\IdentityVerification\Models\IdentityVerificationDecision;
use App\Domain\IdentityVerification\Models\IdentityVerificationSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Phase 6 — the safe HTTP representation of an identity verification
 * session.
 *
 * Only application-level status fields are exposed. Document/selfie storage
 * paths, provider references, attempt `metadata`, raw provider responses and
 * any other internal/PII detail are deliberately absent (Phase 0 §17,
 * Phase 6 "do not return sensitive data from API resources").
 *
 * The `latest_decision` block, when present, carries only the decision
 * result, band, the optional staff reason, and who/when — no identity data.
 *
 * @mixin IdentityVerificationSession
 */
class IdentityVerificationResource extends JsonResource
{
    private ?IdentityVerificationDecision $latestDecision = null;

    private bool $decisionLoaded = false;

    private ?IdentityVerificationAttempt $latestAttempt = null;

    private bool $staffDetail = false;

    /** Staff responses also name the OCR provider (never shown to guests). */
    public function withStaffDetail(): self
    {
        $this->staffDetail = true;

        return $this;
    }

    public function withLatestAttempt(?IdentityVerificationAttempt $attempt): self
    {
        $this->latestAttempt = $attempt;

        return $this;
    }

    public function withLatestDecision(?IdentityVerificationDecision $decision): self
    {
        $this->latestDecision = $decision;
        $this->decisionLoaded = true;

        return $this;
    }

    /** @return array<string, mixed> */
    private function documentCheck(): array
    {
        $attempt = $this->latestAttempt;
        $status = $attempt->documentCheckStatus();
        $cap = (int) config('verification.document_check.max_uploads_per_attempt', 5);

        return array_filter([
            'status' => $attempt->document_check_status,
            'document_type' => $attempt->document_type,
            'back_image' => $attempt->document_back_path !== null,
            'reasons' => array_values((array) ($attempt->document_check['reasons'] ?? [])),
            'fields' => (object) ($attempt->document_check['fields'] ?? []),
            'document_kind' => $attempt->document_check['document_kind'] ?? null,
            'can_continue' => $status?->allowsSelfie() ?? false,
            'requires_new_document' => $status?->requiresNewDocument() ?? false,
            'uploads_remaining' => $cap > 0 ? max(0, $cap - (int) $attempt->document_uploads) : null,
            'checked_at' => $attempt->document_checked_at,
            'provider' => $this->staffDetail ? $attempt->document_check_provider : null,
        ], fn ($v, $k) => $k !== 'provider' || $v !== null, ARRAY_FILTER_USE_BOTH);
    }

    public function toArray(Request $request): array
    {
        return [
            'reservation_id' => $this->reservation_id,
            'hotel_id' => $this->hotel_id,
            'guest_id' => $this->guest_id,
            'status' => $this->status,
            'provider' => $this->provider,
            'attempts' => (int) ($this->attempts ?? 0),
            'latest_outcome' => $this->latest_outcome,
            'latest_score' => $this->latest_score,
            'decided_at' => $this->decided_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'latest_decision' => $this->when(
                $this->decisionLoaded && $this->latestDecision !== null,
                fn () => [
                    'type' => $this->latestDecision->type,
                    'result' => $this->latestDecision->result,
                    'band' => $this->latestDecision->band,
                    'reason' => $this->latestDecision->reason,
                    'decided_by_user_id' => $this->latestDecision->decided_by_user_id,
                    'decided_at' => $this->latestDecision->created_at,
                ],
            ),
            // The OCR document check of the current attempt: status + reason
            // codes only — never an extracted or entered value.
            'document_check' => $this->when(
                $this->latestAttempt?->document_check_status !== null,
                fn () => $this->documentCheck(),
            ),
        ];
    }
}

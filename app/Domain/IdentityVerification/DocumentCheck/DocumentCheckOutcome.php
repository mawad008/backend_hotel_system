<?php

namespace App\Domain\IdentityVerification\DocumentCheck;

use App\Domain\IdentityVerification\Provider\Support\IdentitySensitiveDataGuard;

/**
 * The result of one document check — outcome CODES only, never a value read
 * off the document or entered by the guest. This is what gets stored on the
 * attempt (`document_check_status` + `document_check` JSON) and what the API
 * exposes (status + reasons).
 *
 * `fields` maps a compared field to its outcome, e.g.
 *   name        strong | weak | mismatch | unverifiable_script | missing | not_provided
 *   number      match | confusable | mismatch | missing | not_provided
 *   birth       match | mismatch | missing | not_provided
 *   expiry      valid | expired | missing
 *   nationality match | mismatch | missing | not_provided
 */
final class DocumentCheckOutcome
{
    /**
     * @param  list<string>  $reasons
     * @param  array<string, string>  $fields
     */
    public function __construct(
        public readonly DocumentCheckStatus $status,
        public readonly array $reasons = [],
        public readonly array $fields = [],
        public readonly ?int $nameScore = null,
        public readonly ?string $documentKind = null,
        public readonly ?string $issuingCountry = null,
        /** valid | invalid | absent | null (not evaluated) */
        public readonly ?string $machineReadableZone = null,
    ) {}

    public function withStatus(DocumentCheckStatus $status, string $reason): self
    {
        return new self($status, array_values(array_unique([...$this->reasons, $reason])), $this->fields, $this->nameScore, $this->documentKind, $this->issuingCountry, $this->machineReadableZone);
    }

    /**
     * Safe, persistable summary (no PII).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = array_filter([
            'status' => $this->status->value,
            'reasons' => $this->reasons,
            'fields' => $this->fields,
            'name_score' => $this->nameScore,
            'document_kind' => $this->documentKind,
            'issuing_country' => $this->issuingCountry,
            'machine_readable_zone' => $this->machineReadableZone,
        ], fn ($v) => $v !== null && $v !== []);

        IdentitySensitiveDataGuard::assertNoSensitiveKeys($data, 'document check');

        return $data;
    }
}

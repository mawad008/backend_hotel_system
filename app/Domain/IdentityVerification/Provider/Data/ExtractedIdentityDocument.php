<?php

namespace App\Domain\IdentityVerification\Provider\Data;

use LogicException;

/**
 * The structured fields a provider read off an ID document, provider-neutral.
 * PII: lives only in memory during the document check. Never persisted,
 * logged or serialized — the check stores outcome codes only.
 *
 * Every value is the provider's ORIGINAL reading (dates as Y-m-d, countries
 * as ISO alpha-3 when the provider normalizes them). Normalization for
 * comparison happens in the evaluator, so the original and the comparison
 * value are never conflated.
 *
 * Confidences are 0..1 (null when the provider gives none).
 */
final class ExtractedIdentityDocument
{
    public const KIND_PASSPORT = 'passport';

    public const KIND_NATIONAL_ID = 'national_id';

    public const KIND_RESIDENCE_PERMIT = 'residence_permit';

    public const KIND_DRIVER_LICENSE = 'driver_license';

    public const KIND_OTHER = 'other';

    /**
     * @param  array<string, float|null>  $confidence  keyed by field name
     */
    public function __construct(
        public readonly string $kind,
        public readonly ?string $givenNames = null,
        public readonly ?string $surname = null,
        public readonly ?string $documentNumber = null,
        public readonly ?string $dateOfBirth = null,
        public readonly ?string $dateOfExpiry = null,
        public readonly ?string $nationality = null,
        public readonly ?string $issuingCountry = null,
        /** Raw MRZ text as read (newline-separated lines), when present. */
        public readonly ?string $mrzText = null,
        public readonly array $confidence = [],
        /**
         * The name in the document's OTHER script, when it prints both
         * (e.g. Saudi cards: Arabic + Latin). Lets an Arabic claim be compared
         * with the Arabic name without ever transliterating.
         */
        public readonly ?string $alternateGivenNames = null,
        public readonly ?string $alternateSurname = null,
        /** 'male' | 'female' | null — normalized by the mapper. */
        public readonly ?string $gender = null,
        /** Read for completeness; never compared, stored or returned. */
        public readonly ?string $address = null,
        public readonly ?string $issuingAuthority = null,
        /**
         * Inconsistencies the mapper found between the sides / fields of the
         * document itself (reason codes, e.g. `front_back_number_conflict`).
         * They send the check to manual review.
         */
        public readonly array $consistencyIssues = [],
    ) {}

    public function confidenceOf(string $field): ?float
    {
        return $this->confidence[$field] ?? null;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['kind' => $this->kind, 'fields' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new LogicException('Extracted identity data must never be serialized.');
    }
}

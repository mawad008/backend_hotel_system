<?php

namespace App\Domain\IdentityVerification\DocumentCheck\NationalNumbers;

use DateTimeImmutable;
use LogicException;

/**
 * Result of a STRUCTURAL check of a national identity number. A valid
 * structure is NOT proof that a document is genuine or that the number was
 * ever issued — it only catches misreads, typos and impossible values.
 * Contains PII-derived data (birth date / gender) — never persisted.
 */
final class NationalNumberInspection
{
    /**
     * @param  list<string>  $reasons  reason codes when not valid
     */
    public function __construct(
        public readonly bool $valid,
        public readonly array $reasons = [],
        public readonly ?DateTimeImmutable $birthDate = null,
        /** 'male' | 'female' | null */
        public readonly ?string $gender = null,
    ) {}

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['valid' => $this->valid ? 'yes' : 'no'];
    }

    public function __serialize(): array
    {
        throw new LogicException('National number data must never be serialized.');
    }
}

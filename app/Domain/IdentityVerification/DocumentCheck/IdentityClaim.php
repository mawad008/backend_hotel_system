<?php

namespace App\Domain\IdentityVerification\DocumentCheck;

use DateTimeImmutable;
use LogicException;

/**
 * What the guest (or the staff member on their behalf) says is on the ID
 * document. Held in memory for the duration of one document check and never
 * persisted, logged, serialized or echoed back — only the comparison outcome
 * is stored (the existing schema deliberately has no document-number / DOB
 * columns, and this keeps it that way).
 *
 * Every field is optional at this level: a field that is not provided can't
 * be confirmed, so the check can at best reach NEEDS_REVIEW, never VERIFIED.
 */
final class IdentityClaim
{
    public function __construct(
        #[\SensitiveParameter] public readonly ?string $fullName = null,
        #[\SensitiveParameter] public readonly ?string $documentNumber = null,
        #[\SensitiveParameter] public readonly ?DateTimeImmutable $dateOfBirth = null,
        /** ISO 3166-1 alpha-3. */
        public readonly ?string $nationality = null,
    ) {}

    public function isComplete(): bool
    {
        return $this->fullName !== null && $this->fullName !== ''
            && $this->documentNumber !== null && $this->documentNumber !== ''
            && $this->dateOfBirth !== null;
    }

    /**
     * A stable canonical form, used ONLY as keyed-HMAC input for duplicate
     * upload detection — never stored or logged as-is.
     */
    public function canonical(): string
    {
        return implode("\x1F", [
            IdentityTextNormalizer::name($this->fullName ?? ''),
            IdentityTextNormalizer::documentNumber($this->documentNumber ?? ''),
            $this->dateOfBirth?->format('Y-m-d') ?? '',
            $this->nationality ?? '',
        ]);
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['claim' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new LogicException('An identity claim must never be serialized.');
    }
}

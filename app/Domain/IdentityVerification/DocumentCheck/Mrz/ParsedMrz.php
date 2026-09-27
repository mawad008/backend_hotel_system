<?php

namespace App\Domain\IdentityVerification\DocumentCheck\Mrz;

use DateTimeImmutable;
use LogicException;

/**
 * A parsed ICAO 9303 machine-readable zone. Contains PII — never persisted,
 * logged or serialized; only {@see self::$valid} / {@see self::$failedChecks}
 * leave the document check (as outcome codes).
 */
final class ParsedMrz
{
    /**
     * @param  list<string>  $failedChecks  names of check digits that did not verify
     */
    public function __construct(
        public readonly string $format,
        public readonly string $documentCode,
        public readonly ?string $issuingState,
        public readonly string $surname,
        public readonly string $givenNames,
        public readonly bool $namesTruncated,
        public readonly string $documentNumber,
        public readonly ?string $nationality,
        public readonly ?DateTimeImmutable $dateOfBirth,
        public readonly string $sex,
        public readonly ?DateTimeImmutable $dateOfExpiry,
        public readonly array $failedChecks,
    ) {}

    public function isValid(): bool
    {
        return $this->failedChecks === [] && $this->dateOfBirth !== null && $this->dateOfExpiry !== null;
    }

    public function isPassport(): bool
    {
        return $this->format === MrzParser::TD3 && str_starts_with($this->documentCode, 'P');
    }

    public function fullName(): string
    {
        return trim($this->givenNames.' '.$this->surname);
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['mrz' => '[redacted]', 'format' => $this->format];
    }

    public function __serialize(): array
    {
        throw new LogicException('MRZ data must never be serialized.');
    }
}

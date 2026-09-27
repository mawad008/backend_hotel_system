<?php

namespace App\Domain\IdentityVerification\DocumentCheck\NationalNumbers;

use App\Domain\IdentityVerification\DocumentCheck\IdentityDocumentType;
use DateTimeImmutable;

/**
 * Which structural number rules apply to a document type (null = none;
 * passports are covered by the MRZ check digits instead).
 */
final class NationalNumberRules
{
    private function __construct() {}

    public static function inspect(IdentityDocumentType $type, string $number, ?DateTimeImmutable $today = null): ?NationalNumberInspection
    {
        return match ($type) {
            IdentityDocumentType::EgyptianNationalId => EgyptianNationalIdNumber::inspect($number, $today),
            IdentityDocumentType::SaudiNationalId, IdentityDocumentType::SaudiIqama => SaudiIdentityNumber::inspect($number, $type),
            default => null,
        };
    }
}

<?php

namespace App\Domain\IdentityVerification\DocumentCheck\NationalNumbers;

use App\Domain\IdentityVerification\DocumentCheck\IdentityDocumentType;
use App\Domain\IdentityVerification\DocumentCheck\IdentityTextNormalizer;

/**
 * Structure of Saudi identity numbers: 10 digits; the first digit is 1 for
 * a Saudi National ID (citizen) and 2 for an Iqama (resident).
 *
 * A Luhn-style checksum is described by third parties but is not an
 * officially published rule, so it is deliberately NOT enforced here.
 */
final class SaudiIdentityNumber
{
    private function __construct() {}

    public static function inspect(string $value, IdentityDocumentType $type): NationalNumberInspection
    {
        $digits = preg_replace('/\s+/', '', IdentityTextNormalizer::digits($value)) ?? '';

        if (preg_match('/^\d{10}$/', $digits) !== 1) {
            return new NationalNumberInspection(false, ['saudi_number_structure_invalid']);
        }

        $expected = $type === IdentityDocumentType::SaudiIqama ? '2' : '1';

        if ($digits[0] !== $expected) {
            return new NationalNumberInspection(false, ['saudi_number_prefix_mismatch']);
        }

        return new NationalNumberInspection(true);
    }
}

<?php

namespace App\Domain\IdentityVerification\DocumentCheck\NationalNumbers;

use App\Domain\IdentityVerification\DocumentCheck\IdentityTextNormalizer;
use DateTimeImmutable;

/**
 * Structure of the 14-digit Egyptian national number (الرقم القومي):
 *
 *   C YYMMDD GG SSSS K
 *   │ │      │  │    └ check digit — its algorithm is NOT officially
 *   │ │      │  │      published, so it is deliberately NOT validated
 *   │ │      │  └ serial; the 4th serial digit (13th overall) is odd for
 *   │ │      │    male, even for female
 *   │ │      └ governorate of birth registration: 01–35, or 88 (abroad)
 *   │ └ date of birth
 *   └ century: 2 = 1900–1999, 3 = 2000–2099
 *
 * Checks: exactly 14 digits (after Arabic-Indic → ASCII), a known century
 * digit, a real calendar date that is not in the future, a governorate code
 * in the documented range. Passing is supplementary evidence only.
 */
final class EgyptianNationalIdNumber
{
    private function __construct() {}

    public static function inspect(string $value, ?DateTimeImmutable $today = null): NationalNumberInspection
    {
        $digits = preg_replace('/\s+/', '', IdentityTextNormalizer::digits($value)) ?? '';

        if (preg_match('/^\d{14}$/', $digits) !== 1) {
            return new NationalNumberInspection(false, ['national_id_structure_invalid']);
        }

        $century = match ($digits[0]) {
            '2' => 1900,
            '3' => 2000,
            default => null,
        };

        if ($century === null) {
            return new NationalNumberInspection(false, ['national_id_century_invalid']);
        }

        $year = $century + (int) substr($digits, 1, 2);
        $month = (int) substr($digits, 3, 2);
        $day = (int) substr($digits, 5, 2);

        if (! checkdate($month, $day, $year)) {
            return new NationalNumberInspection(false, ['national_id_birth_date_invalid']);
        }

        $birth = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));

        if ($birth > ($today ?? new DateTimeImmutable('today'))) {
            return new NationalNumberInspection(false, ['national_id_birth_date_invalid']);
        }

        $governorate = (int) substr($digits, 7, 2);

        if (! (($governorate >= 1 && $governorate <= 35) || $governorate === 88)) {
            return new NationalNumberInspection(false, ['national_id_governorate_invalid']);
        }

        return new NationalNumberInspection(
            valid: true,
            birthDate: $birth,
            gender: ((int) $digits[12]) % 2 === 1 ? 'male' : 'female',
        );
    }
}

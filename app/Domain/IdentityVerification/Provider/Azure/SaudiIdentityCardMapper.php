<?php

namespace App\Domain\IdentityVerification\Provider\Azure;

use App\Domain\IdentityVerification\DocumentCheck\IdentityDocumentType;
use App\Domain\IdentityVerification\DocumentCheck\IdentityTextNormalizer;
use App\Domain\IdentityVerification\Provider\Data\ExtractedIdentityDocument;
use InvalidArgumentException;

/**
 * Saudi National ID and Saudi Iqama — two SEPARATE custom Azure models
 * (their layouts and fields differ), sharing one mapper class with a
 * per-type schema.
 *
 * LABELING CONTRACT
 *
 *   Saudi National ID (front; back optional)
 *     FullNameArabic   الاسم (Arabic)
 *     FullNameLatin    name in Latin script, when printed
 *     IdNumber         10 digits, starts with 1
 *     DateOfBirth      Hijri or Gregorian as printed
 *     ExpiryDate       Hijri or Gregorian as printed
 *     Nationality      only if printed on the version being trained
 *
 *   Saudi Iqama / resident identity (front; back optional)
 *     FullNameArabic   الاسم (Arabic)
 *     FullNameLatin    name in Latin script, when printed
 *     IqamaNumber      10 digits, starts with 2
 *     Nationality      الجنسية (Arabic or English as printed)
 *     DateOfBirth      as printed (if the card version prints it)
 *     ExpiryDate       تاريخ الانتهاء (Hijri or Gregorian)
 *
 * Any label may also be placed on the back image; front wins when both
 * carry it and a front/back number disagreement is flagged. Hijri dates are
 * converted by the evaluator (Umm al-Qura). Employer / occupation /
 * religion are deliberately NOT extracted.
 */
final class SaudiIdentityCardMapper implements DocumentFieldMapper
{
    public function __construct(private readonly IdentityDocumentType $type)
    {
        if (! in_array($type, [IdentityDocumentType::SaudiNationalId, IdentityDocumentType::SaudiIqama], true)) {
            throw new InvalidArgumentException('Not a Saudi document type.');
        }
    }

    public function map(array $front, ?array $back): ?ExtractedIdentityDocument
    {
        $f = AzureFields::of($front);
        $b = AzureFields::of($back);

        if ($f === []) {
            return null;
        }

        $numberLabel = $this->type === IdentityDocumentType::SaudiIqama ? 'IqamaNumber' : 'IdNumber';
        $pick = fn (string $label): ?string => AzureFields::first($f, $label) ?? AzureFields::first($b, $label);
        $conf = fn (string $label): ?float => AzureFields::confidence($f, $label) ?? AzureFields::confidence($b, $label);

        $arabic = $pick('FullNameArabic');
        $latin = $pick('FullNameLatin');
        [$given, $surname] = AzureFields::splitFullName($arabic ?? $latin);
        [$altGiven, $altSurname] = $arabic !== null ? AzureFields::splitFullName($latin) : [null, null];

        $issues = [];
        $frontNumber = AzureFields::first($f, $numberLabel);
        $backNumber = AzureFields::first($b, $numberLabel);

        if ($frontNumber !== null && $backNumber !== null
            && IdentityTextNormalizer::documentNumber($frontNumber) !== IdentityTextNormalizer::documentNumber($backNumber)) {
            $issues[] = 'front_back_number_conflict';
        }

        return new ExtractedIdentityDocument(
            kind: $this->type === IdentityDocumentType::SaudiIqama
                ? ExtractedIdentityDocument::KIND_RESIDENCE_PERMIT
                : ExtractedIdentityDocument::KIND_NATIONAL_ID,
            givenNames: $given,
            surname: $surname,
            documentNumber: $frontNumber ?? $backNumber,
            dateOfBirth: $pick('DateOfBirth'),
            dateOfExpiry: $pick('ExpiryDate'),
            nationality: CountryNames::toIso3($pick('Nationality')),
            issuingCountry: 'SAU',
            confidence: [
                'name' => $conf($arabic !== null ? 'FullNameArabic' : 'FullNameLatin'),
                'document_number' => $conf($numberLabel),
                'birth' => $conf('DateOfBirth'),
                'expiry' => $conf('ExpiryDate'),
            ],
            alternateGivenNames: $altGiven,
            alternateSurname: $altSurname,
            consistencyIssues: $issues,
        );
    }
}

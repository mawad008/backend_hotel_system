<?php

namespace App\Domain\IdentityVerification\Provider\Azure;

use App\Domain\IdentityVerification\DocumentCheck\IdentityTextNormalizer;
use App\Domain\IdentityVerification\Provider\Data\ExtractedIdentityDocument;

/**
 * Egyptian National ID card — custom Azure model (Arabic, front + back).
 *
 * LABELING CONTRACT (field names the custom model must be trained with):
 *
 *   FRONT  FirstName         line 1 of the name (الاسم الأول)
 *          FamilyNames       line 2 (father / grandfather / family names)
 *          FullName          optional — the whole name labeled as one field
 *          Address           address lines (read, never stored/compared)
 *          NationalIdNumber  the 14-digit national number (الرقم القومي)
 *   BACK   NationalIdNumber  the number as repeated on the back (cross-check)
 *          Gender            النوع (ذكر / أنثى)
 *          ExpiryDate        البطاقة سارية حتى
 *          IssuingAuthority  optional — issuing civil-registry office
 *
 * The card does not print a separate date of birth: it is DERIVED from the
 * national number by the evaluator (century + YYMMDD), never invented here.
 * Religion / marital status / profession on the back are deliberately NOT
 * extracted (not needed, sensitive).
 */
final class EgyptianNationalIdMapper implements DocumentFieldMapper
{
    public function map(array $front, ?array $back): ?ExtractedIdentityDocument
    {
        $f = AzureFields::of($front);
        $b = AzureFields::of($back);

        if ($f === []) {
            return null;
        }

        $first = AzureFields::first($f, 'FirstName');
        $rest = AzureFields::first($f, 'FamilyNames');

        if ($first === null && $rest === null) {
            [$first, $rest] = AzureFields::splitFirstToken(AzureFields::first($f, 'FullName'));
        }

        $frontNumber = AzureFields::first($f, 'NationalIdNumber');
        $backNumber = AzureFields::first($b, 'NationalIdNumber');
        $issues = [];

        if ($frontNumber !== null && $backNumber !== null
            && IdentityTextNormalizer::documentNumber($frontNumber) !== IdentityTextNormalizer::documentNumber($backNumber)) {
            $issues[] = 'front_back_number_conflict';
        }

        return new ExtractedIdentityDocument(
            kind: ExtractedIdentityDocument::KIND_NATIONAL_ID,
            givenNames: $first,
            surname: $rest,
            documentNumber: $frontNumber ?? $backNumber,
            dateOfBirth: null,
            dateOfExpiry: AzureFields::first($b, 'ExpiryDate') ?? AzureFields::first($f, 'ExpiryDate'),
            nationality: null,
            issuingCountry: 'EGY',
            confidence: [
                'name' => AzureFields::minConfidence(
                    AzureFields::confidence($f, 'FirstName', 'FullName'),
                    AzureFields::confidence($f, 'FamilyNames', 'FullName'),
                ),
                'document_number' => AzureFields::confidence($f, 'NationalIdNumber') ?? AzureFields::confidence($b, 'NationalIdNumber'),
                'birth' => null,
                'expiry' => AzureFields::confidence($b, 'ExpiryDate') ?? AzureFields::confidence($f, 'ExpiryDate'),
            ],
            gender: AzureFields::gender(AzureFields::first($b, 'Gender') ?? AzureFields::first($f, 'Gender')),
            address: AzureFields::first($f, 'Address'),
            issuingAuthority: AzureFields::first($b, 'IssuingAuthority'),
            consistencyIssues: $issues,
        );
    }
}

<?php

namespace App\Domain\IdentityVerification\Provider\Azure;

use App\Domain\IdentityVerification\Provider\Data\ExtractedIdentityDocument;

/**
 * Maps Microsoft's documented `prebuilt-idDocument` schema (2024-11-30):
 * FirstName / MiddleName / LastName / DocumentNumber / DateOfBirth /
 * DateOfExpiration / Nationality / CountryRegion / MachineReadableZone.
 * Behaviour unchanged from the original single-route provider.
 */
final class PrebuiltIdDocumentMapper implements DocumentFieldMapper
{
    private const KINDS = [
        'idDocument.passport' => ExtractedIdentityDocument::KIND_PASSPORT,
        'idDocument.nationalIdentityCard' => ExtractedIdentityDocument::KIND_NATIONAL_ID,
        'idDocument.residencePermit' => ExtractedIdentityDocument::KIND_RESIDENCE_PERMIT,
        'idDocument.driverLicense' => ExtractedIdentityDocument::KIND_DRIVER_LICENSE,
    ];

    public function map(array $front, ?array $back): ?ExtractedIdentityDocument
    {
        $fields = AzureFields::of($front);

        if ($fields === []) {
            return null;
        }

        $given = trim(implode(' ', array_filter([
            AzureFields::value($fields['FirstName'] ?? null),
            AzureFields::value($fields['MiddleName'] ?? null),
        ])));

        return new ExtractedIdentityDocument(
            kind: self::KINDS[AzureFields::docType($front)] ?? ExtractedIdentityDocument::KIND_OTHER,
            givenNames: $given === '' ? null : $given,
            surname: AzureFields::value($fields['LastName'] ?? null),
            documentNumber: AzureFields::value($fields['DocumentNumber'] ?? null),
            dateOfBirth: AzureFields::value($fields['DateOfBirth'] ?? null),
            dateOfExpiry: AzureFields::value($fields['DateOfExpiration'] ?? null),
            nationality: AzureFields::value($fields['Nationality'] ?? null),
            issuingCountry: AzureFields::value($fields['CountryRegion'] ?? null),
            mrzText: $this->mrzText($fields['MachineReadableZone'] ?? null, (string) ($front['content'] ?? '')),
            confidence: [
                'name' => AzureFields::minConfidence(
                    AzureFields::confidence($fields, 'FirstName'),
                    AzureFields::confidence($fields, 'LastName'),
                ),
                'document_number' => AzureFields::confidence($fields, 'DocumentNumber'),
                'birth' => AzureFields::confidence($fields, 'DateOfBirth'),
                'expiry' => AzureFields::confidence($fields, 'DateOfExpiration'),
            ],
            gender: AzureFields::gender(AzureFields::value($fields['Sex'] ?? null)),
        );
    }

    /** The MRZ field's text, else MRZ-shaped lines from the page text. */
    private function mrzText(mixed $field, string $content): ?string
    {
        if (is_array($field) && is_string($field['content'] ?? null) && trim($field['content']) !== '') {
            return $field['content'];
        }

        $lines = array_filter(
            preg_split('/\R/u', $content) ?: [],
            fn (string $l) => preg_match('/^[A-Z0-9<«\s]{28,}$/', strtoupper(trim($l))) === 1 && str_contains($l, '<'),
        );

        return $lines === [] ? null : implode("\n", $lines);
    }
}

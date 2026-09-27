<?php

namespace App\Domain\IdentityVerification\Provider;

use App\Domain\IdentityVerification\DocumentCheck\IdentityDocumentType;
use App\Domain\IdentityVerification\DocumentCheck\Mrz\MrzParser;
use App\Domain\IdentityVerification\Provider\Contracts\IdentityDocumentProviderInterface;
use App\Domain\IdentityVerification\Provider\Data\DocumentExtractionRequest;
use App\Domain\IdentityVerification\Provider\Data\DocumentExtractionResult;
use App\Domain\IdentityVerification\Provider\Data\ExtractedIdentityDocument;
use InvalidArgumentException;

/**
 * Deterministic document provider for automated tests and local development
 * WITHOUT provider credentials. It performs no OCR: it returns a SYNTHETIC
 * specimen identity (the fictional ICAO 9303 "Utopia" passport holder), with
 * a machine-readable zone whose check digits are computed here — so the real
 * MRZ parser, normalizer, matcher and decision rules all run against it.
 *
 * It is refused in production by the container binding
 * (AppServiceProvider) — production must use the real provider.
 *
 * Scenarios (config `verification.document_providers.dummy.scenario`, or a
 * test-constructed instance): see {@see self::SCENARIOS}.
 */
final class DummyIdentityDocumentProvider implements IdentityDocumentProviderInterface
{
    public const NAME = 'dummy';

    /** The synthetic specimen identity a guest must enter to be verified locally. */
    public const SPECIMEN = [
        'given' => 'ANNA MARIA',
        'surname' => 'ERIKSSON',
        'document_number' => 'L898902C3',
        'date_of_birth' => '1974-08-12',
        'date_of_expiry' => '2034-04-15',
        'nationality' => 'UTO',
    ];

    /**
     * SYNTHETIC specimens for the custom-model document types (fictional
     * people, structurally valid numbers). Used only by tests / local dev —
     * this provider is refused in production.
     */
    public const EGYPT_SPECIMEN = [
        'first_name' => 'سامي',
        'family_names' => 'عادل فؤاد منصور',
        // 2 + 900115 (1990-01-15) + 01 (Cairo) + 1235 (13th digit odd → male) + 7
        'national_id' => '٢٩٠٠١١٥٠١١٢٣٥٧',
        'date_of_birth' => '1990-01-15',
        'expiry' => '2031-01-01',
        'gender' => 'male',
    ];

    public const SAUDI_ID_SPECIMEN = [
        'name_ar' => 'فهد سالم ناصر الحربي',
        'name_en' => 'FAHAD SALEM NASSER ALHARBI',
        'number' => '1098765432',
        'date_of_birth_hijri' => '١٤١٠/٠٣/١٥ هـ',   // = 1989-10-16
        'date_of_birth' => '1989-10-16',
        'expiry' => '1452/01/01',
    ];

    public const SAUDI_IQAMA_SPECIMEN = [
        'name_ar' => 'راجيف كومار شارما',
        'name_en' => 'RAJEEV KUMAR SHARMA',
        'number' => '2098765432',
        'nationality' => 'IND',
        'date_of_birth' => '1985-06-20',
        'expiry' => '1451/06/01',
    ];

    public const SCENARIOS = [
        'specimen_passport',   // valid passport with a valid MRZ
        'expired_passport',    // valid MRZ, expired 2020-04-15
        'bad_mrz_passport',    // MRZ with a broken check digit
        'expired_document',    // the selected type's specimen, already expired
        'national_id_no_mrz',  // national ID card, visual fields only
        'driver_license',      // an unsupported document kind
        'no_document',         // nothing identifiable in the image
        'provider_timeout',    // provider did not answer in time
    ];

    /** @var list<string> artifact references "deleted" — lets tests assert cleanup */
    public array $deleted = [];

    public function __construct(
        private readonly string $scenario = 'specimen_passport',
        private readonly ?ExtractedIdentityDocument $fixed = null,
    ) {
        if ($fixed === null && ! in_array($scenario, self::SCENARIOS, true)) {
            throw new InvalidArgumentException("Unknown dummy document scenario [{$scenario}].");
        }
    }

    /** A provider that always returns the given document (tests). */
    public static function returning(ExtractedIdentityDocument $document): self
    {
        return new self('fixed', $document);
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function extract(DocumentExtractionRequest $request): DocumentExtractionResult
    {
        $artifact = 'dummy-'.substr(hash('sha256', $request->reference), 0, 24);

        if ($this->fixed !== null) {
            return DocumentExtractionResult::extracted($this->fixed, 'succeeded', $artifact);
        }

        $s = self::SPECIMEN;
        $custom = match ($request->documentType) {
            IdentityDocumentType::EgyptianNationalId,
            IdentityDocumentType::SaudiNationalId,
            IdentityDocumentType::SaudiIqama => true,
            default => false,
        };

        if ($custom && ! in_array($this->scenario, ['driver_license', 'no_document', 'provider_timeout'], true)) {
            $expired = in_array($this->scenario, ['expired_passport', 'expired_document'], true);

            return DocumentExtractionResult::extracted($this->customSpecimen($request->documentType, $expired), 'succeeded', $artifact);
        }

        return match ($this->scenario) {
            'specimen_passport' => DocumentExtractionResult::extracted($this->passport($s['date_of_expiry']), 'succeeded', $artifact),
            'expired_passport', 'expired_document' => DocumentExtractionResult::extracted($this->passport('2020-04-15'), 'succeeded', $artifact),
            'bad_mrz_passport' => DocumentExtractionResult::extracted($this->passport($s['date_of_expiry'], breakCheckDigit: true), 'succeeded', $artifact),
            'national_id_no_mrz' => DocumentExtractionResult::extracted(new ExtractedIdentityDocument(
                kind: ExtractedIdentityDocument::KIND_NATIONAL_ID,
                givenNames: $s['given'],
                surname: $s['surname'],
                documentNumber: $s['document_number'],
                dateOfBirth: $s['date_of_birth'],
                dateOfExpiry: $s['date_of_expiry'],
                nationality: $s['nationality'],
                issuingCountry: $s['nationality'],
                confidence: ['name' => 0.97, 'document_number' => 0.98, 'birth' => 0.98, 'expiry' => 0.98],
            ), 'succeeded', $artifact),
            'driver_license' => DocumentExtractionResult::extracted(new ExtractedIdentityDocument(
                kind: ExtractedIdentityDocument::KIND_DRIVER_LICENSE,
                givenNames: $s['given'],
                surname: $s['surname'],
                documentNumber: 'D1234567',
                dateOfBirth: $s['date_of_birth'],
                dateOfExpiry: $s['date_of_expiry'],
            ), 'succeeded', $artifact),
            'no_document' => DocumentExtractionResult::failed(DocumentExtractionResult::FAILURE_NO_DOCUMENT, 'no_document', $artifact),
            'provider_timeout' => DocumentExtractionResult::failed(DocumentExtractionResult::FAILURE_TIMEOUT, 'poll_timeout', $artifact),
        };
    }

    public function deleteArtifact(string $artifactReference): bool
    {
        $this->deleted[] = $artifactReference;

        return true;
    }

    private function customSpecimen(IdentityDocumentType $type, bool $expired): ExtractedIdentityDocument
    {
        $confidence = ['name' => 0.96, 'document_number' => 0.97, 'birth' => 0.97, 'expiry' => 0.97];

        if ($type === IdentityDocumentType::EgyptianNationalId) {
            $e = self::EGYPT_SPECIMEN;

            return new ExtractedIdentityDocument(
                kind: ExtractedIdentityDocument::KIND_NATIONAL_ID,
                givenNames: $e['first_name'],
                surname: $e['family_names'],
                documentNumber: $e['national_id'],
                dateOfExpiry: $expired ? '2020-01-01' : $e['expiry'],
                issuingCountry: 'EGY',
                confidence: $confidence,
                gender: $e['gender'],
            );
        }

        $sp = $type === IdentityDocumentType::SaudiIqama ? self::SAUDI_IQAMA_SPECIMEN : self::SAUDI_ID_SPECIMEN;
        [$given, $surname] = \App\Domain\IdentityVerification\Provider\Azure\AzureFields::splitFullName($sp['name_ar']);
        [$altGiven, $altSurname] = \App\Domain\IdentityVerification\Provider\Azure\AzureFields::splitFullName($sp['name_en']);

        return new ExtractedIdentityDocument(
            kind: $type === IdentityDocumentType::SaudiIqama ? ExtractedIdentityDocument::KIND_RESIDENCE_PERMIT : ExtractedIdentityDocument::KIND_NATIONAL_ID,
            givenNames: $given,
            surname: $surname,
            documentNumber: $sp['number'],
            dateOfBirth: $sp['date_of_birth_hijri'] ?? $sp['date_of_birth'],
            dateOfExpiry: $expired ? '1440/01/01' : $sp['expiry'],
            nationality: $sp['nationality'] ?? null,
            issuingCountry: 'SAU',
            confidence: $confidence,
            alternateGivenNames: $altGiven,
            alternateSurname: $altSurname,
        );
    }

    private function passport(string $expiry, bool $breakCheckDigit = false): ExtractedIdentityDocument
    {
        $s = self::SPECIMEN;

        return new ExtractedIdentityDocument(
            kind: ExtractedIdentityDocument::KIND_PASSPORT,
            givenNames: $s['given'],
            surname: $s['surname'],
            documentNumber: $s['document_number'],
            dateOfBirth: $s['date_of_birth'],
            dateOfExpiry: $expiry,
            nationality: $s['nationality'],
            issuingCountry: $s['nationality'],
            mrzText: self::td3($s['surname'], $s['given'], $s['document_number'], $s['nationality'], $s['date_of_birth'], $expiry, $breakCheckDigit),
            confidence: ['name' => 0.97, 'document_number' => 0.98, 'birth' => 0.98, 'expiry' => 0.98],
        );
    }

    /** Builds a TD3 MRZ with correct ICAO check digits. */
    public static function td3(
        string $surname,
        string $given,
        string $number,
        string $nationality,
        string $dob,
        string $expiry,
        bool $breakCheckDigit = false,
    ): string {
        $names = str_replace(' ', '<', $surname).'<<'.str_replace(' ', '<', $given);
        $line1 = str_pad(substr('P<'.$nationality.$names, 0, 44), 44, '<');

        $num = str_pad($number, 9, '<');
        $numCd = MrzParser::checkDigit($num);
        $numCd = $breakCheckDigit ? ($numCd + 1) % 10 : $numCd;
        $d = (new \DateTimeImmutable($dob))->format('ymd');
        $e = (new \DateTimeImmutable($expiry))->format('ymd');
        $optional = str_repeat('<', 14);

        $body = $num.$numCd.$nationality.$d.MrzParser::checkDigit($d).'F'.$e.MrzParser::checkDigit($e).$optional.'0';
        $composite = MrzParser::checkDigit(substr($body, 0, 10).substr($body, 13, 7).substr($body, 21, 22));

        return $line1."\n".$body.$composite;
    }
}

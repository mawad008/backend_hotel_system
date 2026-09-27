<?php

namespace Tests\Unit\IdentityVerification\DocumentCheck;

use App\Domain\IdentityVerification\DocumentCheck\DocumentCheckEvaluator;
use App\Domain\IdentityVerification\DocumentCheck\DocumentCheckStatus as S;
use App\Domain\IdentityVerification\DocumentCheck\IdentityClaim;
use App\Domain\IdentityVerification\DocumentCheck\IdentityDocumentType as T;
use App\Domain\IdentityVerification\Provider\Data\DocumentExtractionResult;
use App\Domain\IdentityVerification\Provider\Data\ExtractedIdentityDocument as Doc;
use App\Domain\IdentityVerification\Provider\DummyIdentityDocumentProvider;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Egyptian National ID, Saudi National ID and Saudi Iqama decisions.
 * Synthetic values only.
 */
class RegionalDocumentEvaluatorTest extends TestCase
{
    private const ON = '2026-09-27';

    private function eval(Doc|DocumentExtractionResult $doc, IdentityClaim $claim, T $type, bool $auto = true)
    {
        $extraction = $doc instanceof Doc ? DocumentExtractionResult::extracted($doc) : $doc;

        return (new DocumentCheckEvaluator)->evaluate($extraction, $claim, new DateTimeImmutable(self::ON), $type, $auto);
    }

    private function egypt(array $o = []): Doc
    {
        return new Doc(
            kind: $o['kind'] ?? Doc::KIND_NATIONAL_ID,
            givenNames: $o['first'] ?? 'سامي',
            surname: $o['family'] ?? 'عادل فؤاد منصور',
            documentNumber: array_key_exists('number', $o) ? $o['number'] : '٢٩٠٠١١٥٠١١٢٣٥٧',
            dateOfBirth: $o['dob'] ?? null,
            dateOfExpiry: $o['expiry'] ?? '٢٠٣١/٠١/٠١',
            issuingCountry: 'EGY',
            confidence: ['name' => 0.95, 'document_number' => 0.95, 'birth' => null, 'expiry' => 0.95],
            gender: $o['gender'] ?? 'male',
            consistencyIssues: $o['issues'] ?? [],
        );
    }

    private function egyptClaim(array $o = []): IdentityClaim
    {
        return new IdentityClaim(
            fullName: $o['name'] ?? 'سامي عادل فؤاد منصور',
            documentNumber: $o['number'] ?? '29001150112357',
            dateOfBirth: new DateTimeImmutable($o['dob'] ?? '1990-01-15'),
        );
    }

    private function saudi(T $type, array $o = []): Doc
    {
        $iqama = $type === T::SaudiIqama;

        return new Doc(
            kind: $iqama ? Doc::KIND_RESIDENCE_PERMIT : Doc::KIND_NATIONAL_ID,
            givenNames: $iqama ? 'راجيف كومار' : 'فهد سالم ناصر',
            surname: $iqama ? 'شارما' : 'الحربي',
            documentNumber: $o['number'] ?? ($iqama ? '٢٠٩٨٧٦٥٤٣٢' : '١٠٩٨٧٦٥٤٣٢'),
            dateOfBirth: $o['dob'] ?? ($iqama ? '1985-06-20' : '١٤١٠/٠٣/١٥ هـ'),
            dateOfExpiry: $o['expiry'] ?? '1451/06/01',
            nationality: $iqama ? 'IND' : null,
            issuingCountry: 'SAU',
            confidence: ['name' => 0.95, 'document_number' => 0.95, 'birth' => 0.95, 'expiry' => 0.95],
            alternateGivenNames: $iqama ? 'RAJEEV KUMAR' : 'FAHAD SALEM NASSER',
            alternateSurname: $iqama ? 'SHARMA' : 'ALHARBI',
        );
    }

    private function saudiClaim(T $type, array $o = []): IdentityClaim
    {
        $iqama = $type === T::SaudiIqama;

        return new IdentityClaim(
            fullName: $o['name'] ?? ($iqama ? 'راجيف كومار شارما' : 'فهد سالم ناصر الحربي'),
            documentNumber: $o['number'] ?? ($iqama ? '2098765432' : '1098765432'),
            dateOfBirth: new DateTimeImmutable($o['dob'] ?? ($iqama ? '1985-06-20' : '1989-10-16')),
            nationality: $o['nationality'] ?? null,
        );
    }

    // ── Egypt ───────────────────────────────────────────────────────

    public function test_egypt_full_match_is_verified_only_when_auto_verify_is_enabled(): void
    {
        $verified = $this->eval($this->egypt(), $this->egyptClaim(), T::EgyptianNationalId, auto: true);
        $this->assertSame(S::Verified, $verified->status);
        $this->assertSame(['expiry' => 'valid', 'number' => 'match', 'birth' => 'match', 'name' => 'strong', 'nationality' => 'not_provided'], $verified->fields);

        $gated = $this->eval($this->egypt(), $this->egyptClaim(), T::EgyptianNationalId, auto: false);
        $this->assertSame(S::NeedsReview, $gated->status);
        $this->assertSame(['manual_review_required_for_document_type'], $gated->reasons);
    }

    public function test_egypt_birth_date_is_derived_from_the_number(): void
    {
        $out = $this->eval($this->egypt(), $this->egyptClaim(['dob' => '1990-01-16']), T::EgyptianNationalId);

        $this->assertSame(S::Mismatch, $out->status);
        $this->assertSame('mismatch', $out->fields['birth']);
    }

    public function test_egypt_latin_digits_in_claim_match_arabic_digits_on_card(): void
    {
        $this->assertSame('match', $this->eval($this->egypt(), $this->egyptClaim(['number' => '٢٩٠٠١١٥٠١١٢٣٥٧']), T::EgyptianNationalId)->fields['number']);
        $this->assertSame('match', $this->eval($this->egypt(['number' => '29001150112357']), $this->egyptClaim(), T::EgyptianNationalId)->fields['number']);
    }

    public function test_egypt_wrong_number_and_wrong_name(): void
    {
        $this->assertSame(S::Mismatch, $this->eval($this->egypt(), $this->egyptClaim(['number' => '29001150112359']), T::EgyptianNationalId)->status);
        $this->assertSame(S::Mismatch, $this->eval($this->egypt(), $this->egyptClaim(['name' => 'خالد يوسف ابراهيم']), T::EgyptianNationalId)->status);
    }

    public function test_egypt_latin_claim_against_an_arabic_card_is_review_not_verified(): void
    {
        $out = $this->eval($this->egypt(), $this->egyptClaim(['name' => 'Sami Adel Fouad Mansour']), T::EgyptianNationalId);

        $this->assertSame(S::NeedsReview, $out->status);
        $this->assertSame('unverifiable_script', $out->fields['name']);
    }

    public function test_egypt_structurally_invalid_number_is_review(): void
    {
        $out = $this->eval($this->egypt(['number' => '19001150112357']), $this->egyptClaim(['number' => '19001150112357', 'dob' => '1990-01-15']), T::EgyptianNationalId);

        $this->assertSame(S::NeedsReview, $out->status);
        $this->assertContains('national_id_century_invalid', $out->reasons);
        $this->assertContains('birth_date_unreadable', $out->reasons, 'no birth date can be derived from an invalid number');
    }

    public function test_egypt_encoded_gender_and_printed_birth_date_are_cross_checked(): void
    {
        $this->assertContains('gender_encoding_conflict', $this->eval($this->egypt(['gender' => 'female']), $this->egyptClaim(), T::EgyptianNationalId)->reasons);
        $this->assertContains('birth_date_encoding_conflict', $this->eval($this->egypt(['dob' => '1991-01-15']), $this->egyptClaim(['dob' => '1991-01-15']), T::EgyptianNationalId)->reasons);
    }

    public function test_egypt_front_back_conflict_and_expiry(): void
    {
        $this->assertContains('front_back_number_conflict', $this->eval($this->egypt(['issues' => ['front_back_number_conflict']]), $this->egyptClaim(), T::EgyptianNationalId)->reasons);
        $this->assertSame(S::DocumentExpired, $this->eval($this->egypt(['expiry' => '2020-01-01']), $this->egyptClaim(), T::EgyptianNationalId)->status);

        $missing = $this->eval($this->egypt(['expiry' => 'غير مقروء']), $this->egyptClaim(), T::EgyptianNationalId);
        $this->assertSame(S::NeedsReview, $missing->status);
        $this->assertContains('expiry_unreadable', $missing->reasons);
    }

    public function test_egypt_unreadable_number_is_ocr_failed(): void
    {
        $this->assertSame(S::OcrFailed, $this->eval($this->egypt(['number' => null]), $this->egyptClaim(), T::EgyptianNationalId)->status);
    }

    public function test_a_passport_uploaded_as_an_egyptian_id_is_unsupported(): void
    {
        $out = $this->eval($this->egypt(['kind' => Doc::KIND_PASSPORT]), $this->egyptClaim(), T::EgyptianNationalId);

        $this->assertSame(S::DocumentUnsupported, $out->status);
        $this->assertSame(['document_type_mismatch'], $out->reasons);
    }

    // ── Saudi ───────────────────────────────────────────────────────

    public function test_saudi_id_hijri_birth_date_matches_the_gregorian_claim(): void
    {
        $out = $this->eval($this->saudi(T::SaudiNationalId), $this->saudiClaim(T::SaudiNationalId), T::SaudiNationalId);

        $this->assertSame(S::Verified, $out->status);
        $this->assertSame('match', $out->fields['birth']);
    }

    public function test_saudi_latin_claim_uses_the_latin_name_printed_on_the_card(): void
    {
        $out = $this->eval($this->saudi(T::SaudiNationalId), $this->saudiClaim(T::SaudiNationalId, ['name' => 'Fahad Salem Alharbi']), T::SaudiNationalId);

        $this->assertSame('strong', $out->fields['name']);
    }

    public function test_saudi_wrong_number_wrong_dob_expired(): void
    {
        $t = T::SaudiNationalId;
        $this->assertSame(S::Mismatch, $this->eval($this->saudi($t), $this->saudiClaim($t, ['number' => '1098765433']), $t)->status);
        $this->assertSame(S::Mismatch, $this->eval($this->saudi($t), $this->saudiClaim($t, ['dob' => '1989-10-17']), $t)->status);
        $this->assertSame(S::DocumentExpired, $this->eval($this->saudi($t, ['expiry' => '1440/01/01']), $this->saudiClaim($t), $t)->status);
    }

    public function test_saudi_number_prefix_must_fit_the_selected_document(): void
    {
        $out = $this->eval($this->saudi(T::SaudiNationalId, ['number' => '2098765432']), $this->saudiClaim(T::SaudiNationalId, ['number' => '2098765432']), T::SaudiNationalId);

        $this->assertSame(S::NeedsReview, $out->status);
        $this->assertContains('saudi_number_prefix_mismatch', $out->reasons);
    }

    public function test_iqama_is_verified_with_nationality_and_distinguished_from_the_citizen_id(): void
    {
        $t = T::SaudiIqama;
        $this->assertSame(S::Verified, $this->eval($this->saudi($t), $this->saudiClaim($t, ['nationality' => 'IND']), $t)->status);
        $this->assertContains('nationality_differs', $this->eval($this->saudi($t), $this->saudiClaim($t, ['nationality' => 'PAK']), $t)->reasons);

        // A citizen ID read by the Iqama route is the wrong document.
        $this->assertSame(S::DocumentUnsupported, $this->eval($this->saudi(T::SaudiNationalId), $this->saudiClaim($t), $t)->status);
    }

    public function test_iqama_wrong_dob_and_expired(): void
    {
        $t = T::SaudiIqama;
        $this->assertSame(S::Mismatch, $this->eval($this->saudi($t), $this->saudiClaim($t, ['dob' => '1985-06-21']), $t)->status);
        $this->assertSame(S::DocumentExpired, $this->eval($this->saudi($t, ['expiry' => '2025-01-01']), $this->saudiClaim($t), $t)->status);
    }

    // ── Configuration ───────────────────────────────────────────────

    public function test_an_unconfigured_route_is_needs_review_never_verified(): void
    {
        $out = $this->eval(DocumentExtractionResult::failed(DocumentExtractionResult::FAILURE_NOT_CONFIGURED, 'model_not_configured'), $this->egyptClaim(), T::EgyptianNationalId);

        $this->assertSame(S::NeedsReview, $out->status);
        $this->assertSame(['provider_not_configured'], $out->reasons);
        $this->assertTrue($out->status->allowsSelfie());
        $this->assertFalse($out->status->requiresNewDocument());
    }

    public function test_dummy_specimens_are_consistent_with_the_rules(): void
    {
        $this->assertTrue(\App\Domain\IdentityVerification\DocumentCheck\NationalNumbers\EgyptianNationalIdNumber::inspect(DummyIdentityDocumentProvider::EGYPT_SPECIMEN['national_id'])->valid);
    }
}

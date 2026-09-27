<?php

namespace Tests\Unit\IdentityVerification\DocumentCheck;

use App\Domain\IdentityVerification\DocumentCheck\DocumentCheckEvaluator;
use App\Domain\IdentityVerification\DocumentCheck\DocumentCheckStatus as S;
use App\Domain\IdentityVerification\DocumentCheck\IdentityClaim;
use App\Domain\IdentityVerification\Provider\Data\DocumentExtractionResult;
use App\Domain\IdentityVerification\Provider\Data\ExtractedIdentityDocument as Doc;
use App\Domain\IdentityVerification\Provider\DummyIdentityDocumentProvider;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class DocumentCheckEvaluatorTest extends TestCase
{
    private function passport(array $overrides = [], ?string $mrz = 'auto'): Doc
    {
        $f = $overrides + [
            'given' => 'ANNA MARIA', 'surname' => 'ERIKSSON', 'number' => 'L898902C3',
            'dob' => '1974-08-12', 'expiry' => '2034-04-15', 'nationality' => 'UTO', 'kind' => Doc::KIND_PASSPORT,
            'confidence' => ['name' => 0.95, 'document_number' => 0.95, 'birth' => 0.95, 'expiry' => 0.95],
        ];

        if ($mrz === 'auto') {
            $mrz = DummyIdentityDocumentProvider::td3('ERIKSSON', 'ANNA MARIA', 'L898902C3', 'UTO', '1974-08-12', $f['expiry']);
        }

        return new Doc(
            kind: $f['kind'],
            givenNames: $f['given'],
            surname: $f['surname'],
            documentNumber: $f['number'],
            dateOfBirth: $f['dob'],
            dateOfExpiry: $f['expiry'],
            nationality: $f['nationality'],
            issuingCountry: 'UTO',
            mrzText: $mrz,
            confidence: $f['confidence'],
        );
    }

    private function claim(array $o = []): IdentityClaim
    {
        return new IdentityClaim(
            fullName: array_key_exists('name', $o) ? $o['name'] : 'Anna Maria Eriksson',
            documentNumber: array_key_exists('number', $o) ? $o['number'] : 'L898902C3',
            dateOfBirth: array_key_exists('dob', $o) ? $o['dob'] : new DateTimeImmutable('1974-08-12'),
            nationality: $o['nationality'] ?? null,
        );
    }

    private function check(Doc|DocumentExtractionResult $doc, ?IdentityClaim $claim = null, string $on = '2026-09-26', bool $noMrzOk = false)
    {
        $extraction = $doc instanceof Doc ? DocumentExtractionResult::extracted($doc) : $doc;

        return (new DocumentCheckEvaluator(0.80, $noMrzOk))->evaluate($extraction, $claim ?? $this->claim(), new DateTimeImmutable($on));
    }

    public function test_everything_matching_with_a_valid_mrz_is_verified(): void
    {
        $out = $this->check($this->passport());

        $this->assertSame(S::Verified, $out->status);
        $this->assertSame([], $out->reasons);
        $this->assertSame('valid', $out->machineReadableZone);
        $this->assertSame(['expiry' => 'valid', 'number' => 'match', 'birth' => 'match', 'name' => 'strong', 'nationality' => 'not_provided'], $out->fields);
    }

    public function test_extraction_failure_is_ocr_failed(): void
    {
        $out = $this->check(DocumentExtractionResult::failed(DocumentExtractionResult::FAILURE_TIMEOUT, 'poll_timeout'));

        $this->assertSame(S::OcrFailed, $out->status);
        $this->assertSame(['ocr_timeout'], $out->reasons);
    }

    public function test_unreadable_number_is_ocr_failed(): void
    {
        $this->assertSame(S::OcrFailed, $this->check($this->passport(['number' => null], mrz: null))->status);
    }

    public function test_driver_licence_is_unsupported(): void
    {
        $this->assertSame(S::DocumentUnsupported, $this->check($this->passport(['kind' => Doc::KIND_DRIVER_LICENSE]))->status);
        $this->assertSame(S::DocumentUnsupported, $this->check($this->passport(['kind' => Doc::KIND_OTHER]))->status);
    }

    public function test_expiry_is_judged_against_the_check_in_date(): void
    {
        $doc = $this->passport(['expiry' => '2026-10-01']);

        $this->assertSame(S::Verified, $this->check($doc, on: '2026-10-01')->status);
        $this->assertSame(S::DocumentExpired, $this->check($doc, on: '2026-10-02')->status);
    }

    public function test_expired_wins_over_a_mismatch(): void
    {
        $out = $this->check($this->passport(['expiry' => '2020-01-01']), $this->claim(['number' => 'Z999']));

        $this->assertSame(S::DocumentExpired, $out->status);
    }

    public function test_hard_mismatches(): void
    {
        $this->assertSame(S::Mismatch, $this->check($this->passport(), $this->claim(['number' => 'L898902C4']))->status);
        $this->assertSame(S::Mismatch, $this->check($this->passport(), $this->claim(['dob' => new DateTimeImmutable('1974-12-08')]))->status);
        $this->assertSame(S::Mismatch, $this->check($this->passport(), $this->claim(['name' => 'Peter Parker']))->status);
    }

    public function test_number_compare_is_exact_after_normalization(): void
    {
        $this->assertSame(S::Verified, $this->check($this->passport(), $this->claim(['number' => 'l898 902-c3']))->status);
        $this->assertSame(S::Verified, $this->check($this->passport(['number' => '1234567'], mrz: DummyIdentityDocumentProvider::td3('ERIKSSON', 'ANNA MARIA', '1234567', 'UTO', '1974-08-12', '2034-04-15')), $this->claim(['number' => '١٢٣٤٥٦٧']))->status);
    }

    public function test_ocr_confusable_number_is_review_not_accept(): void
    {
        $out = $this->check($this->passport(), $this->claim(['number' => 'L89B9O2C3']));

        $this->assertSame(S::NeedsReview, $out->status);
        $this->assertSame('confusable', $out->fields['number']);
        $this->assertContains('document_number_ocr_ambiguous', $out->reasons);
    }

    public function test_weak_or_cross_script_name_needs_review(): void
    {
        $this->assertSame(S::NeedsReview, $this->check($this->passport(), $this->claim(['name' => 'Eriksson']))->status);
        $this->assertSame(S::NeedsReview, $this->check($this->passport(), $this->claim(['name' => 'آنا إريكسون']))->status);
    }

    public function test_passport_mrz_rules(): void
    {
        $missing = $this->check($this->passport(mrz: null));
        $this->assertSame(S::NeedsReview, $missing->status);
        $this->assertContains('passport_mrz_missing', $missing->reasons);

        $bad = $this->check($this->passport(mrz: DummyIdentityDocumentProvider::td3('ERIKSSON', 'ANNA MARIA', 'L898902C3', 'UTO', '1974-08-12', '2034-04-15', breakCheckDigit: true)));
        $this->assertSame(S::NeedsReview, $bad->status);
        $this->assertContains('mrz_check_digits_failed', $bad->reasons);
    }

    public function test_mrz_that_disagrees_with_the_printed_page_needs_review(): void
    {
        $out = $this->check($this->passport(['number' => 'L898902C8']));

        $this->assertSame(S::NeedsReview, $out->status);
        $this->assertContains('mrz_visual_conflict', $out->reasons);
        $this->assertSame('match', $out->fields['number'], 'the check-digit-verified MRZ is authoritative');
    }

    public function test_low_confidence_unproven_fields_need_review(): void
    {
        $doc = $this->passport(['kind' => Doc::KIND_NATIONAL_ID, 'confidence' => ['name' => 0.95, 'document_number' => 0.50, 'birth' => 0.95, 'expiry' => 0.95]], mrz: null);

        $this->assertContains('low_confidence', $this->check($doc, noMrzOk: true)->reasons);
    }

    public function test_national_id_without_mrz_is_review_unless_explicitly_enabled(): void
    {
        $doc = $this->passport(['kind' => Doc::KIND_NATIONAL_ID], mrz: null);

        $this->assertSame(S::NeedsReview, $this->check($doc)->status);
        $this->assertSame(S::Verified, $this->check($doc, noMrzOk: true)->status);
    }

    public function test_incomplete_claim_can_never_be_verified(): void
    {
        $out = $this->check($this->passport(), $this->claim(['number' => null, 'dob' => null]));

        $this->assertSame(S::NeedsReview, $out->status);
        $this->assertContains('claim_incomplete', $out->reasons);
    }

    public function test_nationality_difference_is_review_not_rejection(): void
    {
        $this->assertSame(S::Verified, $this->check($this->passport(), $this->claim(['nationality' => 'UTO']))->status);
        $this->assertSame(S::NeedsReview, $this->check($this->passport(), $this->claim(['nationality' => 'SAU']))->status);
    }

    public function test_outcome_array_contains_codes_only(): void
    {
        $json = json_encode($this->check($this->passport())->toArray());

        foreach (['L898902C3', '1974', 'ERIKSSON', 'ANNA', 'P<UTO'] as $pii) {
            $this->assertStringNotContainsString($pii, $json);
        }
    }
}

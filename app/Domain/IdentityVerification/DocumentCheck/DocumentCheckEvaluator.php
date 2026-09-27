<?php

namespace App\Domain\IdentityVerification\DocumentCheck;

use App\Domain\IdentityVerification\DocumentCheck\Matching\NameMatcher;
use App\Domain\IdentityVerification\DocumentCheck\Mrz\MrzParser;
use App\Domain\IdentityVerification\DocumentCheck\NationalNumbers\NationalNumberRules;
use App\Domain\IdentityVerification\DocumentCheck\Mrz\ParsedMrz;
use App\Domain\IdentityVerification\Provider\Data\DocumentExtractionResult;
use App\Domain\IdentityVerification\Provider\Data\ExtractedIdentityDocument;
use DateTimeImmutable;

/**
 * Turns an extraction + the guest's claim into a {@see DocumentCheckOutcome}.
 * Pure: no I/O, no clock (the "valid on" date is passed in), no config reads.
 *
 * Decision precedence (first that applies wins):
 *   OCR_FAILED            extraction failed, or name / document number unreadable
 *   DOCUMENT_UNSUPPORTED  driver licence / unknown document kind
 *   DOCUMENT_EXPIRED      expiry date before the stay's check-in date
 *   MISMATCH              document number, date of birth or name contradicts the claim
 *   NEEDS_REVIEW          anything unconfirmed: weak/cross-script name, OCR-confusable
 *                         number, missing/low-confidence field, MRZ missing / invalid /
 *                         disagreeing with the printed page, claim incomplete,
 *                         no MRZ on a non-passport (when auto-verify requires one)
 *   VERIFIED              everything compared matched strongly
 *
 * Per document type (the guest's explicit selection):
 *  - the extracted kind must be one the type accepts (a passport uploaded
 *    as an Egyptian ID is DOCUMENT_UNSUPPORTED / `document_type_mismatch`);
 *  - national-number STRUCTURE rules run for Egyptian and Saudi documents
 *    (century / encoded birth date / governorate / prefix) — supplementary
 *    evidence only, never proof of authenticity; the Egyptian birth date is
 *    derived from the number when the card does not print one;
 *  - a type whose automatic verification is not enabled
 *    (`document_types.<type>.auto_verify`) can at best reach NEEDS_REVIEW:
 *    successful extraction alone never verifies anyone;
 *  - an unconfigured route (`not_configured`) is NEEDS_REVIEW, never
 *    VERIFIED and never a dummy result.
 *
 * Several independent fields must agree — the decision never rests on a
 * single fuzzy string comparison. For passports the MRZ, once its check
 * digits verify, is the authoritative source for number / dates; the printed
 * page is cross-checked against it.
 */
final class DocumentCheckEvaluator
{
    public const SUPPORTED_KINDS = [
        ExtractedIdentityDocument::KIND_PASSPORT,
        ExtractedIdentityDocument::KIND_NATIONAL_ID,
        ExtractedIdentityDocument::KIND_RESIDENCE_PERMIT,
    ];

    public function __construct(
        private readonly float $minFieldConfidence = 0.80,
        private readonly bool $autoVerifyWithoutMrz = false,
    ) {}

    /**
     * @param  IdentityDocumentType|null  $type  the guest's selection (null = legacy generic route)
     * @param  bool  $autoVerify  whether this type may end VERIFIED automatically
     */
    public function evaluate(
        DocumentExtractionResult $extraction,
        IdentityClaim $claim,
        DateTimeImmutable $validOn,
        ?IdentityDocumentType $type = null,
        bool $autoVerify = true,
    ): DocumentCheckOutcome {
        $type ??= IdentityDocumentType::OtherIdentityDocument;
        $doc = $extraction->document;

        if ($doc === null) {
            // A missing production route is a configuration problem, not an
            // unreadable photo: route to a human instead of asking the guest
            // to retake it — and never pass it.
            if ($extraction->failure === DocumentExtractionResult::FAILURE_NOT_CONFIGURED) {
                return new DocumentCheckOutcome(DocumentCheckStatus::NeedsReview, ['provider_not_configured']);
            }

            return new DocumentCheckOutcome(DocumentCheckStatus::OcrFailed, ['ocr_'.($extraction->failure ?? 'failed')]);
        }

        $kind = $doc->kind;
        $issuing = IdentityTextNormalizer::country($doc->issuingCountry);

        if (! in_array($kind, self::SUPPORTED_KINDS, true)) {
            return new DocumentCheckOutcome(DocumentCheckStatus::DocumentUnsupported, ['document_kind_unsupported'], documentKind: $kind, issuingCountry: $issuing);
        }

        if (! in_array($kind, $type->acceptedKinds(), true)
            || ($type->issuingCountry() !== null && $issuing !== null && $issuing !== $type->issuingCountry())) {
            return new DocumentCheckOutcome(DocumentCheckStatus::DocumentUnsupported, ['document_type_mismatch'], documentKind: $kind, issuingCountry: $issuing);
        }

        $mrz = MrzParser::parse($doc->mrzText);
        $mrzState = $mrz === null ? 'absent' : ($mrz->isValid() ? 'valid' : 'invalid');
        $trustedMrz = $mrz !== null && $mrz->isValid() ? $mrz : null;
        $reasons = [];

        // ── Authoritative values: a check-digit-verified MRZ wins over the printed page.
        $number = $trustedMrz?->documentNumber ?? $doc->documentNumber;
        $birth = $trustedMrz?->dateOfBirth ?? IdentityDateParser::parse($doc->dateOfBirth);
        $expiry = $trustedMrz?->dateOfExpiry ?? IdentityDateParser::parse($doc->dateOfExpiry);
        $nationality = IdentityTextNormalizer::country($trustedMrz?->nationality ?? $doc->nationality);
        $issuing ??= IdentityTextNormalizer::country($trustedMrz?->issuingState);

        [$given, $surname, $truncated] = $doc->surname !== null || $doc->givenNames !== null
            ? [(string) $doc->givenNames, (string) $doc->surname, false]
            : [(string) $trustedMrz?->givenNames, (string) $trustedMrz?->surname, (bool) $trustedMrz?->namesTruncated];

        if (($number === null || $number === '') || trim($given.$surname) === '') {
            return new DocumentCheckOutcome(DocumentCheckStatus::OcrFailed, ['ocr_key_fields_unreadable'], documentKind: $kind, issuingCountry: $issuing, machineReadableZone: $mrzState);
        }

        // ── National-number structure (Egypt / Saudi) — supplementary only.
        foreach ($doc->consistencyIssues as $issue) {
            $reasons[] = (string) $issue;
        }

        $inspection = NationalNumberRules::inspect($type, $number, $validOn);

        if ($inspection !== null && ! $inspection->valid) {
            array_push($reasons, ...$inspection->reasons);
        }

        if ($inspection?->birthDate !== null) {
            if ($birth === null) {
                $birth = $inspection->birthDate; // Egyptian cards encode it in the number
            } elseif ($birth->format('Y-m-d') !== $inspection->birthDate->format('Y-m-d')) {
                $reasons[] = 'birth_date_encoding_conflict';
            }
        }

        if ($inspection?->gender !== null && $doc->gender !== null && $inspection->gender !== $doc->gender) {
            $reasons[] = 'gender_encoding_conflict';
        }

        // ── Expiry.
        $fields = [];

        if ($expiry === null) {
            $fields['expiry'] = 'missing';
            $reasons[] = 'expiry_unreadable';
        } elseif ($expiry < $validOn->setTime(0, 0)) {
            $fields['expiry'] = 'expired';

            return new DocumentCheckOutcome(DocumentCheckStatus::DocumentExpired, ['document_expired'], $fields, documentKind: $kind, issuingCountry: $issuing, machineReadableZone: $mrzState);
        } else {
            $fields['expiry'] = 'valid';
        }

        // ── MRZ requirements and cross-check against the printed page.
        if ($kind === ExtractedIdentityDocument::KIND_PASSPORT && $mrz === null) {
            $reasons[] = 'passport_mrz_missing';
        }

        if ($mrz !== null && ! $mrz->isValid()) {
            $reasons[] = 'mrz_check_digits_failed';
        }

        if ($trustedMrz !== null && self::conflicts($trustedMrz, $doc)) {
            $reasons[] = 'mrz_visual_conflict';
        }

        if ($type->usesPrebuiltModel() && $kind !== ExtractedIdentityDocument::KIND_PASSPORT && $trustedMrz === null && ! $this->autoVerifyWithoutMrz) {
            $reasons[] = 'no_mrz_auto_verification_unavailable';
        }

        // ── Provider confidence (only for values not proven by MRZ check digits).
        foreach (['name', 'document_number', 'birth', 'expiry'] as $f) {
            $proven = $trustedMrz !== null && $f !== 'name';
            $c = $doc->confidenceOf($f);

            if (! $proven && $c !== null && $c < $this->minFieldConfidence) {
                $reasons[] = 'low_confidence';

                break;
            }
        }

        // ── Compare against the claim.
        $hardMismatch = false;

        if ($claim->documentNumber === null || $claim->documentNumber === '') {
            $fields['number'] = 'not_provided';
        } else {
            $a = IdentityTextNormalizer::documentNumber($claim->documentNumber);
            $b = IdentityTextNormalizer::documentNumber($number);

            if ($a === $b) {
                $fields['number'] = 'match';
            } elseif (IdentityTextNormalizer::confusableSkeleton($a) === IdentityTextNormalizer::confusableSkeleton($b)) {
                $fields['number'] = 'confusable';
                $reasons[] = 'document_number_ocr_ambiguous';
            } else {
                $fields['number'] = 'mismatch';
                $hardMismatch = true;
            }
        }

        if ($claim->dateOfBirth === null) {
            $fields['birth'] = 'not_provided';
        } elseif ($birth === null) {
            $fields['birth'] = 'missing';
            $reasons[] = 'birth_date_unreadable';
        } elseif ($claim->dateOfBirth->format('Y-m-d') === $birth->format('Y-m-d')) {
            $fields['birth'] = 'match';
        } else {
            $fields['birth'] = 'mismatch';
            $hardMismatch = true;
        }

        $nameScore = null;

        if ($claim->fullName === null || trim($claim->fullName) === '') {
            $fields['name'] = 'not_provided';
        } else {
            ['result' => $nameResult, 'score' => $nameScore] = NameMatcher::compare($claim->fullName, $given, $surname, $truncated);

            // A card printing the name in both scripts: compare with the one
            // in the guest's script — never a transliteration.
            if ($nameResult === NameMatcher::RESULT_UNVERIFIABLE_SCRIPT
                && ($doc->alternateGivenNames !== null || $doc->alternateSurname !== null)) {
                ['result' => $nameResult, 'score' => $nameScore] = NameMatcher::compare(
                    $claim->fullName,
                    (string) $doc->alternateGivenNames,
                    (string) $doc->alternateSurname,
                );
            }

            $fields['name'] = $nameResult;

            match ($nameResult) {
                NameMatcher::RESULT_MISMATCH => $hardMismatch = true,
                NameMatcher::RESULT_WEAK => $reasons[] = 'name_weak_match',
                NameMatcher::RESULT_UNVERIFIABLE_SCRIPT => $reasons[] = 'name_script_differs',
                default => null,
            };
        }

        if ($claim->nationality === null) {
            $fields['nationality'] = 'not_provided';
        } elseif ($nationality === null) {
            $fields['nationality'] = 'missing';
        } elseif ($claim->nationality === $nationality) {
            $fields['nationality'] = 'match';
        } else {
            // Dual nationals exist — a differing nationality is reviewed, not rejected.
            $fields['nationality'] = 'mismatch';
            $reasons[] = 'nationality_differs';
        }

        if (in_array('not_provided', [$fields['number'], $fields['birth'], $fields['name']], true)) {
            $reasons[] = 'claim_incomplete';
        }

        $status = match (true) {
            $hardMismatch => DocumentCheckStatus::Mismatch,
            $reasons !== [] => DocumentCheckStatus::NeedsReview,
            default => DocumentCheckStatus::Verified,
        };

        if ($hardMismatch) {
            $reasons = ['claim_mismatch', ...$reasons];
        }

        // Extraction success is not verification: a type without enabled
        // automatic verification always goes to a human.
        if ($status === DocumentCheckStatus::Verified && ! $autoVerify) {
            $status = DocumentCheckStatus::NeedsReview;
            $reasons[] = 'manual_review_required_for_document_type';
        }

        return new DocumentCheckOutcome(
            status: $status,
            reasons: array_values(array_unique($reasons)),
            fields: $fields,
            nameScore: $nameScore,
            documentKind: $kind,
            issuingCountry: $issuing,
            machineReadableZone: $mrzState,
        );
    }

    /** A verified MRZ that disagrees with the printed page (number / dates). */
    private static function conflicts(ParsedMrz $mrz, ExtractedIdentityDocument $doc): bool
    {
        if ($doc->documentNumber !== null
            && IdentityTextNormalizer::documentNumber($doc->documentNumber) !== IdentityTextNormalizer::documentNumber($mrz->documentNumber)) {
            return true;
        }

        $visualBirth = IdentityDateParser::parse($doc->dateOfBirth);
        $visualExpiry = IdentityDateParser::parse($doc->dateOfExpiry);

        return ($visualBirth !== null && $mrz->dateOfBirth !== null && $visualBirth->format('Y-m-d') !== $mrz->dateOfBirth->format('Y-m-d'))
            || ($visualExpiry !== null && $mrz->dateOfExpiry !== null && $visualExpiry->format('Y-m-d') !== $mrz->dateOfExpiry->format('Y-m-d'));
    }
}

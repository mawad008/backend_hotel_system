<?php

namespace App\Domain\IdentityVerification\DocumentCheck;

use App\Domain\IdentityVerification\Provider\Data\ExtractedIdentityDocument;

/**
 * The identity documents the guest can choose, selected EXPLICITLY by the
 * guest (never guessed from OCR text). Each case decides which extraction
 * route runs ({@see \App\Domain\IdentityVerification\Provider\DocumentProviderRouter}),
 * which document kinds are acceptable, and which number rules apply.
 *
 * Adding a country/document later = a new case + its config block in
 * config/verification.php (`document_types`) + a field mapper for its
 * custom model + (optionally) number rules. Nothing else branches on it.
 */
enum IdentityDocumentType: string
{
    /** Any country's passport — Azure prebuilt ID model + MRZ validation. */
    case Passport = 'passport';

    /** Egyptian National ID card (Arabic, front + back) — custom model. */
    case EgyptianNationalId = 'egyptian_national_id';

    /** Saudi National ID card (citizens, number starts with 1) — custom model. */
    case SaudiNationalId = 'saudi_national_id';

    /** Saudi Iqama / resident identity (number starts with 2) — custom model. */
    case SaudiIqama = 'saudi_iqama';

    /**
     * Backward compatibility with clients that sent the earlier generic
     * labels (`national_id`, `residence_permit`) or no type at all: the
     * prebuilt ID model, any ID kind, never auto-verified without a
     * check-digit-verified MRZ (the pre-existing behaviour).
     */
    case OtherIdentityDocument = 'other_id';

    /** Earlier generic wire labels, still accepted from old app builds. */
    public const LEGACY_ALIASES = ['national_id', 'residence_permit'];

    /** Resolve request input (canonical value, legacy alias or null). */
    public static function fromInput(?string $value): self
    {
        if ($value === null || $value === '') {
            return self::OtherIdentityDocument;
        }

        return self::tryFrom(strtolower(trim($value))) ?? self::OtherIdentityDocument;
    }

    /** Values a request may send. */
    public static function acceptedInputs(): array
    {
        return [...array_map(fn (self $t) => $t->value, self::cases()), ...self::LEGACY_ALIASES];
    }

    /** ISO 3166-1 alpha-3 of the issuing state, when the type fixes it. */
    public function issuingCountry(): ?string
    {
        return match ($this) {
            self::EgyptianNationalId => 'EGY',
            self::SaudiNationalId, self::SaudiIqama => 'SAU',
            default => null,
        };
    }

    /** Extracted document kinds that are acceptable for this selection. */
    public function acceptedKinds(): array
    {
        return match ($this) {
            self::Passport => [ExtractedIdentityDocument::KIND_PASSPORT],
            self::EgyptianNationalId, self::SaudiNationalId => [ExtractedIdentityDocument::KIND_NATIONAL_ID],
            self::SaudiIqama => [ExtractedIdentityDocument::KIND_RESIDENCE_PERMIT],
            self::OtherIdentityDocument => [
                ExtractedIdentityDocument::KIND_PASSPORT,
                ExtractedIdentityDocument::KIND_NATIONAL_ID,
                ExtractedIdentityDocument::KIND_RESIDENCE_PERMIT,
            ],
        };
    }

    /** Uses the Azure prebuilt ID model (vs a trained custom model). */
    public function usesPrebuiltModel(): bool
    {
        return $this === self::Passport || $this === self::OtherIdentityDocument;
    }

    /** The date of birth is encoded in the document number (Egypt). */
    public function numberEncodesBirthDate(): bool
    {
        return $this === self::EgyptianNationalId;
    }
}

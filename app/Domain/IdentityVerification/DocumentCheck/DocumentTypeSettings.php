<?php

namespace App\Domain\IdentityVerification\DocumentCheck;

/**
 * The configured behaviour of one {@see IdentityDocumentType}
 * (config/verification.php → `document_types.<type>`). Contains no secret —
 * safe for the staff configuration endpoint; the guest endpoint exposes only
 * the UX parts (sides, whether an automatic check is available).
 */
final class DocumentTypeSettings
{
    public const BACK_NONE = 'none';

    public const BACK_OPTIONAL = 'optional';

    public const BACK_REQUIRED = 'required';

    public function __construct(
        public readonly IdentityDocumentType $type,
        public readonly bool $enabled,
        /** Azure model id, or null when not configured (never a dummy fallback). */
        public readonly ?string $model,
        public readonly string $back,
        /**
         * Whether a fully matching document may be `verified` automatically.
         * Off for the custom-model types until their model has been
         * evaluated on an authorized dataset — they then always end in
         * manual review.
         */
        public readonly bool $autoVerify,
    ) {}

    public function modelConfigured(): bool
    {
        return $this->model !== null && $this->model !== '';
    }

    public function backRequired(): bool
    {
        return $this->back === self::BACK_REQUIRED;
    }

    public function backAllowed(): bool
    {
        return $this->back !== self::BACK_NONE;
    }
}

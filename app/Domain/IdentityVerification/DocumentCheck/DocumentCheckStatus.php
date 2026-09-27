<?php

namespace App\Domain\IdentityVerification\DocumentCheck;

/**
 * The outcome of the OCR document check that runs when an ID document is
 * uploaded (before the selfie/face match). Stored on the attempt as
 * `document_check_status`; it is NOT a session status — the session stays
 * DOCUMENT_UPLOADED and the approved state machine is unchanged.
 *
 *  - verified             every compared field matched strongly, the document
 *                         is supported and in date → the selfie step opens.
 *  - needs_review         nothing contradicts the guest, but something could
 *                         not be confirmed automatically → the selfie step
 *                         opens, and the session can only end in
 *                         PENDING_MANUAL_REVIEW (never AUTO_APPROVED).
 *  - mismatch             a compared field contradicts what the guest entered.
 *  - ocr_failed           the document could not be read (retake the photo).
 *  - document_expired     the document expires before the stay starts.
 *  - document_unsupported a document type this integration does not accept.
 *  - processing           the OCR call is in flight (transient).
 */
enum DocumentCheckStatus: string
{
    case Verified = 'verified';

    case NeedsReview = 'needs_review';

    case Mismatch = 'mismatch';

    case OcrFailed = 'ocr_failed';

    case DocumentExpired = 'document_expired';

    case DocumentUnsupported = 'document_unsupported';

    case Processing = 'processing';

    /** Statuses that let the guest continue to the selfie step. */
    public function allowsSelfie(): bool
    {
        return $this === self::Verified || $this === self::NeedsReview;
    }

    /** Statuses where the guest must upload a (different / clearer) document. */
    public function requiresNewDocument(): bool
    {
        return in_array($this, [self::Mismatch, self::OcrFailed, self::DocumentExpired, self::DocumentUnsupported], true);
    }
}

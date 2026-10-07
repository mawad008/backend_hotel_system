<?php

namespace App\Domain\Notification\Enums;

/**
 * An operational event that lands in the dashboard staff inbox.
 *
 * Every value maps from an audit action that the owning domain already
 * records (see forAuditAction) — no business event is invented here. An
 * audit action with no entry simply produces no staff notification.
 */
enum StaffNotificationType: string
{
    case BookingCreated = 'booking_created';
    case BookingCancelled = 'booking_cancelled';
    case DepositReceived = 'deposit_received';
    case PaymentIssue = 'payment_issue';
    case IdentityReviewRequired = 'identity_review_required';
    case GuestVerified = 'guest_verified';
    case AccessIssued = 'access_issued';
    case AccessIssueFailed = 'access_issue_failed';
    case CheckoutCompleted = 'checkout_completed';
    case InvoiceIssued = 'invoice_issued';
    case ServiceRequested = 'service_requested';
    case ProblemReported = 'problem_reported';
    case ReviewSubmitted = 'review_submitted';

    public static function forAuditAction(string $action): ?self
    {
        return match ($action) {
            'reservation.created' => self::BookingCreated,
            'reservation.cancelled' => self::BookingCancelled,
            'payment.hold_succeeded' => self::DepositReceived,
            'payment.settlement_failed',
            'payment.hold_release_failed',
            'checkout.settlement_failed' => self::PaymentIssue,
            'identity_verification.manual_review_required' => self::IdentityReviewRequired,
            'identity_verification.reservation_verified' => self::GuestVerified,
            'digital_access.issued' => self::AccessIssued,
            'digital_access.issue_failed' => self::AccessIssueFailed,
            'checkout.completed' => self::CheckoutCompleted,
            'invoice.issued' => self::InvoiceIssued,
            'service_order.created' => self::ServiceRequested,
            'problem_report.submitted' => self::ProblemReported,
            'review.submitted' => self::ReviewSubmitted,
            default => null,
        };
    }

    /**
     * Events a staff member is expected to act on, as opposed to FYI.
     */
    public function needsAttention(): bool
    {
        return in_array($this, [
            self::PaymentIssue,
            self::IdentityReviewRequired,
            self::AccessIssueFailed,
            self::ServiceRequested,
            self::ProblemReported,
        ], true);
    }
}

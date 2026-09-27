<?php

return [
    'login_success' => 'Logged in successfully.',
    'logout_success' => 'Logged out successfully.',
    'created' => 'Created successfully.',
    'updated' => 'Updated successfully.',
    'deleted' => 'Deleted successfully.',
    'unauthenticated' => 'Unauthenticated.',
    'forbidden' => 'This action is unauthorized.',
    'not_found' => 'The requested resource was not found.',
    'validation_failed' => 'The given data was invalid.',
    'invalid_credentials' => 'The provided credentials are incorrect.',
    'account_inactive' => 'This account has been deactivated.',
    'server_error' => 'Something went wrong. Please try again later.',

    'location' => [
        'city_country_mismatch' => 'The selected city does not belong to the selected country.',
        'country_delete_blocked' => 'This country still has cities or hotels and cannot be deleted. Deactivate it instead.',
        'city_delete_blocked' => 'This city is still referenced by one or more hotels and cannot be deleted. Deactivate it instead.',
    ],

    'facility' => [
        'delete_blocked' => 'This facility is still assigned to one or more hotels and cannot be deleted. Deactivate it instead.',
    ],

    'hotel' => [
        'delete_has_reservations' => 'This hotel has :count reservation(s) and cannot be deleted. Keep it inactive instead.',
        'delete_has_history' => 'This hotel has operational records and cannot be deleted.',
    ],

    'hotel_media' => [
        'gallery_full' => 'This hotel already has the maximum of :max gallery images.',
        'reorder_mismatch' => 'The ids must be exactly the hotel gallery, in the desired order.',
    ],

    'room_media' => [
        'gallery_full' => 'This already has the maximum of :max gallery images.',
        'reorder_mismatch' => 'The ids must be exactly the gallery, in the desired order.',
    ],

    'problem_reports' => [
        'list' => 'Problem reports are returned.',
        'status' => 'The problem report is returned.',
        'submitted' => 'Your problem report was submitted.',
        'in_progress' => 'The problem report is being worked on.',
        'resolved' => 'The problem report was resolved.',
    ],

    'reservation' => [
        'cancellation_not_allowed' => 'This reservation can no longer be cancelled under its cancellation policy.',
        'refund_failed' => 'The deposit could not be released right now, so the reservation was not cancelled. Please try again.',
        'check_in_mode_disallows' => 'Check-in for this hotel is not available through this channel.',
        'room_not_assigned' => 'Your room is being prepared — check-in opens once reception assigns it.',
        'deposit_not_required' => 'No deposit is required for this hotel; the reservation is confirmed.',
    ],
    'guest_booking' => [
        'room_type_unavailable' => 'This room type is not available for booking.',
        'party_exceeds_capacity' => 'This room type seats up to :capacity guests.',
        'no_payment' => 'No deposit payment has been started for this reservation.',
        'deposit_rule_undefined' => 'Online deposit payment is not available yet.',
    ],

    'guest_auth' => [
        'data_deletion_requested' => 'Your data deletion request was received. The hotel team will contact you.',
        'otp_sent' => 'A verification code has been sent.',
        'verified' => 'Your phone number has been verified.',
        'otp_incorrect' => 'The verification code is incorrect.',
        'otp_locked_out' => 'Too many incorrect attempts. Request a new code.',
        'otp_challenge_invalid' => 'This verification request is no longer valid. Request a new code.',
        'otp_challenge_expired' => 'The verification code has expired. Request a new code.',
        'otp_resend_cooldown' => 'Please wait before requesting another code.',
    ],

    'payment' => [
        'hold_placed' => 'The deposit hold was placed successfully.',
        'hold_pending' => 'The deposit hold is awaiting confirmation from the payment provider.',
        'hold_failed' => 'The payment hold was declined by the provider.',
        'hold_cancelled' => 'The payment hold was cancelled by the provider.',
        'hold_expired' => 'The payment hold expired before it could be confirmed.',
        'hold_state' => 'The current payment state is returned.',
        'ledger' => 'The hotel payments ledger is returned.',

        'webhook_processed' => 'The webhook was processed.',
        'webhook_duplicate_ignored' => 'The webhook was a duplicate and was ignored.',
        'webhook_unmatched' => 'The webhook was received but no matching payment was found.',
        'webhook_invalid_signature' => 'The webhook signature is invalid.',
        'webhook_malformed' => 'The webhook payload could not be processed.',
    ],

    'identity_verification' => [
        'document_submitted' => 'The identity document was received.',
        'status' => 'The current identity verification state is returned.',
        'not_started' => 'Identity verification has not been started for this reservation.',
        'document_uploaded' => 'The identity document was received.',
        'selfie_captured' => 'The selfie was received and is being matched.',
        'matching_in_progress' => 'The identity match is in progress.',
        'auto_approved' => 'Identity verification passed automatically.',
        'pending_manual_review' => 'Identity verification requires a manual review.',
        'retry_allowed' => 'The identity match could not be completed; a new attempt is allowed.',
        'staff_approved' => 'Identity verification was approved by staff.',
        'staff_rejected' => 'Identity verification was rejected by staff.',
    ],

    'digital_access' => [
        'checked_in' => 'Check-in complete. A digital access credential has been issued.',
        'issue_failed' => 'The access-control provider could not issue a credential. Check-in can be retried.',
        'status' => 'The current digital access state is returned.',
        'revoked' => 'The digital access credential has been revoked.',
        'state' => 'The current digital access state is returned.',
    ],

    'stay_services' => [
        'folio' => 'The current reservation folio is returned.',
        'folio_ledger' => 'The hotel folio ledger is returned.',
    ],

    'audit' => [
        'log' => 'The audit log is returned.',
    ],

    'reports' => [
        'occupancy' => 'The occupancy report is returned.',
        'revenue' => 'The revenue report is returned.',
        'hotel_comparison' => 'The hotel comparison report is returned.',
        'reservations' => 'The reservations report is returned.',
        'payments' => 'The payments report is returned.',
        'services' => 'The services report is returned.',
        'loyalty' => 'The loyalty report is returned.',
        'reviews' => 'The reviews report is returned.',
    ],

    'front_desk' => [
        'arrivals' => 'Today\'s arrivals are returned.',
        'departures' => 'Today\'s departures are returned.',
        'in_house' => 'The in-house guest list is returned.',
    ],

    'checkout' => [
        'completed' => 'Checkout is complete and the invoice has been issued.',
        'settlement_pending' => 'The final settlement is awaiting confirmation from the payment provider. Checkout is not complete.',
        'settlement_failed' => 'The final settlement was not successful. Checkout is not complete and can be retried.',
        'invoice' => 'The reservation invoice is returned.',
        'invoices_ledger' => 'The hotel invoices ledger is returned.',
        'settlements_ledger' => 'The hotel settlements ledger is returned.',
    ],

    'loyalty' => [
        'account' => 'The guest loyalty account is returned.',
        'transactions' => 'The guest loyalty ledger is returned.',
        'earned' => 'Loyalty points were accrued for the completed stay.',
        'redeemed' => 'Loyalty points were redeemed against the booking.',
        'rule' => 'The hotel group loyalty rule is returned.',
    ],

    'notifications' => [
        'feed' => 'The reservation notification feed is returned.',
        'read' => 'The notification was marked as read.',
        'read_all' => 'The unread notifications were marked as read.',
    ],

    'reviews' => [
        'categories' => 'The review categories are returned.',
        'analytics' => 'The review analytics are returned.',
        'list' => 'The reviews are returned.',
        'status' => 'The review is returned.',
        'submitted' => 'The review was submitted.',
        'already_reviewed' => 'A review already exists for this reservation.',
        'published' => 'The review was published.',
        'rejected' => 'The review was rejected.',
    ],

    'role' => [
        'system_delete_blocked' => 'This role is a protected system role and cannot be deleted.',
        'assigned_delete_blocked' => 'This role is assigned to one or more users and cannot be deleted. Reassign those users to another role first.',
    ],
];

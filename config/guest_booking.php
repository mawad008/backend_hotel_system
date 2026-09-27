<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Guest booking API
    |--------------------------------------------------------------------------
    |
    | The authenticated guest surface for the booking funnel
    | (/api/v1/guest/reservations, /api/v1/guest/reservations/{id}/payment).
    | These endpoints reuse the shared ReservationService /
    | PaymentWorkflowService — nothing here is a business rule, only
    | transport concerns (pagination, rate limiting).
    |
    */

    'pagination' => [
        'per_page' => (int) env('GUEST_BOOKING_PER_PAGE', 15),
        'max_per_page' => (int) env('GUEST_BOOKING_MAX_PER_PAGE', 50),
    ],

    'rate_limits' => [
        'write' => [
            'per_minute' => (int) env('GUEST_BOOKING_WRITE_RATE_LIMIT', 20),
        ],
    ],

    /*
     * Approved cancellation policy (2026-09-26): a refundable room/rate can
     * be cancelled free (full refund) within this many hours of booking, or
     * until check-in if that comes first; after that it cannot be
     * cancelled. Snapshotted on each reservation at booking time.
     */
    'free_cancellation_hours' => (int) env('GUEST_FREE_CANCELLATION_HOURS', 24),

    // The deposit is no longer configured here: each hotel sets its own
    // `deposit_percentage` (of the booked room price) on the dashboard
    // hotel form — see GuestPaymentController.

];

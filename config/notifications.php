<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Active Notification Provider
    |--------------------------------------------------------------------------
    |
    | The provider NotificationProviderInterface resolves to. Phase 11
    | registers exactly one implementation — "dummy" — and an unknown value
    | fails loudly rather than falling back (see NotificationServiceProvider),
    | mirroring the payment / identity / access provider bindings.
    |
    */

    'provider' => env('NOTIFICATION_PROVIDER', 'dummy'),

    /*
    |--------------------------------------------------------------------------
    | Per-Provider Configuration
    |--------------------------------------------------------------------------
    |
    | Only the dummy provider exists in this phase. No secrets, no endpoints,
    | no SDK. It never makes an external call (Phase 0 §15).
    |
    | - default_directive: the deterministic outcome the dummy provider uses
    |   when a caller supplies no explicit simulation directive. One of:
    |   deliver, fail.
    |
    */

    'providers' => [

        'dummy' => [
            'default_directive' => env('NOTIFICATION_DUMMY_DEFAULT_DIRECTIVE', 'deliver'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Channels (Phase 0 §15)
    |--------------------------------------------------------------------------
    |
    | The logical channels the platform knows about. `in_app` is the
    | reservation notification feed; `email` / `sms` are simulated by the
    | dummy provider. Disabling a channel here stops it being produced at all.
    |
    */

    'channels' => [
        'enabled' => ['in_app', 'email', 'sms'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Type → Channel Routing
    |--------------------------------------------------------------------------
    |
    | Which channels each notification type fans out to. Every type keeps
    | `in_app` (the queryable record). `email` / `sms` are only actually
    | produced when the guest has that contact value on file — this list is
    | the intent, NotificationRecipient enforces reachability.
    |
    | No business event is invented: every key is an approved Reservation
    | lifecycle milestone (Phase 0 R20 / R33).
    |
    */

    'routing' => [
        'reservation_deposit_held' => ['in_app', 'email'],
        'identity_verified' => ['in_app', 'email'],
        'reservation_checked_in' => ['in_app', 'email', 'sms'],
        'reservation_invoiced' => ['in_app', 'email'],
        'reservation_cancelled' => ['in_app', 'email'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Locale
    |--------------------------------------------------------------------------
    |
    | The locale a notification is rendered in when the triggering request
    | carries none. There is no per-guest locale field in the MVP (the Guest
    | model has no profile) — a per-recipient locale preference is a
    | documented future enhancement. Falls back to config('app.locale').
    |
    */

    'locale' => env('NOTIFICATION_LOCALE'),

    /*
    |--------------------------------------------------------------------------
    | Rate Limits (Phase 0 §17)
    |--------------------------------------------------------------------------
    |
    | Per-minute ceiling for the reservation notification-feed endpoints,
    | registered as the named limiter `notifications.read` in
    | AppServiceProvider. Keyed by authenticated user id (IP fallback). These
    | are cheap read/mark-read calls — the limit is generous.
    |
    */

    'rate_limits' => [
        'read' => [
            'per_minute' => (int) env('NOTIFICATIONS_READ_RATE_LIMIT', 60),
        ],
    ],

];

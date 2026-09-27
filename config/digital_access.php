<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Active Digital Access Provider
    |--------------------------------------------------------------------------
    |
    | The provider DigitalAccessProviderInterface resolves to. Phase 7
    | registers exactly one implementation — "dummy" — and an unknown value
    | fails loudly rather than falling back (see AppServiceProvider), mirroring
    | the Phase 5 payment gateway and Phase 6 identity verification bindings.
    |
    */

    'provider' => env('DIGITAL_ACCESS_PROVIDER', 'dummy'),

    /*
    |--------------------------------------------------------------------------
    | Access Mode (Phase 0 §11)
    |--------------------------------------------------------------------------
    |
    | §11: "access_mode field distinguishes pin_code (the initial dummy
    | implementation — an app-delivered code) from smart_lock (future, same
    | interface, different adapter) — no schema change needed to switch
    | later." Only "pin_code" is implemented this phase.
    |
    */

    'mode' => env('DIGITAL_ACCESS_MODE', 'pin_code'),

    /*
    |--------------------------------------------------------------------------
    | Per-Provider Configuration
    |--------------------------------------------------------------------------
    |
    | Only the dummy provider exists in this phase. No secrets, no endpoints —
    | the approved Phase 7 workflow has no digital-access callback/webhook
    | (Phase 0 §16 defines no such route).
    |
    | - default_directive: the deterministic outcome the dummy provider uses
    |   when a caller supplies no explicit simulation directive. One of:
    |   success, failure.
    |
    */

    'providers' => [

        'dummy' => [
            'default_directive' => env('DIGITAL_ACCESS_DUMMY_DEFAULT_DIRECTIVE', 'success'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Expiry (Phase 0 §11 — "stay end reached, auto")
    |--------------------------------------------------------------------------
    |
    | Digital access expiry is NOT a configurable duration and none is
    | invented. §11 ties expiry to the stay ending, so the domain derives
    | expires_at from the reservation's check_out date (end of that day).
    |
    | The exact hotel checkout time-of-day is not defined by the approved
    | baseline — end-of-checkout-date is the safe, non-inventive choice. A
    | per-hotel checkout-time setting is an OPEN item (see the Phase 7 report).
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Rate Limits (Phase 0 §17 — sensitive endpoints)
    |--------------------------------------------------------------------------
    |
    | Per-minute ceilings for the check-in and revoke endpoints, registered
    | as the named limiters `check-in` / `digital-access.revoke` in
    | AppServiceProvider and applied by the route `throttle:` middleware.
    | Keyed by authenticated user id (IP fallback).
    |
    */

    'rate_limits' => [
        'check_in' => [
            'per_minute' => (int) env('DIGITAL_ACCESS_CHECKIN_RATE_LIMIT', 20),
        ],
        'revoke' => [
            'per_minute' => (int) env('DIGITAL_ACCESS_REVOKE_RATE_LIMIT', 20),
        ],
    ],

];

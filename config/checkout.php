<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rate Limits (Phase 0 §17 — financially sensitive endpoint)
    |--------------------------------------------------------------------------
    |
    | Per-minute ceiling for POST /reservations/{reservation}/checkout,
    | registered as the named limiter `checkout.perform` in AppServiceProvider
    | and applied by the route `throttle:` middleware. Keyed by authenticated
    | user id (IP fallback).
    |
    | No project convention sets the exact number — 12/min is a conservative
    | technical value (a real checkout is a once-per-stay action; the extra
    | headroom covers a retry after a failed / pending settlement).
    |
    */

    'rate_limits' => [
        'perform' => [
            'per_minute' => (int) env('CHECKOUT_PERFORM_RATE_LIMIT', 12),
        ],
    ],

];

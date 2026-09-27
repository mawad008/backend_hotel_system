<?php

/**
 * Slice 0 — Guest OTP configuration. The OTP sender is a provider boundary
 * (OtpSenderInterface) exactly like the payment / identity / access / notification
 * boundaries: "dummy" is the only implementation registered, an explicitly
 * configured but unsupported sender fails loudly.
 *
 * `fixed_code` is a development convenience only. When set (local `.env`) every
 * generated code is that value, which lets the Flutter app walk the flow without
 * a real SMS. It MUST be unset in staging/production — leave the env var absent.
 * As a guard it is ignored outside the `local` / `testing` environments, so a
 * production `.env` copied from `.env.example` can never accept a fixed code.
 */
return [
    'sender' => env('OTP_SENDER', 'dummy'),

    'code_length' => 6,

    'ttl_seconds' => (int) env('OTP_TTL_SECONDS', 300),

    'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),

    'resend_cooldown_seconds' => (int) env('OTP_RESEND_COOLDOWN_SECONDS', 42),

    'max_resends' => (int) env('OTP_MAX_RESENDS', 3),

    'fixed_code' => in_array(env('APP_ENV'), ['local', 'testing'], true) ? env('OTP_FIXED_CODE') : null,

    'providers' => [
        'dummy' => [
            // The dummy sender records a masked dispatch line to the log and
            // nothing else. No real SMS is sent.
        ],
    ],

    'rate_limits' => [
        'request' => [
            'per_minute' => (int) env('OTP_REQUEST_RATE_LIMIT', 5),
            // Ceiling per client IP across ALL phone numbers — stops one
            // client from triggering SMS to a rotating list of numbers.
            'per_ip_per_minute' => (int) env('OTP_REQUEST_IP_RATE_LIMIT', 20),
        ],
        'verify' => ['per_minute' => (int) env('OTP_VERIFY_RATE_LIMIT', 10)],
    ],
];

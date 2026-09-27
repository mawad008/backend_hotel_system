<?php

/*
 * Phase 11 — notification message templates.
 *
 * One entry per NotificationType. Each is a deterministic, factual statement
 * of the reservation state that was just reached — no invented business
 * promise, no marketing copy, no data beyond the reservation reference.
 * `:reference` is the only placeholder.
 */

return [

    'reservation_deposit_held' => [
        'subject' => 'Deposit confirmed',
        'body' => 'The deposit hold for reservation :reference has been confirmed.',
    ],

    'identity_verified' => [
        'subject' => 'Identity verified',
        'body' => 'Identity verification for reservation :reference is complete.',
    ],

    'reservation_checked_in' => [
        'subject' => 'Check-in complete',
        'body' => 'Check-in for reservation :reference is complete and digital access has been issued.',
    ],

    'reservation_invoiced' => [
        'subject' => 'Invoice available',
        'body' => 'The invoice for reservation :reference is now available.',
    ],

    'reservation_cancelled' => [
        'subject' => 'Reservation cancelled',
        'body' => 'Reservation :reference has been cancelled.',
    ],

];

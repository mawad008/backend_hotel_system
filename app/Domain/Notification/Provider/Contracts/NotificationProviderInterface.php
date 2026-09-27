<?php

namespace App\Domain\Notification\Provider\Contracts;

use App\Domain\Notification\Provider\Data\NotificationDeliveryResult;
use App\Domain\Notification\Provider\Data\NotificationDispatchRequest;

/**
 * Phase 11 — the single provider boundary the rest of the application depends
 * on for notification delivery (Phase 0 §15/§49/§50: "provider abstraction …
 * zero domain-layer coupling to any vendor").
 *
 * Every implementation is an adapter over an external notification vendor
 * (email / SMS / push gateway). `DummyNotificationProvider` is the only
 * binding registered in this phase and it makes no external call.
 *
 * Scope of this contract:
 *  - It describes provider-side send operations ONLY. An implementation must
 *    not touch a Notification / Reservation record, open a DB transaction,
 *    write an audit log, run any business workflow, or make any
 *    authorization / recipient-resolution decision.
 *  - It works with application DTOs, not raw provider arrays.
 *  - It is deliberately minimal: this phase interacts with the provider only
 *    to attempt delivery of one already-composed message on one channel.
 *
 * `send()` never throws for a declined / failed delivery — that is reported
 * through NotificationDeliveryResult::$outcome. It throws only for a genuine
 * programming / configuration error.
 */
interface NotificationProviderInterface
{
    /**
     * Attempt to deliver one composed message on one channel. Provider-side
     * only — the caller persists the returned reference and decides the
     * Notification status transition.
     */
    public function send(NotificationDispatchRequest $request): NotificationDeliveryResult;
}

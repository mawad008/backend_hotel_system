<?php

namespace App\Domain\DigitalAccess\Provider\Contracts;

use App\Domain\DigitalAccess\Provider\Data\AccessIssueRequest;
use App\Domain\DigitalAccess\Provider\Data\AccessOperationRequest;
use App\Domain\DigitalAccess\Provider\Data\AccessResult;

/**
 * Phase 7 — the single provider boundary the rest of the application depends
 * on for digital access (Phase 0 §11/§15, R49/R50: "provider abstraction …
 * zero domain-layer coupling to any vendor"). Every implementation is an
 * adapter over an external access-control vendor; `DummyDigitalAccessProvider`
 * is the only binding registered in this phase.
 *
 * Scope of this contract:
 *  - It describes provider-side credential operations ONLY. An implementation
 *    must not touch an AccessGrant / Reservation record, open a DB
 *    transaction, write an audit log, run any business workflow, or make any
 *    authorization / hotel-scope / eligibility decision — §11: "Eligibility
 *    rules … live entirely in the application/domain layer, never inside the
 *    provider adapter."
 *  - It works with application DTOs, not raw provider arrays.
 *  - It is deliberately minimal: the approved Phase 7 workflow interacts with
 *    the provider only to create+activate a credential (issue) and to revoke
 *    one (revoke). Expiration is application-driven; credential validation at
 *    a door is future work with no endpoint this phase.
 *
 * The operation methods never throw for a declined / failed provider outcome
 * — that is reported through AccessResult::$status. They throw only for a
 * genuine programming / configuration error.
 */
interface DigitalAccessProviderInterface
{
    /**
     * Create and activate an access credential at the provider. Provider-side
     * only — the caller persists the returned reference + credential and
     * decides the AccessGrant status and the Reservation transition.
     */
    public function issue(AccessIssueRequest $request): AccessResult;

    /**
     * Revoke a previously issued credential at the provider. Provider-side
     * only. The caller treats local revocation as authoritative: a provider
     * failure here is recorded for reconciliation but never leaves the
     * credential usable.
     */
    public function revoke(AccessOperationRequest $request): AccessResult;
}

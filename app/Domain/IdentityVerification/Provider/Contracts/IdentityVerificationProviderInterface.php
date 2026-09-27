<?php

namespace App\Domain\IdentityVerification\Provider\Contracts;

use App\Domain\IdentityVerification\Provider\Data\VerificationRequest;
use App\Domain\IdentityVerification\Provider\Data\VerificationResult;

/**
 * Phase 6 — the single provider boundary the rest of the application depends
 * on for identity verification (Phase 0 §10/§15, R49/R50: "provider
 * abstraction ... zero domain-layer coupling to any vendor"). Every
 * implementation is an adapter over an external KYC / biometric provider;
 * `DummyIdentityVerificationProvider` is the only binding registered in this
 * phase.
 *
 * Scope of this contract:
 *  - It describes the provider-side match operation ONLY. An implementation
 *    must not touch a verification session / attempt / decision record, open
 *    a DB transaction, write an audit log, store a file, or run any business
 *    workflow. Those responsibilities belong to the workflow layer.
 *  - It works with application DTOs, not raw provider arrays.
 *  - It is deliberately minimal: the approved Phase 6 workflow has exactly
 *    one provider interaction (the selfie-vs-document match) and no
 *    verification callback/webhook (Phase 0 §16 defines no such route).
 *
 * `verify()` never throws for a low-confidence or failed match — that is
 * reported through VerificationResult::$outcome. It throws only for a
 * genuine programming / configuration error.
 */
interface IdentityVerificationProviderInterface
{
    /**
     * Run a live-selfie-vs-ID match for one verification attempt.
     * Provider-side only — the caller persists the returned reference and
     * decides the session status by comparing the score against the
     * configured confidence thresholds.
     */
    public function verify(VerificationRequest $request): VerificationResult;
}

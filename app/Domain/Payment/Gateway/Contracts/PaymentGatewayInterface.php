<?php

namespace App\Domain\Payment\Gateway\Contracts;

use App\Domain\Payment\Gateway\Data\GatewayHoldRequest;
use App\Domain\Payment\Gateway\Data\GatewayOperationRequest;
use App\Domain\Payment\Gateway\Data\GatewayResult;
use App\Domain\Payment\Gateway\Data\NormalizedWebhook;
use App\Domain\Payment\Gateway\Exceptions\WebhookPayloadException;

/**
 * Phase 5B — the single provider boundary the rest of the application
 * depends on for payments (Phase 0 §9/§15, R49/R50: "provider abstraction
 * ... zero domain-layer coupling to any vendor"). Every implementation is
 * an adapter over an external payment provider; `DummyPaymentGateway` is
 * the only binding registered in this phase.
 *
 * Scope of this contract (Phase 5B instructions §4):
 *  - It describes provider-side operations ONLY. An implementation must not
 *    touch a Payment / PaymentTransaction / Reservation record, open a DB
 *    transaction, write an audit log, or run any business workflow. Those
 *    responsibilities belong to Phase 5C+.
 *  - It works with application DTOs, not raw provider arrays.
 *  - `refund` is intentionally not part of the contract: refund execution
 *    is deferred and the approved 5B operation set excludes it.
 *
 * The operation methods never throw for a declined / failed / pending
 * provider outcome — that is reported through GatewayResult::$status. They
 * throw only for a genuine programming / configuration error.
 */
interface PaymentGatewayInterface
{
    /**
     * Request an authorization hold with the provider. Provider-side only —
     * the caller persists the returned reference and decides the Payment
     * status.
     */
    public function initiateHold(GatewayHoldRequest $request): GatewayResult;

    /**
     * Release a previously requested hold at the provider. No Payment,
     * transaction, reservation or audit state is changed here.
     */
    public function cancelHold(GatewayOperationRequest $request): GatewayResult;

    /**
     * Capture an active hold at the provider. Provider capability only —
     * capture orchestration inside a reservation workflow is a later phase.
     */
    public function capture(GatewayOperationRequest $request): GatewayResult;

    /**
     * Perform the final settlement of a captured payment at the provider.
     * Provider capability only — no settlement workflow, no invoice /
     * checkout side effect.
     */
    public function settle(GatewayOperationRequest $request): GatewayResult;

    /**
     * Ask the provider for the current state of an operation. Provider
     * simulation only — no business workflow.
     */
    public function verify(GatewayOperationRequest $request): GatewayResult;

    /**
     * Normalize a raw inbound webhook body into a provider-agnostic
     * representation. Does NOT verify the signature (see verifySignature),
     * and writes nothing.
     *
     * @throws WebhookPayloadException if the body cannot be normalized.
     */
    public function parseWebhook(string $rawBody): NormalizedWebhook;

    /**
     * Timing-safe verification that $signature is a valid signature for the
     * exact raw $rawBody under the provider's configured secret. Returns
     * false for a missing, malformed or incorrect signature — it never
     * throws and never logs the secret or the signature.
     */
    public function verifySignature(string $rawBody, ?string $signature): bool;
}

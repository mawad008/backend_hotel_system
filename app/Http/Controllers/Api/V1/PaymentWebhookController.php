<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Payment\Gateway\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Gateway\Exceptions\WebhookPayloadException;
use App\Domain\Payment\Services\PaymentWebhookService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Phase 5E — the provider webhook boundary:
 * POST /api/v1/payments/webhooks/{provider}
 *
 * Unauthenticated (machine-to-machine); the signature is the credential.
 * Thin: obtain the exact raw body, resolve/verify the provider, verify the
 * signature and parse the event through PaymentGatewayInterface, then hand
 * the normalized event to PaymentWebhookService. It never mutates a
 * Payment/Reservation, opens a transaction, or reimplements a state
 * machine.
 */
class PaymentWebhookController extends Controller
{
    /**
     * Header carrying the provider's HMAC-SHA256 signature over the exact
     * raw request body (Phase 5B contract).
     */
    private const SIGNATURE_HEADER = 'X-Signature';

    public function __construct(
        private readonly PaymentGatewayInterface $gateway,
        private readonly PaymentWebhookService $webhooks,
    ) {}

    public function handle(Request $request, string $provider): JsonResponse
    {
        // Only the configured provider has a bound gateway this phase
        // (Phase 0 §16: {provider}=dummy). Anything else is a plain 404 —
        // no "unknown provider" detail is leaked.
        if ($provider !== (string) config('payment.provider')) {
            abort(404);
        }

        // The EXACT bytes — never a re-encoded / re-ordered / re-spaced
        // version (Phase 5E §4).
        $rawBody = $request->getContent();

        // Timing-safe HMAC verification, fails closed on a missing secret
        // (Phase 5B). Invalid signature → 400, and nothing is persisted.
        if (! $this->gateway->verifySignature($rawBody, $request->header(self::SIGNATURE_HEADER))) {
            return $this->error(__('api.payment.webhook_invalid_signature'), 400);
        }

        try {
            $event = $this->gateway->parseWebhook($rawBody);
        } catch (WebhookPayloadException|InvalidArgumentException) {
            // Malformed JSON, wrong shape, missing required field, or a
            // rejected sensitive key — never echo the raw parser message.
            return $this->error(__('api.payment.webhook_malformed'), 422);
        }

        $status = $this->webhooks->process($provider, $event);

        // Every processed / duplicate / unmatched outcome is HTTP 200: the
        // provider delivered the event and the application received it.
        return $this->success(
            ['processing_status' => $status],
            __('api.payment.webhook_'.$status),
            200,
        );
    }
}

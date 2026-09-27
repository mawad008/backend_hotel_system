<?php

namespace App\Domain\Payment\Gateway;

use App\Domain\Payment\Gateway\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Gateway\Data\GatewayHoldRequest;
use App\Domain\Payment\Gateway\Data\GatewayOperationRequest;
use App\Domain\Payment\Gateway\Data\GatewayResult;
use App\Domain\Payment\Gateway\Data\NormalizedWebhook;
use App\Domain\Payment\Gateway\Exceptions\WebhookPayloadException;
use App\Domain\Payment\Gateway\Support\SensitiveDataGuard;

/**
 * Phase 5B — the deterministic dummy payment provider (Phase 0 §9/§15,
 * R50: "all third-party integrations dummy/mocked this phase").
 *
 * It is a pure provider-boundary simulation:
 *  - no randomness, no sleep/delay, no time-based behaviour
 *  - no external HTTP, no real credentials, no card processing
 *  - no Eloquent model, no repository, no DB access, no audit write
 *
 * Every operation's outcome is a pure function of (operation, simulation
 * directive, caller-supplied seed reference). The directive is either given
 * explicitly on the request or falls back to the configured default
 * (deterministic success). The same inputs always produce an equivalent
 * result.
 *
 * The provider reference it returns is `dummy_<operation>_<hex>` where the
 * hex is a SHA-256 slice of the operation and the seed — deterministic,
 * collision-resistant enough for tests, and unmistakably a dummy value.
 */
final class DummyPaymentGateway implements PaymentGatewayInterface
{
    /**
     * Bytes of SHA-256 hex kept for a provider reference. 24 hex chars =
     * 96 bits — far more than enough to keep distinct test scenarios apart.
     */
    private const REFERENCE_HEX_LENGTH = 24;

    /**
     * The only fields carried out of an inbound webhook body. Anything not
     * on this list is dropped; a sensitive key anywhere in the body is
     * rejected before we get here.
     *
     * @var list<string>
     */
    private const WEBHOOK_ALLOWED_FIELDS = [
        'type',
        'event_id',
        'provider_reference',
        'status',
        'amount',
        'currency',
    ];

    public function __construct(
        private readonly string $webhookSecret,
        private readonly SimulationDirective $defaultDirective = SimulationDirective::DEFAULT,
    ) {}

    public function initiateHold(GatewayHoldRequest $request): GatewayResult
    {
        return $this->simulate(
            GatewayOperation::Hold,
            $request->intentReference,
            $request->directive,
            $request->metadata,
        );
    }

    public function cancelHold(GatewayOperationRequest $request): GatewayResult
    {
        return $this->simulate(
            GatewayOperation::CancelHold,
            $request->providerReference,
            $request->directive,
            $request->metadata,
        );
    }

    public function capture(GatewayOperationRequest $request): GatewayResult
    {
        return $this->simulate(
            GatewayOperation::Capture,
            $request->providerReference,
            $request->directive,
            $request->metadata,
        );
    }

    public function settle(GatewayOperationRequest $request): GatewayResult
    {
        return $this->simulate(
            GatewayOperation::Settlement,
            $request->providerReference,
            $request->directive,
            $request->metadata,
        );
    }

    public function verify(GatewayOperationRequest $request): GatewayResult
    {
        return $this->simulate(
            GatewayOperation::Verify,
            $request->providerReference,
            $request->directive,
            $request->metadata,
        );
    }

    public function parseWebhook(string $rawBody): NormalizedWebhook
    {
        $decoded = json_decode($rawBody, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw WebhookPayloadException::malformedJson();
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw WebhookPayloadException::notAnObject();
        }

        SensitiveDataGuard::assertNoSensitiveKeys($decoded, 'webhook payload');

        $type = $decoded['type'] ?? null;

        if (! is_string($type) || $type === '') {
            throw WebhookPayloadException::missingField('type');
        }

        $providerReference = isset($decoded['provider_reference']) && is_scalar($decoded['provider_reference'])
            ? (string) $decoded['provider_reference']
            : null;

        $providerEventId = isset($decoded['event_id']) && is_scalar($decoded['event_id'])
            ? (string) $decoded['event_id']
            : null;

        $status = isset($decoded['status']) && is_string($decoded['status'])
            ? GatewayResultStatus::tryFrom($decoded['status'])
            : null;

        $normalizedPayload = [];

        foreach (self::WEBHOOK_ALLOWED_FIELDS as $field) {
            if (array_key_exists($field, $decoded) && is_scalar($decoded[$field])) {
                $normalizedPayload[$field] = $decoded[$field];
            }
        }

        return new NormalizedWebhook(
            providerEventId: $providerEventId,
            eventType: $this->normalizeEventType($type),
            providerReference: $providerReference,
            status: $status,
            payloadHash: hash('sha256', $rawBody),
            normalizedPayload: $normalizedPayload,
        );
    }

    public function verifySignature(string $rawBody, ?string $signature): bool
    {
        if ($signature === null || $signature === '' || $this->webhookSecret === '') {
            return false;
        }

        return hash_equals($this->sign($rawBody), $signature);
    }

    /**
     * The dummy provider's signing scheme, exposed so a test (and the
     * Phase 5E simulated-callback emitter) can build a validly signed
     * body. Not part of PaymentGatewayInterface — that contract only
     * describes inbound provider operations.
     */
    public function sign(string $rawBody): string
    {
        return hash_hmac('sha256', $rawBody, $this->webhookSecret);
    }

    /**
     * The one place an outcome is decided. Pure: no state, no clock, no
     * I/O.
     *
     * @param  array<string, scalar|null>  $metadata
     */
    private function simulate(
        GatewayOperation $operation,
        string $seedReference,
        ?SimulationDirective $directive,
        array $metadata,
    ): GatewayResult {
        $directive ??= $this->defaultDirective;
        $status = GatewayResultStatus::forDirective($directive);

        return new GatewayResult(
            operation: $operation,
            status: $status,
            providerReference: $this->reference($operation, $seedReference),
            providerCode: sprintf('dummy_%s_%s', $operation->value, $status->value),
            message: $this->message($operation, $status),
            context: $metadata,
        );
    }

    private function reference(GatewayOperation $operation, string $seed): string
    {
        $hex = substr(hash('sha256', $operation->value.'|'.$seed), 0, self::REFERENCE_HEX_LENGTH);

        return sprintf('dummy_%s_%s', $operation->value, $hex);
    }

    private function message(GatewayOperation $operation, GatewayResultStatus $status): string
    {
        return sprintf(
            'Dummy provider %s %s.',
            str_replace('_', ' ', $operation->value),
            $status->value,
        );
    }

    private function normalizeEventType(string $type): string
    {
        $type = strtolower(trim($type));

        return (string) preg_replace('/[^a-z0-9._-]+/', '.', $type);
    }
}

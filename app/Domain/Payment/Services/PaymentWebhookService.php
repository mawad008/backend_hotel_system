<?php

namespace App\Domain\Payment\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Payment\Gateway\Data\GatewayResult;
use App\Domain\Payment\Gateway\Data\NormalizedWebhook;
use App\Domain\Payment\Gateway\GatewayOperation;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentTransaction;
use App\Domain\Payment\Models\PaymentWebhookEvent;
use App\Domain\Payment\Repositories\Contracts\PaymentRepositoryInterface;
use App\Domain\Payment\Repositories\Contracts\PaymentTransactionRepositoryInterface;
use App\Domain\Payment\Repositories\Contracts\PaymentWebhookEventRepositoryInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5E — asynchronous payment-result processing at the provider webhook
 * boundary (Phase 0 §9/§16).
 *
 * Responsibilities: persist the normalized event, deduplicate it, match it
 * to a pending hold attempt, and apply the result through the Phase 5C
 * reusable capability (PaymentWorkflowService::applyHoldResult) — never a
 * second copy of the state-transition logic. Signature verification and
 * provider-specific parsing happen in the controller against
 * PaymentGatewayInterface, before this service is called.
 *
 * No provider HTTP call ever happens in webhook processing (§5E.12/§5E.13):
 * the webhook IS the provider's result.
 */
class PaymentWebhookService
{
    public function __construct(
        private readonly PaymentWorkflowService $workflow,
        private readonly PaymentWebhookEventRepositoryInterface $webhookEvents,
        private readonly PaymentTransactionRepositoryInterface $transactions,
        private readonly PaymentRepositoryInterface $payments,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Persist, dedupe, match and apply one already-verified, already-parsed
     * webhook event. Returns the resulting PaymentWebhookEvent processing
     * status (one of PaymentWebhookEvent::PROCESSING_*), which every
     * outcome maps to HTTP 200 — the provider delivered the event and the
     * application received it.
     */
    public function process(string $provider, NormalizedWebhook $event, ?User $actor = null): string
    {
        return DB::transaction(function () use ($provider, $event, $actor) {
            // ── 1. Authoritative dedup: (provider, provider_event_id) ──
            if ($event->providerEventId !== null) {
                $prior = $this->webhookEvents->findByProviderEventId($provider, $event->providerEventId);

                if ($prior !== null) {
                    return $this->ignoreDuplicate($prior);
                }
            }

            // ── 2. Match the pending hold attempt (plain read — applyHoldResult owns locking) ──
            $transaction = $event->providerReference !== null
                ? $this->transactions->findByProviderReference($provider, $event->providerReference)
                : null;

            // ── 3. Fallback dedup: (provider, payload_hash) when no event id.
            //       payload_hash is indexed but NON-unique (Phase 5A); a prior
            //       committed event for the same (provider, hash) means this is
            //       a replay/retry of an already-handled delivery (§5E.8/§5E.15).
            //       Every path below commits a terminal processing_status, so a
            //       returned row is never a mid-flight RECEIVED. ──
            if ($event->providerEventId === null) {
                $prior = $this->webhookEvents->findLatestByPayloadHash($provider, $event->payloadHash);

                if ($prior !== null) {
                    $record = $this->webhookEvents->create(
                        $this->baseAttributes($provider, $event, $transaction, PaymentWebhookEvent::PROCESSING_DUPLICATE_IGNORED)
                        + ['processed_at' => now()],
                    );

                    return $this->ignoreDuplicate($record);
                }
            }

            // ── 4. Persist the event. The (provider, provider_event_id) UNIQUE
            //       index makes concurrent same-id delivery atomic. ──
            try {
                $record = $this->webhookEvents->create(
                    $this->baseAttributes($provider, $event, $transaction, PaymentWebhookEvent::PROCESSING_RECEIVED),
                );
            } catch (UniqueConstraintViolationException) {
                return PaymentWebhookEvent::PROCESSING_DUPLICATE_IGNORED;
            }

            // ── 5. Unmatched: nothing to apply, still a successful delivery ──
            if ($transaction === null) {
                $record = $this->finish($record, PaymentWebhookEvent::PROCESSING_UNMATCHED);
                $this->audit('payment.webhook_unmatched', $record, null);

                return PaymentWebhookEvent::PROCESSING_UNMATCHED;
            }

            $payment = $this->payments->find($transaction->payment_id);

            // ── 6. Already resolved (by the API or an earlier webhook) — acknowledge,
            //       do not re-apply, do not throw an invalid transition (§5E.22) ──
            if ($transaction->status !== PaymentTransaction::STATUS_PENDING) {
                $record = $this->finish(
                    $record,
                    PaymentWebhookEvent::PROCESSING_DUPLICATE_IGNORED,
                    'the hold attempt was already resolved; webhook acknowledged',
                );
                $this->audit('payment.webhook_duplicate_ignored', $record, $payment);

                return PaymentWebhookEvent::PROCESSING_DUPLICATE_IGNORED;
            }

            // ── 7. No actionable status: valid delivery, nothing to transition ──
            if ($event->status === null) {
                $record = $this->finish(
                    $record,
                    PaymentWebhookEvent::PROCESSING_PROCESSED,
                    'no actionable payment status in the event',
                );
                $this->audit('payment.webhook_processed', $record, $payment);

                return PaymentWebhookEvent::PROCESSING_PROCESSED;
            }

            // ── 8. Apply through the Phase 5C reusable capability. It re-reads
            //       under the Reservation -> Payment -> PaymentTransaction lock
            //       order, guards the PaymentStateMachine, routes Reservation
            //       changes through ReservationService, audits, and is idempotent.
            //       Late success after cancellation is handled there. ──
            $this->workflow->applyHoldResult(
                reservationId: $payment->reservation_id,
                paymentId: $payment->id,
                transactionId: $transaction->id,
                result: $this->toGatewayResult($provider, $event),
                actor: $actor,
            );

            $record = $this->finish($record, PaymentWebhookEvent::PROCESSING_PROCESSED);
            $this->audit('payment.webhook_processed', $record, $payment->fresh());

            return PaymentWebhookEvent::PROCESSING_PROCESSED;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function baseAttributes(
        string $provider,
        NormalizedWebhook $event,
        ?PaymentTransaction $transaction,
        string $processingStatus,
    ): array {
        return [
            'provider' => $provider,
            'provider_event_id' => $event->providerEventId,
            'payload_hash' => $event->payloadHash,
            'event_type' => $event->eventType,
            'processing_status' => $processingStatus,
            'payment_id' => $transaction?->payment_id,
            'provider_reference' => $event->providerReference,
            // Already an allow-listed, sensitive-key-free structure (Phase 5B).
            'normalized_payload' => $event->normalizedPayload,
        ];
    }

    private function finish(PaymentWebhookEvent $record, string $status, ?string $error = null): PaymentWebhookEvent
    {
        return $this->webhookEvents->update($record, [
            'processing_status' => $status,
            'processed_at' => now(),
            'error_message' => $error,
        ]);
    }

    private function ignoreDuplicate(PaymentWebhookEvent $record): string
    {
        $payment = $record->payment_id !== null ? $this->payments->find($record->payment_id) : null;
        $this->audit('payment.webhook_duplicate_ignored', $record, $payment);

        return PaymentWebhookEvent::PROCESSING_DUPLICATE_IGNORED;
    }

    private function toGatewayResult(string $provider, NormalizedWebhook $event): GatewayResult
    {
        return new GatewayResult(
            operation: GatewayOperation::Hold,
            status: $event->status,
            providerReference: $event->providerReference ?? '',
            providerCode: $provider.'.webhook.'.$event->eventType,
            message: 'Applied from a '.$provider.' webhook.',
            context: [],
        );
    }

    private function audit(string $action, PaymentWebhookEvent $record, ?Payment $payment): void
    {
        $this->auditLogger->record(
            null,
            $action,
            $record,
            after: [
                'processing_status' => $record->processing_status,
                'event_type' => $record->event_type,
                'provider' => $record->provider,
                'provider_event_id' => $record->provider_event_id,
                'provider_reference' => $record->provider_reference,
                'payment_id' => $record->payment_id,
                'payment_status' => $payment?->status,
            ],
            hotelId: $payment?->hotel_id,
        );
    }
}

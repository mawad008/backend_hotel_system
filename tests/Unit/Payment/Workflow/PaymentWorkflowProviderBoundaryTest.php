<?php

namespace Tests\Unit\Payment\Workflow;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Payment\Gateway\GatewayResultStatus;
use App\Domain\Payment\Gateway\SimulationDirective;
use App\Domain\Payment\Models\Payment;
use App\Domain\Reservation\Models\Reservation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Tests\Unit\Payment\Workflow\Fakes\RecordingPaymentGateway;

/**
 * Phase 5C test matrix L and §39 / §40 — the provider is invoked through
 * PaymentGatewayInterface, outside any workflow-opened DB transaction, and
 * not at all when Step A fails.
 */
class PaymentWorkflowProviderBoundaryTest extends PaymentWorkflowTestCase
{
    // ── §39. Provider call happens outside a workflow transaction ───

    public function test_provider_is_called_with_no_workflow_transaction_open(): void
    {
        $baseline = DB::transactionLevel();

        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_PENDING]);
        $gateway = new RecordingPaymentGateway(GatewayResultStatus::Succeeded);

        $this->makeWorkflow($gateway)->initiateHold($reservation, '10.00', null, null, SimulationDirective::Success, null);

        $this->assertSame(1, $gateway->initiateHoldCalls);
        // Not merely source inspection: the gateway captured the live
        // nesting depth and it equals the ambient (RefreshDatabase) level —
        // no Step A / Step C transaction wraps the provider call.
        $this->assertNotNull($gateway->transactionLevelAtCall);
        $this->assertSame($baseline, $gateway->transactionLevelAtCall);
    }

    public function test_step_a_actually_runs_inside_a_transaction(): void
    {
        // Control for the assertion above: prove the depth probe is
        // meaningful by showing Step A's audit write sees a deeper level.
        $baseline = DB::transactionLevel();
        $seenDuringStepA = null;

        $probeAudit = new class($seenDuringStepA) extends AuditLogger
        {
            public function __construct(public mixed &$seen) {}

            public function record(?User $actor, string $action, ?Model $subject = null, ?array $before = null, ?array $after = null, ?int $hotelId = null): AuditLog
            {
                if ($action === 'payment.hold_requested') {
                    $this->seen = DB::transactionLevel();
                }

                return new AuditLog;
            }
        };

        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_PENDING]);

        $this->makeWorkflow(new RecordingPaymentGateway, $probeAudit)
            ->initiateHold($reservation, '10.00', null, null, SimulationDirective::Success, null);

        $this->assertSame($baseline + 1, $seenDuringStepA);
    }

    // ── L / §40. No provider call when Step A rejects ──────────────

    public function test_no_provider_call_when_the_reservation_is_not_pending(): void
    {
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_VERIFIED]);
        $gateway = new RecordingPaymentGateway;

        try {
            $this->makeWorkflow($gateway)->initiateHold($reservation, '10.00', null, null, SimulationDirective::Success, null);
        } catch (\Throwable) {
            // asserted elsewhere
        }

        $this->assertSame(0, $gateway->initiateHoldCalls);
    }

    public function test_workflow_uses_the_injected_gateway_and_never_builds_its_own(): void
    {
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_PENDING]);
        $gateway = new RecordingPaymentGateway(GatewayResultStatus::Succeeded);

        $payment = $this->makeWorkflow($gateway)->initiateHold(
            $reservation, '10.00', null, 'k', SimulationDirective::Success, null,
        );

        // The fake gateway's fingerprint proves the real DummyPaymentGateway
        // was not instantiated inside the workflow.
        $this->assertSame(1, $gateway->initiateHoldCalls);
        $this->assertStringStartsWith('dummy_hold_fake_', $payment->transactions()->sole()->provider_reference);
    }

    public function test_the_gateway_receives_only_primitive_application_data(): void
    {
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_PENDING]);
        $gateway = new RecordingPaymentGateway(GatewayResultStatus::Succeeded);

        $this->makeWorkflow($gateway)->initiateHold($reservation, '55.00', 'EUR', 'seed-key', SimulationDirective::Success, null);

        $request = $gateway->lastHoldRequest;
        $this->assertSame('seed-key', $request->intentReference);
        $this->assertSame('55.00', $request->amount);
        $this->assertSame('EUR', $request->currency);
        $this->assertSame(SimulationDirective::Success, $request->directive);
        // No Eloquent model / id leaked into the request metadata.
        $this->assertSame([], $request->metadata);
    }

    public function test_default_container_resolution_uses_the_dummy_gateway(): void
    {
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_PENDING]);

        // No explicit gateway — resolved from the container (config: dummy).
        $payment = $this->makeWorkflow()->initiateHold(
            $reservation, '10.00', null, null, SimulationDirective::Success, User::factory()->groupOwner()->create(),
        );

        $this->assertSame(Payment::STATUS_HOLD_ACTIVE, $payment->status);
        $this->assertStringStartsWith('dummy_hold_', $payment->transactions()->sole()->provider_reference);
        $this->assertNotNull(AuditLog::where('action', 'payment.hold_succeeded')->first());
    }
}

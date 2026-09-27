<?php

namespace Tests\Unit\Payment\Workflow;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Payment\Gateway\SimulationDirective;
use App\Domain\Reservation\Models\Reservation;
use Tests\Unit\Payment\Workflow\Fakes\RecordingPaymentGateway;

/**
 * Phase 5C test matrix N — audit coverage and the absence of sensitive
 * data from every audit payload.
 */
class PaymentWorkflowAuditTest extends PaymentWorkflowTestCase
{
    private function assertNoSensitiveDataInAudit(): void
    {
        foreach (AuditLog::all() as $log) {
            $blob = strtolower(json_encode([$log->action, $log->before, $log->after]));

            foreach (['secret', 'card_number', 'cardnumber', 'cvv', 'cvc', ' pin', 'password', 'raw_body', 'webhook_secret'] as $needle) {
                $this->assertStringNotContainsString($needle, $blob, "audit leaked '{$needle}'");
            }
        }
    }

    public function test_successful_hold_audits_request_result_and_reservation_transition(): void
    {
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_PENDING]);
        $actor = User::factory()->groupOwner()->create();

        $this->makeWorkflow()->initiateHold($reservation, '10.00', 'EUR', 'k', SimulationDirective::Success, $actor);

        $this->assertNotNull(AuditLog::where('action', 'payment.hold_requested')->first());
        $this->assertNotNull(AuditLog::where('action', 'payment.hold_succeeded')->first());
        $this->assertNotNull(
            AuditLog::where('action', 'reservation.status_changed')->where('auditable_id', $reservation->id)->first()
        );
        $this->assertNoSensitiveDataInAudit();
    }

    public function test_failed_hold_audits_failure_and_the_cancellation(): void
    {
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_PENDING]);

        $this->makeWorkflow()->initiateHold($reservation, '10.00', null, null, SimulationDirective::Failure, null);

        $this->assertNotNull(AuditLog::where('action', 'payment.hold_failed')->first());
        $this->assertNotNull(
            AuditLog::where('action', 'reservation.status_changed')->where('auditable_id', $reservation->id)->first()
        );
        $this->assertNoSensitiveDataInAudit();
    }

    public function test_late_success_is_audited_as_a_reconciliation_situation(): void
    {
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_PENDING]);
        $gateway = new RecordingPaymentGateway;
        $gateway->onInitiateHold = function () use ($reservation) {
            Reservation::whereKey($reservation->id)->update(['status' => Reservation::STATUS_CANCELLED]);
        };

        $this->makeWorkflow($gateway)->initiateHold($reservation, '10.00', null, null, SimulationDirective::Success, null);

        $late = AuditLog::where('action', 'payment.hold_succeeded_after_reservation_closed')->sole();
        $this->assertTrue($late->after['requires_reconciliation']);
        $this->assertNoSensitiveDataInAudit();
    }

    public function test_audit_entries_carry_the_reservations_hotel_id(): void
    {
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_PENDING]);

        $this->makeWorkflow()->initiateHold($reservation, '10.00', null, null, SimulationDirective::Success, null);

        foreach (AuditLog::where('action', 'like', 'payment.%')->get() as $log) {
            $this->assertSame($reservation->hotel_id, $log->hotel_id);
        }
    }

    public function test_pending_hold_audits_only_the_request_and_the_pending_marker(): void
    {
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_PENDING]);

        $this->makeWorkflow()->initiateHold($reservation, '10.00', null, null, SimulationDirective::Pending, null);

        $this->assertSame(
            ['payment.hold_requested', 'payment.hold_pending'],
            AuditLog::orderBy('id')->pluck('action')->all(),
        );
        $this->assertNoSensitiveDataInAudit();
    }
}

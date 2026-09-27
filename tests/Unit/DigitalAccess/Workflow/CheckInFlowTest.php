<?php

namespace Tests\Unit\DigitalAccess\Workflow;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\DigitalAccess\Exceptions\CheckInEligibilityException;
use App\Domain\DigitalAccess\Exceptions\CheckInNotAllowedException;
use App\Domain\DigitalAccess\Exceptions\DigitalAccessActionNotAllowedException;
use App\Domain\DigitalAccess\Models\AccessGrant;
use App\Domain\DigitalAccess\Provider\SimulationDirective;
use App\Domain\IdentityVerification\Models\IdentityVerificationSession;
use App\Domain\Payment\Models\Payment;
use App\Domain\Reservation\Models\Reservation;
use PHPUnit\Framework\Attributes\DataProvider;

class CheckInFlowTest extends DigitalAccessWorkflowTestCase
{
    public function test_happy_path_issues_an_active_credential_and_checks_the_reservation_in(): void
    {
        $reservation = $this->verifiedReservation();

        $grant = $this->makeService()->checkIn($reservation, SimulationDirective::Success);

        $this->assertSame(AccessGrant::STATUS_ACTIVE, $grant->status);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $grant->credential);
        $this->assertNotNull($grant->activated_at);
        $this->assertNotNull($grant->expires_at);
        $this->assertNotNull($grant->provider_reference);
        $this->assertSame(Reservation::STATUS_IN_STAY, $reservation->fresh()->status);

        $this->assertTrue(AuditLog::where('action', 'digital_access.issue_requested')->exists());
        $this->assertTrue(AuditLog::where('action', 'digital_access.issued')->exists());
        // The reservation transition is audited by ReservationService.
        $this->assertTrue(AuditLog::where('action', 'reservation.status_changed')->exists());
    }

    public function test_expiry_is_derived_from_the_reservation_check_out_end_of_day(): void
    {
        $reservation = $this->verifiedReservation();
        $reservation->update(['check_out' => now()->addDays(5)->format('Y-m-d')]);

        $grant = $this->makeService()->checkIn($reservation, SimulationDirective::Success);

        $this->assertSame(
            $reservation->fresh()->check_out->copy()->endOfDay()->toDateTimeString(),
            $grant->expires_at->toDateTimeString(),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonVerifiedStatuses(): array
    {
        return [
            'pending' => [Reservation::STATUS_PENDING],
            'deposit_held' => [Reservation::STATUS_DEPOSIT_HELD],
            'checked_in' => [Reservation::STATUS_CHECKED_IN],
            'cancelled' => [Reservation::STATUS_CANCELLED],
            'in_stay' => [Reservation::STATUS_IN_STAY],
        ];
    }

    #[DataProvider('nonVerifiedStatuses')]
    public function test_check_in_requires_a_verified_reservation(string $status): void
    {
        $reservation = $this->verifiedReservation();
        $reservation->update(['status' => $status]);

        $this->expectException(CheckInNotAllowedException::class);
        $this->makeService()->checkIn($reservation, SimulationDirective::Success);
    }

    public function test_check_in_is_blocked_when_the_payment_hold_is_not_active(): void
    {
        $reservation = $this->verifiedReservation();
        $reservation->payment->update(['status' => Payment::STATUS_EXPIRED]);

        try {
            $this->makeService()->checkIn($reservation, SimulationDirective::Success);
            $this->fail('expected eligibility exception');
        } catch (CheckInEligibilityException $e) {
            $this->assertSame('payment_not_confirmed', $e->reason);
        }

        $this->assertSame(Reservation::STATUS_VERIFIED, $reservation->fresh()->status);
        $this->assertDatabaseCount('access_grants', 0);
    }

    public function test_check_in_is_blocked_when_identity_is_not_approved(): void
    {
        $reservation = $this->verifiedReservation();
        IdentityVerificationSession::query()
            ->where('reservation_id', $reservation->id)
            ->update(['status' => IdentityVerificationSession::STATUS_PENDING_MANUAL_REVIEW]);

        try {
            $this->makeService()->checkIn($reservation, SimulationDirective::Success);
            $this->fail('expected eligibility exception');
        } catch (CheckInEligibilityException $e) {
            $this->assertSame('identity_not_verified', $e->reason);
        }
    }

    public function test_check_in_is_blocked_after_the_stay_window_has_passed(): void
    {
        $reservation = $this->verifiedReservation();
        $reservation->update([
            'check_in' => now()->subDays(5)->format('Y-m-d'),
            'check_out' => now()->subDays(1)->format('Y-m-d'),
        ]);

        try {
            $this->makeService()->checkIn($reservation, SimulationDirective::Success);
            $this->fail('expected eligibility exception');
        } catch (CheckInEligibilityException $e) {
            $this->assertSame('outside_stay_window', $e->reason);
        }
    }

    public function test_provider_failure_leaves_a_recoverable_failed_grant_and_no_reservation_change(): void
    {
        $reservation = $this->verifiedReservation();

        $grant = $this->makeService()->checkIn($reservation, SimulationDirective::Failure);

        $this->assertSame(AccessGrant::STATUS_FAILED, $grant->status);
        $this->assertNull($grant->credential);
        $this->assertSame('provider_declined', $grant->failure_reason);
        $this->assertSame(Reservation::STATUS_VERIFIED, $reservation->fresh()->status);
        $this->assertTrue(AuditLog::where('action', 'digital_access.issue_failed')->exists());
    }

    public function test_check_in_can_be_retried_after_a_provider_failure(): void
    {
        $reservation = $this->verifiedReservation();
        $service = $this->makeService();

        $service->checkIn($reservation, SimulationDirective::Failure);
        $this->assertSame(AccessGrant::STATUS_FAILED, AccessGrant::sole()->status);

        $grant = $service->checkIn($reservation, SimulationDirective::Success);

        $this->assertSame(AccessGrant::STATUS_ACTIVE, $grant->status);
        $this->assertSame(1, AccessGrant::count());
        $this->assertSame(Reservation::STATUS_IN_STAY, $reservation->fresh()->status);
    }

    public function test_a_second_check_in_after_success_returns_the_same_active_grant_without_a_new_provider_call(): void
    {
        $reservation = $this->verifiedReservation();
        $service = $this->makeService();

        $first = $service->checkIn($reservation, SimulationDirective::Success);
        // Reservation is now CHECKED_IN; a differently-keyed retry must not
        // re-issue — it returns the existing ACTIVE grant.
        $second = $service->checkIn($reservation->fresh(), SimulationDirective::Success);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->credential, $second->credential);
        $this->assertSame(1, AccessGrant::count());
    }

    public function test_check_in_never_touches_payment_state(): void
    {
        $reservation = $this->verifiedReservation();
        $paymentStatusBefore = $reservation->payment->status;

        $this->makeService()->checkIn($reservation, SimulationDirective::Success);

        $this->assertSame($paymentStatusBefore, $reservation->payment->fresh()->status);
    }

    public function test_check_in_while_a_grant_is_already_issue_requested_is_rejected_unless_same_key(): void
    {
        // Simulates a concurrent check-in that has committed Stage A but not
        // yet Stage C: a second differently-keyed request must not start a
        // parallel issuance.
        $reservation = $this->verifiedReservation();
        AccessGrant::factory()->create([
            'reservation_id' => $reservation->id,
            'hotel_id' => $reservation->hotel_id,
            'guest_id' => $reservation->guest_id,
            'status' => AccessGrant::STATUS_ISSUE_REQUESTED,
            'idempotency_key' => 'in-flight-key',
        ]);

        try {
            $this->makeService()->checkIn($reservation, SimulationDirective::Success, idempotencyKey: 'a-different-key');
            $this->fail('expected a not-allowed exception');
        } catch (DigitalAccessActionNotAllowedException $e) {
            $this->assertSame('issue_requested', $e->currentStatus);
        }

        // The same key is treated as an idempotent replay.
        $grant = $this->makeService()->checkIn($reservation->fresh(), SimulationDirective::Success, idempotencyKey: 'in-flight-key');
        $this->assertSame(AccessGrant::STATUS_ISSUE_REQUESTED, $grant->status);
    }

    public function test_check_in_after_a_revoke_cannot_re_issue(): void
    {
        $reservation = $this->verifiedReservation();
        AccessGrant::factory()->revoked()->create([
            'reservation_id' => $reservation->id,
            'hotel_id' => $reservation->hotel_id,
            'guest_id' => $reservation->guest_id,
        ]);

        $this->expectException(DigitalAccessActionNotAllowedException::class);
        $this->makeService()->checkIn($reservation, SimulationDirective::Success);
    }

    public function test_an_assigned_room_is_a_check_in_prerequisite(): void
    {
        // Approved 2026-09-26: the key opens a specific room, so reception
        // assigns it before check-in.
        $reservation = $this->verifiedReservation(withRoom: false);

        try {
            $this->makeService()->checkIn($reservation, SimulationDirective::Success);
            $this->fail('Check-in without a room must be refused.');
        } catch (CheckInEligibilityException $e) {
            $this->assertSame('room_not_assigned', $e->reason);
        }

        $this->assertSame(Reservation::STATUS_VERIFIED, $reservation->fresh()->status);
    }
}

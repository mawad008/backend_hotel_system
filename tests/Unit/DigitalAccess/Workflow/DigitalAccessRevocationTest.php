<?php

namespace Tests\Unit\DigitalAccess\Workflow;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\DigitalAccess\Exceptions\DigitalAccessActionNotAllowedException;
use App\Domain\DigitalAccess\Models\AccessGrant;
use App\Domain\DigitalAccess\Provider\AccessResultStatus;
use App\Domain\DigitalAccess\Provider\Contracts\DigitalAccessProviderInterface;
use App\Domain\DigitalAccess\Provider\Data\AccessIssueRequest;
use App\Domain\DigitalAccess\Provider\Data\AccessOperationRequest;
use App\Domain\DigitalAccess\Provider\Data\AccessResult;
use App\Domain\DigitalAccess\Provider\SimulationDirective;
use App\Domain\Reservation\Models\Reservation;
use Illuminate\Support\Facades\DB;

class DigitalAccessRevocationTest extends DigitalAccessWorkflowTestCase
{
    private function checkedIn(): Reservation
    {
        $reservation = $this->verifiedReservation();
        $this->makeService()->checkIn($reservation, SimulationDirective::Success);

        return $reservation->fresh();
    }

    public function test_revoking_an_active_grant_kills_the_credential(): void
    {
        $reservation = $this->checkedIn();

        $grant = $this->makeService()->revoke($reservation, 'guest reported a lost phone', SimulationDirective::Success);

        $this->assertSame(AccessGrant::STATUS_REVOKED, $grant->status);
        $this->assertNull($grant->credential);
        $this->assertNotNull($grant->revoked_at);
        $this->assertSame('guest reported a lost phone', $grant->revocation_reason);
        // Reservation stays CHECKED_IN — revocation does not roll the guest back.
        $this->assertSame(Reservation::STATUS_IN_STAY, $reservation->fresh()->status);
        $this->assertTrue(AuditLog::where('action', 'digital_access.revoke_requested')->exists());
        $this->assertTrue(AuditLog::where('action', 'digital_access.revoked')->exists());
    }

    public function test_repeated_revoke_is_a_safe_no_op(): void
    {
        $reservation = $this->checkedIn();
        $service = $this->makeService();

        $first = $service->revoke($reservation, null, SimulationDirective::Success);
        $second = $service->revoke($reservation->fresh(), null, SimulationDirective::Success);

        $this->assertSame(AccessGrant::STATUS_REVOKED, $second->status);
        $this->assertSame($first->id, $second->id);
        // Only one revoke lifecycle audited.
        $this->assertSame(1, AuditLog::where('action', 'digital_access.revoked')->count());
    }

    public function test_provider_revoke_failure_still_revokes_locally_and_flags_reconciliation(): void
    {
        $reservation = $this->checkedIn();

        $grant = $this->makeService()->revoke($reservation, null, SimulationDirective::Failure);

        $this->assertSame(AccessGrant::STATUS_REVOKED, $grant->status);
        $this->assertNull($grant->credential);

        $audit = AuditLog::where('action', 'digital_access.revoked')->latest('id')->first();
        $this->assertTrue((bool) ($audit->after['provider_revoke_failed'] ?? false));
        $this->assertTrue((bool) ($audit->after['requires_reconciliation'] ?? false));
    }

    public function test_revoke_before_any_credential_is_issued_is_rejected(): void
    {
        $reservation = $this->verifiedReservation();

        $this->expectException(DigitalAccessActionNotAllowedException::class);
        $this->makeService()->revoke($reservation, null, SimulationDirective::Success);
    }

    public function test_revoke_of_a_failed_grant_is_rejected(): void
    {
        $reservation = $this->verifiedReservation();
        $this->makeService()->checkIn($reservation, SimulationDirective::Failure);

        $this->expectException(DigitalAccessActionNotAllowedException::class);
        $this->makeService()->revoke($reservation->fresh(), null, SimulationDirective::Success);
    }

    public function test_revoke_provider_is_reached_only_through_the_interface_and_outside_a_transaction(): void
    {
        $reservation = $this->checkedIn();

        $baseline = DB::transactionLevel();

        $spy = new class($baseline) implements DigitalAccessProviderInterface
        {
            public array $calls = [];

            public function __construct(private readonly int $baseline) {}

            public function issue(AccessIssueRequest $request): AccessResult
            {
                return new AccessResult(AccessResultStatus::Active, 'ref', '999999', 'c', 'm');
            }

            public function revoke(AccessOperationRequest $request): AccessResult
            {
                $this->calls[] = DB::transactionLevel();

                return new AccessResult(AccessResultStatus::Revoked, 'ref', null, 'c', 'm');
            }
        };

        $this->makeService($spy)->revoke($reservation, null, SimulationDirective::Success);

        $this->assertSame([$baseline], $spy->calls);
    }
}

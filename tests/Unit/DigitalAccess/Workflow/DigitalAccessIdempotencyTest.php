<?php

namespace Tests\Unit\DigitalAccess\Workflow;

use App\Domain\DigitalAccess\Exceptions\DigitalAccessIdempotencyKeyConflictException;
use App\Domain\DigitalAccess\Models\AccessGrant;
use App\Domain\DigitalAccess\Provider\AccessResultStatus;
use App\Domain\DigitalAccess\Provider\Contracts\DigitalAccessProviderInterface;
use App\Domain\DigitalAccess\Provider\Data\AccessIssueRequest;
use App\Domain\DigitalAccess\Provider\Data\AccessOperationRequest;
use App\Domain\DigitalAccess\Provider\Data\AccessResult;
use App\Domain\DigitalAccess\Provider\SimulationDirective;
use App\Domain\Reservation\Models\Reservation;

class DigitalAccessIdempotencyTest extends DigitalAccessWorkflowTestCase
{
    private function countingProvider(): DigitalAccessProviderInterface
    {
        return new class implements DigitalAccessProviderInterface
        {
            public int $issueCalls = 0;

            public function issue(AccessIssueRequest $request): AccessResult
            {
                $this->issueCalls++;

                return new AccessResult(
                    AccessResultStatus::Active,
                    'dummy_access_issue_'.substr(md5($request->grantReference), 0, 12),
                    str_pad((string) (hexdec(substr(md5($request->grantReference), 0, 6)) % 1_000_000), 6, '0', STR_PAD_LEFT),
                    'dummy_access_issue_active',
                    'ok',
                );
            }

            public function revoke(AccessOperationRequest $request): AccessResult
            {
                return new AccessResult(AccessResultStatus::Revoked, 'ref', null, 'c', 'm');
            }
        };
    }

    public function test_same_idempotency_key_replays_without_a_second_provider_call(): void
    {
        $provider = $this->countingProvider();
        $service = $this->makeService($provider);
        $reservation = $this->verifiedReservation();

        $first = $service->checkIn($reservation, SimulationDirective::Success, idempotencyKey: 'ci-1');
        $second = $service->checkIn($reservation->fresh(), SimulationDirective::Success, idempotencyKey: 'ci-1');

        $this->assertSame(1, $provider->issueCalls);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(AccessGrant::STATUS_ACTIVE, $second->status);
        $this->assertSame(1, AccessGrant::count());
    }

    public function test_idempotency_key_reused_across_reservations_is_a_conflict(): void
    {
        $service = $this->makeService();

        $a = $this->verifiedReservation();
        $service->checkIn($a, SimulationDirective::Success, idempotencyKey: 'shared');

        $b = $this->verifiedReservation();

        $this->expectException(DigitalAccessIdempotencyKeyConflictException::class);
        $service->checkIn($b, SimulationDirective::Success, idempotencyKey: 'shared');
    }

    public function test_a_provider_result_applied_twice_is_a_no_op(): void
    {
        $service = $this->makeService();
        $reservation = $this->verifiedReservation();
        $grant = $service->checkIn($reservation, SimulationDirective::Success);

        $again = $service->applyIssueResult(
            $reservation->id,
            $grant->id,
            new AccessResult(AccessResultStatus::Active, $grant->provider_reference, '111111', 'c', 'm'),
        );

        $this->assertSame(AccessGrant::STATUS_ACTIVE, $again->status);
        // The credential from the first (real) issue is unchanged.
        $this->assertSame($grant->credential, $again->credential);
        $this->assertSame(Reservation::STATUS_IN_STAY, $reservation->fresh()->status);
    }

    public function test_no_idempotency_key_generates_one_and_still_works(): void
    {
        $reservation = $this->verifiedReservation();

        $grant = $this->makeService()->checkIn($reservation, SimulationDirective::Success);

        $this->assertNotNull($grant->idempotency_key);
        $this->assertSame(AccessGrant::STATUS_ACTIVE, $grant->status);
    }
}

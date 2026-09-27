<?php

namespace Tests\Unit\DigitalAccess\Workflow;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\DigitalAccess\Provider\AccessResultStatus;
use App\Domain\DigitalAccess\Provider\Contracts\DigitalAccessProviderInterface;
use App\Domain\DigitalAccess\Provider\Data\AccessIssueRequest;
use App\Domain\DigitalAccess\Provider\Data\AccessOperationRequest;
use App\Domain\DigitalAccess\Provider\Data\AccessResult;
use App\Domain\DigitalAccess\Provider\SimulationDirective;
use App\Domain\DigitalAccess\Repositories\Contracts\AccessGrantRepositoryInterface;
use App\Domain\DigitalAccess\Services\DigitalAccessService;
use App\Domain\IdentityVerification\Repositories\Contracts\IdentityVerificationSessionRepositoryInterface;
use App\Domain\Payment\Repositories\Contracts\PaymentRepositoryInterface;
use App\Domain\Reservation\Repositories\Contracts\ReservationRepositoryInterface;
use App\Domain\Reservation\Services\ReservationService;
use App\Http\Controllers\Api\V1\CheckInController;
use App\Http\Controllers\Api\V1\DigitalAccessController;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use ReflectionNamedType;

class DigitalAccessArchitectureTest extends DigitalAccessWorkflowTestCase
{
    private function source(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();

        $code = '';
        foreach (token_get_all(file_get_contents($file)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        return $code;
    }

    public function test_service_depends_only_on_approved_collaborators(): void
    {
        $constructor = (new ReflectionClass(DigitalAccessService::class))->getConstructor();

        $types = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            $this->assertInstanceOf(ReflectionNamedType::class, $type);
            $types[] = $type->getName();
        }
        sort($types);

        $expected = [
            AuditLogger::class,
            DigitalAccessProviderInterface::class,
            AccessGrantRepositoryInterface::class,
            IdentityVerificationSessionRepositoryInterface::class,
            PaymentRepositoryInterface::class,
            ReservationRepositoryInterface::class,
            ReservationService::class,
        ];
        sort($expected);

        $this->assertSame($expected, $types);
    }

    public function test_service_does_not_touch_models_or_the_query_builder_directly(): void
    {
        $code = $this->source(DigitalAccessService::class);

        foreach ([
            'AccessGrant::query(', 'AccessGrant::create(', 'AccessGrant::find(',
            'Reservation::query(', 'Reservation::find(', 'Payment::query(',
            'DB::table(', 'DB::select(', 'DB::statement(',
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $code, "must not use {$needle}");
        }
    }

    public function test_service_never_references_the_concrete_dummy_provider(): void
    {
        $this->assertStringNotContainsString('DummyDigitalAccessProvider', $this->source(DigitalAccessService::class));
    }

    public function test_reservation_transitions_go_through_reservation_service_only(): void
    {
        $code = $this->source(DigitalAccessService::class);

        $this->assertStringContainsString('reservationService->transitionTo(', $code);
        $this->assertStringNotContainsString('reservation->status = ', $code);
        $this->assertStringNotContainsString('reservations->update(', $code);
    }

    public function test_grant_status_changes_are_guarded_by_the_state_machine(): void
    {
        $code = $this->source(DigitalAccessService::class);

        $this->assertStringContainsString('DigitalAccessStateMachine::assertCanTransition(', $code);
        $this->assertStringNotContainsString('TRANSITIONS', $code);
    }

    public function test_reservation_and_payment_services_have_no_dependency_on_digital_access(): void
    {
        $reservation = file_get_contents((new ReflectionClass(ReservationService::class))->getFileName());
        $this->assertStringNotContainsString('App\Domain\DigitalAccess', $reservation);
    }

    public function test_controllers_are_thin(): void
    {
        foreach ([CheckInController::class, DigitalAccessController::class] as $class) {
            $code = $this->source($class);
            $this->assertStringNotContainsString('DB::', $code);
            $this->assertStringNotContainsString('::query(', $code);
            $this->assertStringNotContainsString('DigitalAccessStateMachine', $code);
            $this->assertStringNotContainsString('DigitalAccessProviderInterface', $code);
        }
    }

    public function test_no_provider_call_happens_inside_a_nested_database_transaction(): void
    {
        $baseline = DB::transactionLevel();

        $guard = new class($baseline) implements DigitalAccessProviderInterface
        {
            public array $levels = [];

            public function __construct(private readonly int $baseline) {}

            public function issue(AccessIssueRequest $request): AccessResult
            {
                $this->record();

                return new AccessResult(AccessResultStatus::Active, 'ref', '424242', 'dummy_access_issue_active', 'ok');
            }

            public function revoke(AccessOperationRequest $request): AccessResult
            {
                $this->record();

                return new AccessResult(AccessResultStatus::Revoked, 'ref', null, 'dummy_access_revoke_revoked', 'ok');
            }

            private function record(): void
            {
                $level = DB::transactionLevel();
                $this->levels[] = $level;
                if ($level > $this->baseline) {
                    throw new \RuntimeException('provider called inside a nested DB transaction');
                }
            }
        };

        $reservation = $this->verifiedReservation();
        $service = $this->makeService($guard);

        $grant = $service->checkIn($reservation, SimulationDirective::Success);
        $service->revoke($reservation->fresh(), null, SimulationDirective::Success);

        $this->assertSame([$baseline, $baseline], $guard->levels);
        $this->assertSame('active', $grant->status);
    }
}

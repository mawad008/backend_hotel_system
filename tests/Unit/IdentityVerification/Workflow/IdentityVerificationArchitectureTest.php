<?php

namespace Tests\Unit\IdentityVerification\Workflow;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\IdentityVerification\Provider\Contracts\IdentityVerificationProviderInterface;
use App\Domain\IdentityVerification\Provider\Data\VerificationRequest;
use App\Domain\IdentityVerification\Provider\Data\VerificationResult;
use App\Domain\IdentityVerification\Provider\MatchOutcome;
use App\Domain\IdentityVerification\Provider\SimulationDirective;
use App\Domain\IdentityVerification\Repositories\Contracts\IdentityVerificationAttemptRepositoryInterface;
use App\Domain\IdentityVerification\Repositories\Contracts\IdentityVerificationDecisionRepositoryInterface;
use App\Domain\IdentityVerification\Repositories\Contracts\IdentityVerificationSessionRepositoryInterface;
use App\Domain\IdentityVerification\Services\IdentityDocumentCheckService;
use App\Domain\IdentityVerification\Services\IdentityVerificationService;
use App\Domain\IdentityVerification\Support\IdentityFileStore;
use App\Domain\Reservation\Repositories\Contracts\ReservationRepositoryInterface;
use App\Domain\Reservation\Services\ReservationService;
use App\Http\Controllers\Api\V1\IdentityVerificationController;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use ReflectionNamedType;

class IdentityVerificationArchitectureTest extends IdentityVerificationWorkflowTestCase
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
        $constructor = (new ReflectionClass(IdentityVerificationService::class))->getConstructor();

        $types = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            $this->assertInstanceOf(ReflectionNamedType::class, $type);
            $types[] = $type->getName();
        }
        sort($types);

        $expected = [
            AuditLogger::class,
            IdentityFileStore::class,
            IdentityDocumentCheckService::class,
            IdentityVerificationAttemptRepositoryInterface::class,
            IdentityVerificationDecisionRepositoryInterface::class,
            IdentityVerificationProviderInterface::class,
            IdentityVerificationSessionRepositoryInterface::class,
            ReservationRepositoryInterface::class,
            ReservationService::class,
        ];
        sort($expected);

        $this->assertSame($expected, $types);
    }

    public function test_service_never_references_the_concrete_dummy_provider(): void
    {
        $code = $this->source(IdentityVerificationService::class);

        $this->assertStringNotContainsString('DummyIdentityVerificationProvider', $code);
    }

    public function test_service_does_not_touch_models_or_the_query_builder_directly(): void
    {
        $code = $this->source(IdentityVerificationService::class);

        foreach ([
            'IdentityVerificationSession::query(', 'IdentityVerificationSession::create(',
            'IdentityVerificationAttempt::query(', 'IdentityVerificationAttempt::create(',
            'IdentityVerificationDecision::create(',
            'Reservation::query(', 'Reservation::find(',
            'DB::table(', 'DB::select(', 'DB::statement(',
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $code, "must not use {$needle}");
        }
    }

    public function test_service_is_not_an_http_or_controller_component(): void
    {
        $code = $this->source(IdentityVerificationService::class);

        foreach (['Illuminate\Http\Request', 'Controller', 'FormRequest', 'Route::', 'response(', 'JsonResource'] as $needle) {
            $this->assertStringNotContainsString($needle, $code, "must not reference {$needle}");
        }
    }

    public function test_reservation_transitions_go_through_reservation_service_only(): void
    {
        $code = $this->source(IdentityVerificationService::class);

        $this->assertStringContainsString('reservationService->transitionTo(', $code);
        $this->assertStringNotContainsString('reservation->status = ', $code);
        $this->assertStringNotContainsString('reservations->update(', $code);
    }

    public function test_session_status_changes_are_guarded_by_the_state_machine(): void
    {
        $code = $this->source(IdentityVerificationService::class);

        $this->assertStringContainsString('IdentityVerificationStateMachine::assertCanTransition(', $code);
        $this->assertStringNotContainsString('TRANSITIONS', $code);
    }

    public function test_reservation_service_still_has_no_dependency_on_identity_verification(): void
    {
        $code = file_get_contents((new ReflectionClass(ReservationService::class))->getFileName());

        $this->assertStringNotContainsString('App\Domain\IdentityVerification', $code);
    }

    public function test_controller_never_names_the_provider_transaction_or_state_machine(): void
    {
        $code = $this->source(IdentityVerificationController::class);

        $this->assertStringNotContainsString('DummyIdentityVerificationProvider', $code);
        $this->assertStringNotContainsString('IdentityVerificationProviderInterface', $code);
        $this->assertStringNotContainsString('DB::', $code);
        $this->assertStringNotContainsString('IdentityVerificationStateMachine', $code);
        $this->assertStringNotContainsString('::query(', $code);
    }

    public function test_no_provider_call_happens_inside_a_database_transaction(): void
    {
        // RefreshDatabase wraps each test in a transaction, so an absolute
        // "level 0" check is meaningless here. Instead: capture the ambient
        // level and assert the provider sees exactly that — i.e. the
        // workflow opened NO additional transaction around the provider call.
        $baseline = DB::transactionLevel();

        $guard = new class($baseline) implements IdentityVerificationProviderInterface
        {
            public ?int $levelWhenCalled = null;

            public function __construct(private readonly int $baseline) {}

            public function verify(VerificationRequest $request): VerificationResult
            {
                $this->levelWhenCalled = DB::transactionLevel();

                if ($this->levelWhenCalled > $this->baseline) {
                    throw new \RuntimeException('provider was called inside a nested DB transaction');
                }

                return new VerificationResult(
                    outcome: MatchOutcome::HighMatch,
                    score: 90,
                    providerReference: 'dummy_idv_test',
                    providerCode: 'dummy_idv_high_match',
                    message: 'ok',
                );
            }
        };

        config(['verification.thresholds.auto_approve' => 80]);
        $reservation = $this->reservation();
        $service = $this->makeService($guard);

        $service->submitDocument($reservation, $this->image('doc.jpg'), 'passport', claim: $this->claim());
        $session = $service->submitSelfie($reservation, $this->image('selfie.jpg'), directive: SimulationDirective::HighMatch);

        $this->assertSame($baseline, $guard->levelWhenCalled);
        $this->assertSame('auto_approved', $session->status);
    }
}

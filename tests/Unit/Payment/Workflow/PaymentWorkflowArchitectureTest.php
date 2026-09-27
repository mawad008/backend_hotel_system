<?php

namespace Tests\Unit\Payment\Workflow;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Payment\Gateway\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Repositories\Contracts\PaymentRepositoryInterface;
use App\Domain\Payment\Repositories\Contracts\PaymentTransactionRepositoryInterface;
use App\Domain\Payment\Services\PaymentWorkflowService;
use App\Domain\Reservation\Repositories\Contracts\ReservationRepositoryInterface;
use App\Domain\Reservation\Services\ReservationService;
use ReflectionClass;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * Phase 5C §43 — architectural guardrails for PaymentWorkflowService.
 */
class PaymentWorkflowArchitectureTest extends TestCase
{
    private function source(): string
    {
        $file = (new ReflectionClass(PaymentWorkflowService::class))->getFileName();

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

    public function test_it_depends_only_on_the_approved_collaborators(): void
    {
        $constructor = (new ReflectionClass(PaymentWorkflowService::class))->getConstructor();

        $types = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            $this->assertInstanceOf(ReflectionNamedType::class, $type);
            $types[] = $type->getName();
        }

        sort($types);

        $this->assertSame([
            AuditLogger::class,
            PaymentGatewayInterface::class,
            PaymentRepositoryInterface::class,
            PaymentTransactionRepositoryInterface::class,
            ReservationRepositoryInterface::class,
            ReservationService::class,
        ], $types);
    }

    public function test_it_never_references_the_concrete_dummy_gateway(): void
    {
        $code = $this->source();

        $this->assertStringNotContainsString('DummyPaymentGateway', $code);
        $this->assertStringNotContainsString('new \App\Domain\Payment\Gateway\Dummy', $code);
    }

    public function test_it_does_not_touch_models_or_the_query_builder_directly(): void
    {
        $code = $this->source();

        foreach ([
            'Payment::query(', 'Payment::create(', 'Payment::find(',
            'PaymentTransaction::query(', 'PaymentTransaction::create(',
            'Reservation::query(', 'Reservation::find(',
            'DB::table(', 'DB::select(', 'DB::statement(', '::whereKey(',
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $code, "must not use {$needle}");
        }
    }

    public function test_it_is_not_an_http_or_controller_component(): void
    {
        $code = $this->source();

        foreach (['Illuminate\Http\Request', 'Controller', 'FormRequest', 'Route::', 'response(', 'JsonResource'] as $needle) {
            $this->assertStringNotContainsString($needle, $code, "must not reference {$needle}");
        }
    }

    public function test_reservation_transitions_go_through_reservation_service_only(): void
    {
        $code = $this->source();

        // The only way the workflow changes a reservation status.
        $this->assertStringContainsString('reservationService->transitionTo(', $code);
        // Never a raw status assignment / save on a reservation.
        $this->assertStringNotContainsString('reservation->status = ', $code);
        $this->assertStringNotContainsString('reservation->save(', $code);
        $this->assertStringNotContainsString('reservations->update(', $code);
    }

    public function test_payment_status_changes_are_guarded_by_the_state_machine(): void
    {
        $code = $this->source();

        $this->assertStringContainsString('PaymentStateMachine::assertCanTransition(', $code);
        // The transition map itself is never duplicated here.
        $this->assertStringNotContainsString('TRANSITIONS', $code);
    }

    public function test_reservation_service_still_has_no_dependency_on_payment(): void
    {
        $file = (new ReflectionClass(ReservationService::class))->getFileName();
        $code = file_get_contents($file);

        $this->assertStringNotContainsString('App\Domain\Payment', $code);
        $this->assertStringNotContainsString('PaymentWorkflowService', $code);
    }
}

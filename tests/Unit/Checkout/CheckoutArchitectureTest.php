<?php

namespace Tests\Unit\Checkout;

use App\Domain\Checkout\Services\CheckoutService;
use App\Domain\Checkout\Services\InvoiceService;
use App\Domain\Payment\Services\PaymentSettlementService;
use App\Domain\Payment\Services\PaymentWorkflowService;
use App\Domain\Reservation\Services\ReservationService;
use App\Domain\StayServices\Services\FolioService;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Requests\Api\V1\Checkout\PerformCheckoutRequest;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class CheckoutArchitectureTest extends TestCase
{
    private function source(string $class): string
    {
        $code = '';
        foreach (token_get_all(file_get_contents((new ReflectionClass($class))->getFileName())) as $token) {
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

    public function test_controllers_are_thin(): void
    {
        foreach ([CheckoutController::class, InvoiceController::class] as $class) {
            $code = $this->source($class);
            $this->assertStringNotContainsString('DB::', $code, "{$class} uses DB::");
            $this->assertStringNotContainsString('::query(', $code, "{$class} uses ::query(");
            $this->assertStringNotContainsString('StateMachine', $code);
            $this->assertStringNotContainsString('PaymentGatewayInterface', $code);
        }
    }

    public function test_services_do_not_touch_the_query_builder_or_models_directly(): void
    {
        foreach ([CheckoutService::class, InvoiceService::class, PaymentSettlementService::class] as $class) {
            $code = $this->source($class);
            foreach ([
                'DB::table(', 'DB::select(', 'DB::statement(',
                'Checkout::query(', 'Checkout::create(',
                'Invoice::query(', 'Invoice::create(',
                'Payment::query(', 'PaymentTransaction::query(',
                'Reservation::query(',
            ] as $needle) {
                $this->assertStringNotContainsString($needle, $code, "{$class} must not use {$needle}");
            }
        }
    }

    public function test_the_provider_is_only_reached_from_the_settlement_service(): void
    {
        $this->assertStringNotContainsString('gateway->', $this->source(CheckoutService::class));
        $this->assertStringContainsString('gateway->settle(', $this->source(PaymentSettlementService::class));
    }

    public function test_reservation_transitions_go_through_reservation_service_only(): void
    {
        $code = $this->source(CheckoutService::class);
        $this->assertStringContainsString('reservationService->transitionTo(', $code);
        $this->assertStringNotContainsString('reservation->status = ', $code);
        $this->assertStringNotContainsString('->update([\'status\' => Reservation::STATUS', $code);
    }

    public function test_status_changes_are_guarded_by_state_machines(): void
    {
        $checkout = $this->source(CheckoutService::class);
        $this->assertStringContainsString('CheckoutStateMachine::assertCanTransition(', $checkout);

        $settlement = $this->source(PaymentSettlementService::class);
        $this->assertStringContainsString('PaymentStateMachine::assertCanTransition(', $settlement);
        $this->assertStringNotContainsString('TRANSITIONS', $settlement);
    }

    public function test_money_maths_never_uses_float(): void
    {
        foreach ([CheckoutService::class, PaymentSettlementService::class, InvoiceService::class] as $class) {
            $this->assertStringNotContainsString('(float)', $this->source($class), "{$class} uses a float cast");
        }
    }

    public function test_client_amount_is_never_read(): void
    {
        // Neither the Form Request nor the controller reads an amount from
        // the request — the settlement amount is computed from the folio.
        $request = $this->source(PerformCheckoutRequest::class);
        $this->assertStringNotContainsString("'amount'", $request);

        $controller = $this->source(CheckoutController::class);
        $this->assertStringNotContainsString("'amount'", $controller);
        $this->assertStringNotContainsString('->amount', $controller);
    }

    public function test_reservation_and_payment_domains_do_not_depend_on_checkout(): void
    {
        foreach ([
            ReservationService::class,
            PaymentWorkflowService::class,
            FolioService::class,
        ] as $class) {
            $raw = file_get_contents((new ReflectionClass($class))->getFileName());
            $this->assertStringNotContainsString('App\Domain\Checkout', $raw, "{$class} depends on Checkout");
        }
    }
}

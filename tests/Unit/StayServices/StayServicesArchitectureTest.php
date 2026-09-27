<?php

namespace Tests\Unit\StayServices;

use App\Domain\Payment\Services\PaymentWorkflowService;
use App\Domain\Reservation\Services\ReservationService;
use App\Domain\StayServices\Services\FolioService;
use App\Domain\StayServices\Services\ServiceCatalogService;
use App\Domain\StayServices\Services\ServiceOrderService;
use App\Http\Controllers\Api\V1\FolioController;
use App\Http\Controllers\Api\V1\ServiceCategoryController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\Api\V1\ServiceOrderController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class StayServicesArchitectureTest extends TestCase
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
        foreach ([
            ServiceCategoryController::class,
            ServiceController::class,
            ServiceOrderController::class,
            FolioController::class,
        ] as $class) {
            $code = $this->source($class);
            $this->assertStringNotContainsString('DB::', $code, "{$class} uses DB::");
            $this->assertStringNotContainsString('::query(', $code, "{$class} uses ::query(");
            $this->assertStringNotContainsString('StateMachine', $code, "{$class} references a state machine");
            $this->assertStringNotContainsString('FolioCharge::', $code, "{$class} touches a model");
        }
    }

    public function test_services_do_not_touch_the_query_builder_or_models_directly(): void
    {
        foreach ([ServiceCatalogService::class, ServiceOrderService::class, FolioService::class] as $class) {
            $code = $this->source($class);
            foreach ([
                'DB::table(', 'DB::select(', 'DB::statement(',
                'ServiceOrder::query(', 'ServiceOrder::create(', 'ServiceOrder::find(',
                'FolioCharge::query(', 'FolioCharge::create(',
                'HotelService::query(', 'Reservation::query(', 'Payment::query(',
            ] as $needle) {
                $this->assertStringNotContainsString($needle, $code, "{$class} must not use {$needle}");
            }
        }
    }

    public function test_order_status_changes_are_guarded_by_the_state_machine(): void
    {
        $code = $this->source(ServiceOrderService::class);
        $this->assertStringContainsString('ServiceOrderStateMachine::assertCanTransition(', $code);
        $this->assertStringNotContainsString('TRANSITIONS', $code);
    }

    public function test_money_maths_never_uses_float_arithmetic(): void
    {
        $order = $this->source(ServiceOrderService::class);
        // bcmath only for the line total; no "* $quantity" style float maths.
        $this->assertStringContainsString('bcmul(', $order);
        $this->assertStringNotContainsString('(float)', $order);

        $folio = $this->source(FolioService::class);
        $this->assertStringContainsString('bcsub(', $folio);
    }

    public function test_folio_service_derives_payments_total_from_the_payment_domain(): void
    {
        $code = $this->source(FolioService::class);
        // payments_total is the payment transaction history (Phase 9 review
        // fix) — resolved through the Payment domain's repository, never a
        // hardcoded status list or `payments.amount` read.
        $this->assertStringContainsString('paymentTransactions->sumCollectedForPayment(', $code);
        $this->assertStringNotContainsString("'captured'", $code);
        $this->assertStringNotContainsString("'settled'", $code);
        $this->assertStringNotContainsString('payment->amount', $code);
    }

    public function test_reservation_and_payment_domains_do_not_depend_on_stay_services(): void
    {
        foreach ([
            ReservationService::class,
            PaymentWorkflowService::class,
        ] as $class) {
            $raw = file_get_contents((new ReflectionClass($class))->getFileName());
            $this->assertStringNotContainsString('App\Domain\StayServices', $raw, "{$class} must not depend on StayServices");
        }
    }
}

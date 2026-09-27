<?php

namespace Tests\Unit\Payment\Gateway;

use App\Domain\Payment\Gateway\Data\GatewayHoldRequest;
use App\Domain\Payment\Gateway\Data\GatewayOperationRequest;
use App\Domain\Payment\Gateway\DummyPaymentGateway;
use App\Domain\Payment\Gateway\GatewayOperation;
use App\Domain\Payment\Gateway\GatewayResultStatus;
use App\Domain\Payment\Gateway\SimulationDirective;
use App\Domain\Payment\Models\PaymentTransaction;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 5B test matrix B–F, I, J — the deterministic dummy gateway
 * operations (Phase 5B instructions §5–§12, §20).
 */
class DummyPaymentGatewayTest extends TestCase
{
    private const SECRET = 'phase-5b-test-secret';

    private function gateway(SimulationDirective $default = SimulationDirective::Success): DummyPaymentGateway
    {
        return new DummyPaymentGateway(self::SECRET, $default);
    }

    private function assertNoPaymentRowsWritten(): void
    {
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_transactions', 0);
        $this->assertDatabaseCount('payment_webhook_events', 0);
    }

    // ---- B. Hold -----------------------------------------------------------

    /**
     * @return array<string, array{SimulationDirective, GatewayResultStatus}>
     */
    public static function directiveOutcomes(): array
    {
        return [
            'success -> succeeded' => [SimulationDirective::Success, GatewayResultStatus::Succeeded],
            'pending -> pending' => [SimulationDirective::Pending, GatewayResultStatus::Pending],
            'failure -> failed' => [SimulationDirective::Failure, GatewayResultStatus::Failed],
            'cancelled -> cancelled' => [SimulationDirective::Cancelled, GatewayResultStatus::Cancelled],
            'expired -> expired' => [SimulationDirective::Expired, GatewayResultStatus::Expired],
        ];
    }

    #[DataProvider('directiveOutcomes')]
    public function test_initiate_hold_maps_each_directive_to_a_deterministic_status(
        SimulationDirective $directive,
        GatewayResultStatus $expected,
    ): void {
        $result = $this->gateway()->initiateHold(
            new GatewayHoldRequest('pay_1', '150.00', 'EUR', $directive),
        );

        $this->assertSame(GatewayOperation::Hold, $result->operation);
        $this->assertSame($expected, $result->status);
        $this->assertSame("dummy_hold_{$expected->value}", $result->providerCode);
        $this->assertNoPaymentRowsWritten();
    }

    public function test_initiate_hold_defaults_to_success_when_no_directive_is_given(): void
    {
        $result = $this->gateway()->initiateHold(new GatewayHoldRequest('pay_1', '10.00'));

        $this->assertSame(GatewayResultStatus::Succeeded, $result->status);
    }

    public function test_initiate_hold_honours_the_configured_default_directive(): void
    {
        $result = $this->gateway(SimulationDirective::Pending)
            ->initiateHold(new GatewayHoldRequest('pay_1', '10.00'));

        $this->assertSame(GatewayResultStatus::Pending, $result->status);
    }

    public function test_initiate_hold_returns_a_deterministic_dummy_provider_reference(): void
    {
        $a = $this->gateway()->initiateHold(new GatewayHoldRequest('pay_42', '10.00'));
        $b = $this->gateway()->initiateHold(new GatewayHoldRequest('pay_42', '10.00'));
        $other = $this->gateway()->initiateHold(new GatewayHoldRequest('pay_99', '10.00'));

        $this->assertSame($a->providerReference, $b->providerReference);
        $this->assertNotSame($a->providerReference, $other->providerReference);
        $this->assertStringStartsWith('dummy_hold_', $a->providerReference);
    }

    public function test_hold_writes_nothing_to_the_database(): void
    {
        $this->assertNoPaymentRowsWritten();

        $this->gateway()->initiateHold(new GatewayHoldRequest('pay_1', '10.00'));

        $this->assertNoPaymentRowsWritten();
    }

    // ---- C/D/E/F. cancelHold, capture, settle, verify --------------------

    /**
     * @return array<string, array{string, GatewayOperation}>
     */
    public static function followUpOperations(): array
    {
        return [
            'cancelHold' => ['cancelHold', GatewayOperation::CancelHold],
            'capture' => ['capture', GatewayOperation::Capture],
            'settle' => ['settle', GatewayOperation::Settlement],
            'verify' => ['verify', GatewayOperation::Verify],
        ];
    }

    #[DataProvider('followUpOperations')]
    public function test_follow_up_operation_returns_a_deterministic_result_and_writes_nothing(
        string $method,
        GatewayOperation $operation,
    ): void {
        $gateway = $this->gateway();
        $request = new GatewayOperationRequest('dummy_hold_abc123');

        $first = $gateway->{$method}($request);
        $second = $gateway->{$method}($request);

        $this->assertSame($operation, $first->operation);
        $this->assertSame(GatewayResultStatus::Succeeded, $first->status);
        $this->assertSame($first->providerReference, $second->providerReference);
        $this->assertStringStartsWith("dummy_{$operation->value}_", $first->providerReference);
        $this->assertNoPaymentRowsWritten();
    }

    #[DataProvider('followUpOperations')]
    public function test_follow_up_operation_honours_an_explicit_failure_directive(
        string $method,
        GatewayOperation $operation,
    ): void {
        $result = $this->gateway()->{$method}(
            new GatewayOperationRequest('dummy_hold_abc123', SimulationDirective::Failure),
        );

        $this->assertSame(GatewayResultStatus::Failed, $result->status);
    }

    public function test_operations_never_throw_for_a_declined_outcome(): void
    {
        // A failed provider outcome is a status, not an exception.
        $result = $this->gateway()->capture(
            new GatewayOperationRequest('dummy_hold_x', SimulationDirective::Failure),
        );

        $this->assertFalse($result->isSuccessful());
    }

    // ---- J. Determinism --------------------------------------------------

    public function test_the_same_operation_and_directive_produce_an_equivalent_result(): void
    {
        $request = new GatewayHoldRequest('pay_1', '20.00', 'EUR', SimulationDirective::Pending, ['note' => 'x']);

        $this->assertEquals(
            $this->gateway()->initiateHold($request)->toArray(),
            $this->gateway()->initiateHold($request)->toArray(),
        );
    }

    public function test_operation_does_not_sleep_or_take_wall_clock_time(): void
    {
        $start = microtime(true);

        for ($i = 0; $i < 200; $i++) {
            $this->gateway()->initiateHold(new GatewayHoldRequest("pay_{$i}", '10.00'));
        }

        $this->assertLessThan(1.0, microtime(true) - $start);
    }

    public function test_gateway_source_contains_no_randomness_or_sleep(): void
    {
        $file = (new \ReflectionClass(DummyPaymentGateway::class))->getFileName();

        // Strip comments/docblocks — only real code counts.
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

        foreach (['rand(', 'mt_rand', 'random_int', 'random_bytes', 'uniqid', 'Str::uuid', 'Str::random', 'sleep(', 'usleep(', 'microtime', 'time()', 'now()', 'Carbon'] as $needle) {
            $this->assertStringNotContainsString($needle, $code, "DummyPaymentGateway must not use {$needle}");
        }
    }

    // ---- I. Security ----------------------------------------------------

    public function test_the_result_never_contains_the_webhook_secret(): void
    {
        $result = $this->gateway()->initiateHold(
            new GatewayHoldRequest('pay_1', '10.00', 'EUR', null, ['channel' => 'web']),
        );

        $this->assertStringNotContainsString(self::SECRET, json_encode($result->toArray()));
    }

    public function test_hold_request_rejects_sensitive_payment_data(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GatewayHoldRequest('pay_1', '10.00', 'EUR', null, ['card_number' => '4111111111111111']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function sensitiveKeys(): array
    {
        return [
            ['cvv'],
            ['CVC'],
            ['card-number'],
            ['cardholder_name'],
            ['exp_month'],
            ['pan'],
            ['card pin'],
        ];
    }

    #[DataProvider('sensitiveKeys')]
    public function test_operation_request_rejects_each_sensitive_key(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GatewayOperationRequest('dummy_hold_x', null, [$key => 'value']);
    }

    public function test_metadata_values_must_be_scalar(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GatewayHoldRequest('pay_1', '10.00', 'EUR', null, ['nested' => ['a' => 'b']]);
    }

    public function test_safe_metadata_is_echoed_back_untouched(): void
    {
        $result = $this->gateway()->capture(
            new GatewayOperationRequest('dummy_hold_x', null, ['reason' => 'check-in', 'attempt' => 2]),
        );

        $this->assertSame(['reason' => 'check-in', 'attempt' => 2], $result->context);
    }

    // ---- Cross-check with the Phase 5A persistence vocabulary -----------

    public function test_result_status_values_match_the_payment_transaction_status_vocabulary(): void
    {
        $gatewayStatuses = array_map(fn ($c) => $c->value, GatewayResultStatus::cases());
        sort($gatewayStatuses);

        $transactionStatuses = PaymentTransaction::STATUSES;
        sort($transactionStatuses);

        $this->assertSame($transactionStatuses, $gatewayStatuses);
    }

    public function test_operation_values_are_a_subset_of_the_payment_transaction_type_vocabulary(): void
    {
        foreach (GatewayOperation::cases() as $operation) {
            $this->assertContains($operation->value, PaymentTransaction::TYPES);
        }
    }
}

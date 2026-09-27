<?php

namespace Tests\Unit\Payment\Workflow;

use App\Domain\Payment\Exceptions\InvalidPaymentAmountException;
use App\Domain\Payment\Exceptions\InvalidPaymentCurrencyException;
use App\Domain\Payment\Exceptions\PaymentHoldNotAllowedException;
use App\Domain\Payment\Gateway\SimulationDirective;
use App\Domain\Reservation\Models\Reservation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Unit\Payment\Workflow\Fakes\RecordingPaymentGateway;

/**
 * Phase 5C test matrix F, O — hold pre-conditions: the Reservation must be
 * PENDING, the amount must be a positive DECIMAL(12,2) value, and no
 * currency is ever invented.
 */
class PaymentWorkflowPreconditionsTest extends PaymentWorkflowTestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function nonPendingStatuses(): array
    {
        return [
            'deposit_held' => [Reservation::STATUS_DEPOSIT_HELD],
            'verified' => [Reservation::STATUS_VERIFIED],
            'checked_in' => [Reservation::STATUS_CHECKED_IN],
            'in_stay' => [Reservation::STATUS_IN_STAY],
            'cancelled' => [Reservation::STATUS_CANCELLED],
            'checked_out' => [Reservation::STATUS_CHECKED_OUT],
            'invoiced' => [Reservation::STATUS_INVOICED],
        ];
    }

    #[DataProvider('nonPendingStatuses')]
    public function test_hold_is_rejected_when_the_reservation_is_not_pending(string $status): void
    {
        $reservation = Reservation::factory()->create(['status' => $status]);
        $gateway = new RecordingPaymentGateway;

        try {
            $this->makeWorkflow($gateway)->initiateHold($reservation, '10.00', null, null, SimulationDirective::Success, null);
            $this->fail('Expected a PaymentHoldNotAllowedException.');
        } catch (PaymentHoldNotAllowedException $e) {
            $this->assertSame($status, $e->reservationStatus);
        }

        $this->assertSame(0, $gateway->initiateHoldCalls);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_hold_is_rejected_when_the_reservation_does_not_exist(): void
    {
        $reservation = Reservation::factory()->create();
        $id = $reservation->id;
        Reservation::whereKey($id)->delete();

        $this->expectException(ModelNotFoundException::class);

        $this->makeWorkflow()->initiateHold($reservation, '10.00', null, null, SimulationDirective::Success, null);
    }

    // ── O. Amount validation ────────────────────────────────────────

    /**
     * @return array<string, array{int|float|string, string}>
     */
    public static function validAmounts(): array
    {
        return [
            'integer' => [100, '100.00'],
            'string two dp' => ['150.25', '150.25'],
            'string one dp' => ['150.2', '150.20'],
            'string integer' => ['150', '150.00'],
            'float one dp' => [150.5, '150.50'],
            'max decimal 12,2' => ['9999999999.99', '9999999999.99'],
            'small' => ['0.01', '0.01'],
        ];
    }

    #[DataProvider('validAmounts')]
    public function test_valid_amount_is_normalized_and_persisted(int|float|string $amount, string $expected): void
    {
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_PENDING]);

        $payment = $this->makeWorkflow()->initiateHold($reservation, $amount, null, null, SimulationDirective::Success, null);

        $this->assertSame($expected, $payment->amount);
    }

    /**
     * @return array<string, array{int|float|string}>
     */
    public static function invalidAmounts(): array
    {
        return [
            'zero' => [0],
            'zero string' => ['0.00'],
            'negative int' => [-5],
            'negative string' => ['-5.00'],
            'three decimals' => ['10.999'],
            'float sub-cent' => [10.999],
            'too many integer digits' => ['100000000000'],
            'not numeric' => ['abc'],
            'empty' => [''],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_amount_is_rejected_before_any_persistence(int|float|string $amount): void
    {
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_PENDING]);
        $gateway = new RecordingPaymentGateway;

        $this->expectException(InvalidPaymentAmountException::class);

        try {
            $this->makeWorkflow($gateway)->initiateHold($reservation, $amount, null, null, SimulationDirective::Success, null);
        } finally {
            $this->assertSame(0, $gateway->initiateHoldCalls);
            $this->assertDatabaseCount('payments', 0);
        }
    }

    // ── O. Currency ────────────────────────────────────────────────

    public function test_currency_is_null_when_none_is_supplied_or_configured(): void
    {
        config(['payment.currency' => null]);
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_PENDING]);

        $payment = $this->makeWorkflow()->initiateHold($reservation, '10.00', null, null, SimulationDirective::Success, null);

        $this->assertNull($payment->currency);
    }

    public function test_configured_currency_is_used_when_the_caller_gives_none(): void
    {
        config(['payment.currency' => 'usd']);
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_PENDING]);

        $payment = $this->makeWorkflow()->initiateHold($reservation, '10.00', null, null, SimulationDirective::Success, null);

        $this->assertSame('USD', $payment->currency);
    }

    public function test_explicit_currency_argument_wins_over_config(): void
    {
        config(['payment.currency' => 'USD']);
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_PENDING]);

        $payment = $this->makeWorkflow()->initiateHold($reservation, '10.00', 'gbp', null, SimulationDirective::Success, null);

        $this->assertSame('GBP', $payment->currency);
    }

    public function test_malformed_currency_is_rejected(): void
    {
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_PENDING]);

        $this->expectException(InvalidPaymentCurrencyException::class);

        $this->makeWorkflow()->initiateHold($reservation, '10.00', 'EURO', null, SimulationDirective::Success, null);
    }
}

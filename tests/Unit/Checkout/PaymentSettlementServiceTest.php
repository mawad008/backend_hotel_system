<?php

namespace Tests\Unit\Checkout;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Payment\Exceptions\IdempotencyKeyConflictException;
use App\Domain\Payment\Exceptions\PaymentSettlementNotAllowedException;
use App\Domain\Payment\Gateway\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Gateway\Data\GatewayHoldRequest;
use App\Domain\Payment\Gateway\Data\GatewayOperationRequest;
use App\Domain\Payment\Gateway\Data\GatewayResult;
use App\Domain\Payment\Gateway\Data\NormalizedWebhook;
use App\Domain\Payment\Gateway\GatewayOperation;
use App\Domain\Payment\Gateway\GatewayResultStatus;
use App\Domain\Payment\Gateway\SimulationDirective;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentTransaction;
use App\Domain\Payment\Services\PaymentSettlementService;
use App\Domain\Reservation\Models\Reservation;
use Illuminate\Support\Facades\DB;

class PaymentSettlementServiceTest extends CheckoutTestCase
{
    private function service(?PaymentGatewayInterface $gateway = null): PaymentSettlementService
    {
        if ($gateway !== null) {
            $this->app->instance(PaymentGatewayInterface::class, $gateway);
        }

        return $this->app->make(PaymentSettlementService::class);
    }

    public function test_walks_hold_active_through_capture_to_settled(): void
    {
        $reservation = $this->inStayReservation();
        $reservation->update(['status' => Reservation::STATUS_CHECKOUT_IN_PROGRESS]);

        $payment = $this->service()->settle(
            $reservation, settlementAmount: '45.00', currency: 'USD',
            directive: SimulationDirective::Success,
        );

        $this->assertSame(Payment::STATUS_SETTLED, $payment->status);
        // payments.amount is NOT overwritten — it keeps the held deposit (0.00 here).
        $this->assertSame('0.00', $payment->amount);
        $txn = PaymentTransaction::sole();
        $this->assertSame(PaymentTransaction::STATUS_SUCCEEDED, $txn->status);
        $this->assertSame(PaymentTransaction::TYPE_SETTLEMENT, $txn->type);
        // the settlement transaction records the collected amount.
        $this->assertSame('45.00', $txn->amount);
    }

    public function test_no_payment_is_a_settlement_not_allowed_error(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $reservation = Reservation::factory()->create([
            'hotel_id' => $hotel->id, 'room_type_id' => $roomType->id,
            'status' => Reservation::STATUS_CHECKOUT_IN_PROGRESS,
        ]);

        $this->expectException(PaymentSettlementNotAllowedException::class);
        $this->service()->settle($reservation, '10.00', 'USD', directive: SimulationDirective::Success);
    }

    public function test_failed_provider_outcome_leaves_payment_settlement_failed(): void
    {
        $reservation = $this->inStayReservation();

        $payment = $this->service()->settle($reservation, '20.00', 'USD', directive: SimulationDirective::Failure);

        $this->assertSame(Payment::STATUS_SETTLEMENT_FAILED, $payment->status);
        $this->assertSame(PaymentTransaction::STATUS_FAILED, PaymentTransaction::sole()->status);
    }

    public function test_pending_provider_outcome_keeps_payment_final_settlement_requested(): void
    {
        $reservation = $this->inStayReservation();

        $payment = $this->service()->settle($reservation, '20.00', 'USD', directive: SimulationDirective::Pending);

        $this->assertSame(Payment::STATUS_FINAL_SETTLEMENT_REQUESTED, $payment->status);
        $this->assertSame(PaymentTransaction::STATUS_PENDING, PaymentTransaction::sole()->status);
    }

    public function test_same_key_replays_a_succeeded_settlement_without_a_second_provider_call(): void
    {
        $reservation = $this->inStayReservation();
        $gateway = new CountingSettleGateway;
        $svc = $this->service($gateway);

        $svc->settle($reservation, '20.00', 'USD', idempotencyKey: 'k1', directive: SimulationDirective::Success);
        $svc->settle($reservation->fresh(), '20.00', 'USD', idempotencyKey: 'k1', directive: SimulationDirective::Success);

        $this->assertSame(1, $gateway->settleCalls);
        $this->assertSame(1, PaymentTransaction::count());
    }

    public function test_reused_key_from_a_hold_operation_is_a_conflict(): void
    {
        $reservation = $this->inStayReservation();
        PaymentTransaction::factory()->create([
            'payment_id' => $reservation->payment->id,
            'type' => PaymentTransaction::TYPE_HOLD,
            'idempotency_key' => 'shared-key',
        ]);

        $this->expectException(IdempotencyKeyConflictException::class);
        $this->service()->settle($reservation, '20.00', 'USD', idempotencyKey: 'shared-key', directive: SimulationDirective::Success);
    }

    public function test_provider_is_never_called_inside_a_db_transaction(): void
    {
        $reservation = $this->inStayReservation();
        $baseline = DB::transactionLevel();
        $gateway = new CountingSettleGateway($baseline);

        $this->service($gateway)->settle($reservation, '30.00', 'USD', directive: SimulationDirective::Success);

        $this->assertSame([$baseline], $gateway->levelsAtCall);
    }
}

/**
 * A gateway that counts settle() calls and records the DB transaction level
 * at the moment of the call.
 */
class CountingSettleGateway implements PaymentGatewayInterface
{
    public int $settleCalls = 0;

    /** @var list<int> */
    public array $levelsAtCall = [];

    private readonly int $baseline;

    public function __construct(?int $baseline = null)
    {
        // RefreshDatabase wraps each test in a transaction, so the "outside a
        // transaction" baseline is whatever level the test starts at.
        $this->baseline = $baseline ?? DB::transactionLevel();
    }

    public function settle(GatewayOperationRequest $request): GatewayResult
    {
        $this->settleCalls++;
        $level = DB::transactionLevel();
        $this->levelsAtCall[] = $level;

        if ($level > $this->baseline) {
            throw new \RuntimeException('settle() called inside a nested DB transaction');
        }

        return new GatewayResult(
            GatewayOperation::Settlement,
            GatewayResultStatus::Succeeded,
            'dummy_settlement_ref',
            'dummy_settlement_succeeded',
            'ok',
        );
    }

    public function initiateHold(GatewayHoldRequest $request): GatewayResult
    {
        return new GatewayResult(GatewayOperation::Hold, GatewayResultStatus::Succeeded, 'r', 'c', 'm');
    }

    public function cancelHold(GatewayOperationRequest $request): GatewayResult
    {
        return new GatewayResult(GatewayOperation::CancelHold, GatewayResultStatus::Succeeded, 'r', 'c', 'm');
    }

    public function capture(GatewayOperationRequest $request): GatewayResult
    {
        return new GatewayResult(GatewayOperation::Capture, GatewayResultStatus::Succeeded, 'r', 'c', 'm');
    }

    public function verify(GatewayOperationRequest $request): GatewayResult
    {
        return new GatewayResult(GatewayOperation::Verify, GatewayResultStatus::Succeeded, 'r', 'c', 'm');
    }

    public function parseWebhook(string $rawBody): NormalizedWebhook
    {
        return new NormalizedWebhook(null, 'test', null, null, hash('sha256', $rawBody), []);
    }

    public function verifySignature(string $rawBody, ?string $signature): bool
    {
        return false;
    }
}

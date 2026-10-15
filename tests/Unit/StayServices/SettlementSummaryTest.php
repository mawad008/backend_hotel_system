<?php

namespace Tests\Unit\StayServices;

use App\Domain\Payment\Models\Payment;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\StayServices\Models\FolioCharge;
use App\Domain\StayServices\Services\Folio;
use App\Domain\StayServices\Services\SettlementSummary;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * The checkout settlement read model — DB-free (in-memory models).
 *
 * Fixture: 2 nights = 640.00, service fee 45.00, tax 15% of 685.00 =
 * 102.75, services 80.00 → total 867.75.
 */
class SettlementSummaryTest extends BaseTestCase
{
    private function folio(?Payment $payment, string $paid = '0.00', array $extraCharges = [], string $status = Reservation::STATUS_IN_STAY): Folio
    {
        $reservation = new Reservation([
            'price_snapshot' => '640.00', 'service_fee_amount' => '45.00', 'tax_rate' => '15.00', 'status' => $status,
        ]);
        $charges = collect([
            new FolioCharge(['source_type' => FolioCharge::SOURCE_SERVICE_ORDER, 'total_amount' => '50.00', 'status' => FolioCharge::STATUS_POSTED]),
            new FolioCharge(['source_type' => FolioCharge::SOURCE_SERVICE_ORDER, 'total_amount' => '30.00', 'status' => FolioCharge::STATUS_POSTED]),
            new FolioCharge(['source_type' => FolioCharge::SOURCE_SERVICE_ORDER, 'total_amount' => '99.00', 'status' => FolioCharge::STATUS_CANCELLED]),
            ...$extraCharges,
        ]);

        return new Folio($reservation, $charges, $payment, '80.00', $paid, '80.00', 'SAR');
    }

    private function hold(string $status, string $amount = '160.00'): Payment
    {
        return new Payment(['status' => $status, 'amount' => $amount, 'currency' => 'SAR']);
    }

    public function test_before_checkout_the_total_includes_the_stay_not_just_services(): void
    {
        $s = SettlementSummary::of($this->folio($this->hold(Payment::STATUS_HOLD_ACTIVE)));

        $this->assertSame('640.00', $s['accommodation']);
        $this->assertSame('80.00', $s['services']);
        $this->assertSame('45.00', $s['service_fee']);
        $this->assertSame('102.75', $s['tax']);
        $this->assertSame('867.75', $s['total']);
        $this->assertFalse($s['is_final']);
    }

    public function test_an_open_deposit_hold_is_applied_to_the_bill_first(): void
    {
        $s = SettlementSummary::of($this->folio($this->hold(Payment::STATUS_HOLD_ACTIVE)));

        $this->assertSame(SettlementSummary::DEPOSIT_HELD, $s['deposit']['state']);
        $this->assertSame('160.00', $s['deposit']['applied']);
        $this->assertSame('0.00', $s['deposit']['released']);
        $this->assertSame('707.75', $s['balance_due']);
    }

    public function test_a_deposit_larger_than_the_bill_releases_the_rest(): void
    {
        $s = SettlementSummary::of($this->folio($this->hold(Payment::STATUS_HOLD_ACTIVE, '1000.00')));

        $this->assertSame('867.75', $s['deposit']['applied']);
        $this->assertSame('132.25', $s['deposit']['released']);
        $this->assertSame('0.00', $s['balance_due']);
    }

    public function test_after_settlement_the_deposit_is_inside_paid_and_nothing_is_due(): void
    {
        $s = SettlementSummary::of($this->folio($this->hold(Payment::STATUS_SETTLED), '867.75', status: Reservation::STATUS_INVOICED));

        $this->assertSame(SettlementSummary::DEPOSIT_APPLIED, $s['deposit']['state']);
        $this->assertSame('160.00', $s['deposit']['applied']);
        $this->assertSame('0.00', $s['balance_due']);
        $this->assertTrue($s['is_final']);
    }

    public function test_a_released_hold_is_returned_in_full(): void
    {
        $s = SettlementSummary::of($this->folio($this->hold(Payment::STATUS_CANCELLED)));

        $this->assertSame(SettlementSummary::DEPOSIT_RELEASED, $s['deposit']['state']);
        $this->assertSame('160.00', $s['deposit']['released']);
        $this->assertSame('867.75', $s['balance_due']);
    }

    public function test_no_payment_means_no_deposit(): void
    {
        $s = SettlementSummary::of($this->folio(null));

        $this->assertSame(SettlementSummary::DEPOSIT_NONE, $s['deposit']['state']);
        $this->assertSame('867.75', $s['balance_due']);
    }

    public function test_a_loyalty_discount_reduces_the_total(): void
    {
        $credit = new FolioCharge(['source_type' => FolioCharge::SOURCE_LOYALTY_REDEMPTION, 'total_amount' => '-40.00', 'status' => FolioCharge::STATUS_POSTED]);
        $s = SettlementSummary::of($this->folio(null, extraCharges: [$credit]));

        $this->assertSame('40.00', $s['loyalty_discount']);
        $this->assertSame('827.75', $s['total']);
    }
}

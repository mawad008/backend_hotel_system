<?php

namespace Tests\Unit\Checkout;

use App\Domain\Checkout\Models\Invoice;
use App\Domain\Checkout\Models\InvoiceItem;
use App\Domain\Payment\Gateway\SimulationDirective;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\StayServices\Models\FolioCharge;
use Illuminate\Database\UniqueConstraintViolationException;

class InvoiceServiceTest extends CheckoutTestCase
{
    public function test_invoice_is_issued_only_after_a_completed_checkout(): void
    {
        $reservation = $this->inStayReservation();
        $this->postCharge($reservation, '80.00');

        // A failed settlement -> no invoice at all.
        $this->checkoutService()->checkout($reservation, directive: SimulationDirective::Failure);
        $this->assertDatabaseCount('invoices', 0);

        // A successful retry -> an issued invoice.
        $result = $this->checkoutService()->checkout($reservation->fresh(), directive: SimulationDirective::Success);
        $this->assertSame(Invoice::STATUS_ISSUED, $result->invoice->status);
        $this->assertNotNull($result->invoice->issued_at);
    }

    public function test_invoice_items_snapshot_posted_folio_charges_only(): void
    {
        $reservation = $this->inStayReservation();
        $this->postCharge($reservation, '10.00', 2);   // 20.00
        $this->postCharge($reservation, '5.50', 1);    // 5.50
        FolioCharge::factory()->cancelled()->create([  // excluded
            'reservation_id' => $reservation->id, 'hotel_id' => $reservation->hotel_id,
            'source_type' => FolioCharge::SOURCE_SERVICE_ORDER, 'source_id' => 777,
            'unit_amount' => '99.00', 'total_amount' => '99.00', 'quantity' => 1,
        ]);

        $result = $this->checkoutService()->checkout($reservation, directive: SimulationDirective::Success);

        $items = $result->invoice->items;
        $this->assertCount(2, $items);
        $this->assertSame('25.50', $result->invoice->subtotal);
        $this->assertEqualsCanonicalizing(
            [InvoiceItem::SOURCE_FOLIO_CHARGE, InvoiceItem::SOURCE_FOLIO_CHARGE],
            $items->pluck('source_type')->all(),
        );
        $sum = $items->reduce(fn ($c, $i) => bcadd($c, (string) $i->total_amount, 2), '0.00');
        $this->assertSame('25.50', $sum);
    }

    public function test_unique_invoice_and_invoice_number_per_reservation(): void
    {
        $reservation = $this->inStayReservation();
        $this->postCharge($reservation, '15.00');

        $result = $this->checkoutService()->checkout($reservation, directive: SimulationDirective::Success);

        $this->assertMatchesRegularExpression('/^INV-\d{6,}$/', $result->invoice->invoice_number);

        $this->expectException(UniqueConstraintViolationException::class);
        Invoice::factory()->create(['reservation_id' => $reservation->id]);
    }

    public function test_invoice_number_is_globally_unique(): void
    {
        $a = Invoice::factory()->issued()->create();

        $this->expectException(UniqueConstraintViolationException::class);
        Invoice::factory()->create(['invoice_number' => $a->invoice_number]);
    }

    public function test_repeated_checkout_never_duplicates_items(): void
    {
        $reservation = $this->inStayReservation();
        $this->postCharge($reservation, '12.00');

        $this->checkoutService()->checkout($reservation, directive: SimulationDirective::Success);
        $this->checkoutService()->checkout($reservation->fresh(), directive: SimulationDirective::Success);

        $this->assertSame(1, InvoiceItem::count());
    }

    public function test_zero_charge_checkout_produces_an_empty_but_issued_invoice(): void
    {
        $reservation = $this->inStayReservation();

        $result = $this->checkoutService()->checkout($reservation);

        $this->assertSame(Invoice::STATUS_ISSUED, $result->invoice->status);
        $this->assertCount(0, $result->invoice->items);
        $this->assertSame('0.00', $result->invoice->subtotal);
        $this->assertSame(Reservation::STATUS_INVOICED, $reservation->fresh()->status);
    }
}

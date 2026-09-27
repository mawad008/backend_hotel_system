<?php

namespace Tests\Unit\Checkout;

use App\Domain\Checkout\Models\Checkout;
use App\Domain\Checkout\Models\Invoice;
use App\Domain\Checkout\Models\InvoiceItem;
use App\Domain\Reservation\Models\Reservation;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CheckoutSchemaTest extends TestCase
{
    public function test_tables_exist(): void
    {
        foreach (['checkouts', 'invoices', 'invoice_items'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "missing {$table}");
        }
    }

    public function test_one_checkout_per_reservation(): void
    {
        $checkout = Checkout::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);
        Checkout::factory()->create(['reservation_id' => $checkout->reservation_id]);
    }

    public function test_one_invoice_per_reservation(): void
    {
        $invoice = Invoice::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);
        Invoice::factory()->create(['reservation_id' => $invoice->reservation_id]);
    }

    public function test_one_invoice_item_per_source(): void
    {
        $invoice = Invoice::factory()->create();
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'source_id' => 42]);

        $this->expectException(UniqueConstraintViolationException::class);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'source_id' => 42]);
    }

    public function test_money_columns_keep_two_decimals(): void
    {
        $checkout = Checkout::factory()->create([
            'charges_total' => '123.45', 'payments_total' => '100.00', 'outstanding_total' => '23.45',
        ]);
        $this->assertSame('123.45', $checkout->fresh()->charges_total);
        $this->assertSame('23.45', $checkout->fresh()->outstanding_total);

        $invoice = Invoice::factory()->create(['subtotal' => '0.05', 'outstanding_total' => '-70.00']);
        $this->assertSame('0.05', $invoice->fresh()->subtotal);
        $this->assertSame('-70.00', $invoice->fresh()->outstanding_total);
    }

    public function test_deleting_a_reservation_with_a_checkout_or_invoice_is_blocked(): void
    {
        $checkout = Checkout::factory()->create();
        $this->expectException(QueryException::class);
        Reservation::query()->whereKey($checkout->reservation_id)->delete();
    }

    public function test_deleting_a_reservation_with_an_invoice_is_blocked(): void
    {
        $invoice = Invoice::factory()->create();
        $this->expectException(QueryException::class);
        Reservation::query()->whereKey($invoice->reservation_id)->delete();
    }
}

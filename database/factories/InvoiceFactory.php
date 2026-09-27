<?php

namespace Database\Factories;

use App\Domain\Checkout\Models\Invoice;
use App\Domain\Reservation\Models\Reservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        return [
            'reservation_id' => Reservation::factory(),
            'hotel_id' => fn (array $attributes) => Reservation::findOrFail($attributes['reservation_id'])->hotel_id,
            'invoice_number' => null,
            'status' => Invoice::STATUS_DRAFT,
            'currency' => 'USD',
            'subtotal' => '0.00',
            'payments_total' => '0.00',
            'outstanding_total' => '0.00',
            'issued_at' => null,
            'created_by_user_id' => null,
        ];
    }

    public function issued(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Invoice::STATUS_ISSUED,
            'issued_at' => now(),
        ])->afterCreating(function (Invoice $invoice) {
            if ($invoice->invoice_number === null) {
                $invoice->forceFill(['invoice_number' => sprintf('INV-%06d', $invoice->id)])->save();
            }
        });
    }

    public function totals(string $subtotal, string $paymentsTotal): static
    {
        return $this->state(fn () => [
            'subtotal' => $subtotal,
            'payments_total' => $paymentsTotal,
            'outstanding_total' => bcsub($subtotal, $paymentsTotal, 2),
        ]);
    }
}

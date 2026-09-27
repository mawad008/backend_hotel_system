<?php

namespace Database\Factories;

use App\Domain\Checkout\Models\Invoice;
use App\Domain\Checkout\Models\InvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceItem>
 */
class InvoiceItemFactory extends Factory
{
    protected $model = InvoiceItem::class;

    public function definition(): array
    {
        $quantity = 2;
        $unit = fake()->randomFloat(2, 5, 200);

        return [
            'invoice_id' => Invoice::factory(),
            'source_type' => InvoiceItem::SOURCE_FOLIO_CHARGE,
            'source_id' => fn () => fake()->unique()->numberBetween(1, 1_000_000),
            'description' => fake()->words(2, true).' x'.$quantity,
            'quantity' => $quantity,
            'unit_amount' => $unit,
            'total_amount' => bcmul((string) $unit, (string) $quantity, 2),
        ];
    }
}

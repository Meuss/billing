<?php

namespace Database\Factories;

use App\Enums\InvoiceLineType;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Item lines; Task and Expense lines come from InvoiceService so their sources get billed.
 *
 * @extends Factory<InvoiceLine>
 */
class InvoiceLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'type' => InvoiceLineType::Item,
            'description' => fake()->sentence(3),
            'quantity' => '1.00',
            'unit_price_cents' => fake()->numberBetween(1000, 50000),
            'position' => 1,
        ];
    }
}

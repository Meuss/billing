<?php

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Enums\Language;
use App\Models\Client;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Drafts only; issue invoices through InvoiceService so numbering and freezing apply.
 *
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'status' => InvoiceStatus::Draft,
            'language' => Language::Fr,
        ];
    }
}

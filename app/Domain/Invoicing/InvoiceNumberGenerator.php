<?php

namespace App\Domain\Invoicing;

use App\Models\Invoice;
use App\Settings\BillingSettings;

/** Invoice Numbers "YYYY-NNN", restarting each year. Call inside the transaction that assigns the number. */
class InvoiceNumberGenerator
{
    public function __construct(private BillingSettings $settings) {}

    public function next(int $year): string
    {
        // Locks the year's numbered invoices so two concurrent sends cannot draw the same number.
        $last = Invoice::query()
            ->where('number', 'like', "{$year}-%")
            ->lockForUpdate()
            ->pluck('number')
            ->map(fn (string $number): int => (int) substr($number, strlen("{$year}-")))
            ->max();

        // The seed only starts a year that has no numbered invoice yet, so editing it later cannot cause a clash.
        $sequence = match (true) {
            $last !== null => $last + 1,
            $year === $this->settings->number_seed_year => $this->settings->number_seed_next,
            default => 1,
        };

        return sprintf('%d-%03d', $year, $sequence);
    }
}

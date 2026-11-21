<?php

namespace App\Domain\Invoicing;

use App\Enums\DiscountType;
use App\Models\Invoice;
use App\Models\InvoiceLine;

final class InvoiceTotals
{
    public static function for(Invoice $invoice): Totals
    {
        $subtotal = $invoice->lines->sum(fn (InvoiceLine $line): int => $line->amountCents());

        return self::compute($subtotal, $invoice->discount_type, $invoice->discount_value);
    }

    /** Subtotal − discount, then rounded to the nearest 5 centimes. The total may be negative; sending refuses it. */
    public static function compute(int $subtotalCents, ?DiscountType $discountType, ?int $discountValue): Totals
    {
        $discount = match ($discountType) {
            DiscountType::Percent => Money::percentOf($subtotalCents, (int) $discountValue),
            DiscountType::Amount => (int) $discountValue,
            null => 0,
        };
        $net = $subtotalCents - $discount;
        $total = Money::toNearestFiveCents($net);

        return new Totals($subtotalCents, $discount, $total - $net, $total);
    }
}

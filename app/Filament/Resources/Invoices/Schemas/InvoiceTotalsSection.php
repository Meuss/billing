<?php

namespace App\Filament\Resources\Invoices\Schemas;

use App\Domain\Invoicing\InvoiceTotals;
use App\Domain\Invoicing\Totals;
use App\Enums\DiscountType;
use App\Models\Invoice;
use App\Support\Chf;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

/** Subtotal, discount, rounding and total, always from freshly queried lines. */
class InvoiceTotalsSection
{
    /** $live: take the discount from the (unsaved) edit form state instead of the stored one. */
    public static function make(bool $live = false): Section
    {
        $totals = fn (Invoice $record, Get $get): Totals => static::totals($record, $live ? InvoiceForm::discountFromForm([
            'discount_type' => $get('discount_type'),
            'discount_value' => $get('discount_value'),
        ]) : null);

        return Section::make('Totals')->columns(4)->schema([
            TextEntry::make('totals_subtotal')->label('Subtotal')
                ->state(fn (Invoice $record, Get $get): string => Chf::format($totals($record, $get)->subtotalCents)),
            TextEntry::make('totals_discount')->label('Discount')
                ->state(fn (Invoice $record, Get $get): string => Chf::format(-$totals($record, $get)->discountCents)),
            TextEntry::make('totals_rounding')->label('Rounding')
                ->state(fn (Invoice $record, Get $get): string => Chf::format($totals($record, $get)->roundingCents)),
            TextEntry::make('totals_total')->label('Total')->weight('bold')
                ->state(fn (Invoice $record, Get $get): string => Chf::format($totals($record, $get)->totalCents)),
        ]);
    }

    /** @param array{0: ?DiscountType, 1: ?int}|null $discount */
    public static function totals(Invoice $record, ?array $discount = null): Totals
    {
        // Not the record's loaded relations: a Task's hours may have changed since (see Task::totalMinutes()).
        $fresh = Invoice::query()->with('lines.task.timeEntries')->findOrFail($record->getKey());
        [$type, $value] = $discount ?? [$fresh->discount_type, $fresh->discount_value];

        return InvoiceTotals::compute(InvoiceTotals::for($fresh)->subtotalCents, $type, $value);
    }
}

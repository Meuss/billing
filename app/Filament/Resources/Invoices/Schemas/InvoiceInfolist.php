<?php

namespace App\Filament\Resources\Invoices\Schemas;

use App\Enums\DiscountType;
use App\Filament\Resources\Invoices\InvoiceStatusBadge;
use App\Models\Invoice;
use App\Support\Chf;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/** Read-only view; an issued Invoice shows its frozen values (R10). */
class InvoiceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Invoice')->columns(4)->schema([
                TextEntry::make('number')->placeholder('Draft'),
                TextEntry::make('client.name')->label('Client'),
                TextEntry::make('status')->badge()
                    ->state(fn (Invoice $record): string => $record->displayStatus())
                    ->formatStateUsing(fn (string $state): string => InvoiceStatusBadge::label($state))
                    ->color(fn (string $state): string => InvoiceStatusBadge::color($state)),
                TextEntry::make('language')->label('PDF language')->formatStateUsing(fn ($state): string => $state->label()),
                TextEntry::make('sent_on')->label('Sent')->date('d.m.Y')->placeholder('-'),
                TextEntry::make('due_on')->label('Due')->date('d.m.Y')->placeholder('-'),
                TextEntry::make('paid_on')->label('Paid')->date('d.m.Y')->placeholder('-'),
                TextEntry::make('cancelled_on')->label('Cancelled')->date('d.m.Y')->placeholder('-'),
                TextEntry::make('discount')->label('Discount')->placeholder('None')
                    ->state(fn (Invoice $record): ?string => match ($record->discount_type) {
                        DiscountType::Percent => Chf::fromCents($record->discount_value).' %',
                        DiscountType::Amount => Chf::format($record->discount_value),
                        null => null,
                    }),
                TextEntry::make('note')->placeholder('-')->columnSpan(3),
            ]),
            InvoiceTotalsSection::make(),
        ]);
    }
}

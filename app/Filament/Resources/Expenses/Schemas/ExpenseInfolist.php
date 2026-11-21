<?php

namespace App\Filament\Resources\Expenses\Schemas;

use App\Enums\ExpenseStatus;
use App\Support\Chf;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ExpenseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextEntry::make('client.name')->label('Client'),
            TextEntry::make('project.name')->label('Project')->placeholder('-'),
            TextEntry::make('date')->date(),
            TextEntry::make('status')->badge()
                ->formatStateUsing(fn (ExpenseStatus $state): string => $state->label())
                ->color(fn (ExpenseStatus $state): string => $state->color()),
            TextEntry::make('supplier'),
            TextEntry::make('description'),
            TextEntry::make('cost_cents')->label('Cost')
                ->state(fn ($record): string => 'CHF '.Chf::fromCents($record->cost_cents)),
            TextEntry::make('billed_amount_cents')->label('Billed amount')
                ->state(fn ($record): string => 'CHF '.Chf::fromCents($record->billed_amount_cents)),
            TextEntry::make('original_amount_note')->label('Original amount')->placeholder('-'),
            TextEntry::make('receipt_path')->label('Receipt')
                ->state(fn ($record): string => $record->receipt_path ? 'Download receipt' : '-')
                ->url(fn ($record): ?string => $record->receipt_path ? route('expenses.receipt', $record) : null, shouldOpenInNewTab: true),
        ]);
    }
}

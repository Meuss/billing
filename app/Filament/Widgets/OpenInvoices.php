<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Invoices\InvoiceStatusBadge;
use App\Models\Invoice;
use App\Support\Chf;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class OpenInvoices extends TableWidget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Open invoices';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Invoice::query()->open()->with('client')->orderBy('due_on')->orderBy('id'))
            ->paginated(false)
            ->columns([
                TextColumn::make('number')
                    ->url(fn (Invoice $record): string => InvoiceResource::getUrl('view', ['record' => $record])),
                TextColumn::make('client.name')->label('Client'),
                TextColumn::make('status')->badge()
                    ->state(fn (Invoice $record): string => $record->displayStatus())
                    ->formatStateUsing(fn (string $state): string => InvoiceStatusBadge::label($state))
                    ->color(fn (string $state): string => InvoiceStatusBadge::color($state)),
                TextColumn::make('total_cents')->label('Total')->alignEnd()
                    ->formatStateUsing(fn (int $state): string => Chf::format($state)),
                TextColumn::make('sent_on')->label('Sent')->date('d.m.Y'),
                TextColumn::make('due_on')->label('Due')->date('d.m.Y'),
            ])
            ->emptyStateHeading('No open invoices');
    }
}

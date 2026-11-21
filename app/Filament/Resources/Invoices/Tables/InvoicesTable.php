<?php

namespace App\Filament\Resources\Invoices\Tables;

use App\Enums\InvoiceStatus;
use App\Filament\Resources\Invoices\InvoiceStatusBadge;
use App\Models\Invoice;
use App\Support\Chf;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['client', 'lines.task.timeEntries']))
            // Drafts first, then the most recently sent.
            ->defaultSort(fn (Builder $query) => $query->orderByRaw('sent_on is null desc')->orderByDesc('sent_on')->orderByDesc('id'))
            ->columns([
                TextColumn::make('number')->placeholder('Draft')->searchable()->sortable(),
                TextColumn::make('client.name')->label('Client')->searchable()->sortable(),
                TextColumn::make('status')->badge()
                    ->state(fn (Invoice $record): string => $record->displayStatus())
                    ->formatStateUsing(fn (string $state): string => InvoiceStatusBadge::label($state))
                    ->color(fn (string $state): string => InvoiceStatusBadge::color($state)),
                TextColumn::make('total')->alignEnd()
                    // A draft's total follows its lines; an issued one is frozen.
                    ->state(fn (Invoice $record): int => $record->isDraft() ? $record->totals()->totalCents : (int) $record->total_cents)
                    ->formatStateUsing(fn (int $state): string => Chf::format($state)),
                TextColumn::make('sent_on')->label('Sent')->date('d.m.Y')->placeholder('-')->sortable(),
                TextColumn::make('due_on')->label('Due')->date('d.m.Y')->placeholder('-')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(InvoiceStatusBadge::options())
                    // Matches the badges: "sent" leaves out the overdue ones.
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        null, '' => $query,
                        InvoiceStatusBadge::OVERDUE => $query->overdue(),
                        InvoiceStatus::Sent->value => $query->open()->whereDate('due_on', '>=', today()),
                        default => $query->where('status', $data['value']),
                    }),
                SelectFilter::make('client')->relationship('client', 'name')->searchable()->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()->hidden(fn (Invoice $record): bool => ! $record->isDraft()),
            ]);
    }
}

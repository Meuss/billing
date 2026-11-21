<?php

namespace App\Filament\Resources\Expenses\Tables;

use App\Enums\ExpenseStatus;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Expense;
use App\Support\Chf;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ExpensesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->defaultSort('date', 'desc')
            ->columns([
                TextColumn::make('date')->date()->sortable(),
                TextColumn::make('client.name')->label('Client')->searchable()->sortable(),
                TextColumn::make('project.name')->label('Project')->placeholder('-')->searchable(),
                TextColumn::make('supplier')->searchable()->sortable(),
                TextColumn::make('description')->searchable()->limit(40),
                TextColumn::make('cost_cents')->label('Cost')->sortable()
                    ->formatStateUsing(fn ($state): string => 'CHF '.Chf::fromCents((int) $state)),
                TextColumn::make('billed_amount_cents')->label('Billed amount')->sortable()
                    ->formatStateUsing(fn ($state): string => 'CHF '.Chf::fromCents((int) $state)),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (ExpenseStatus $state): string => $state->label())
                    ->color(fn (ExpenseStatus $state): string => $state->color()),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(ExpenseStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()),
                SelectFilter::make('client')->relationship('client', 'name'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()->hidden(fn (Expense $record): bool => $record->isBilled()),
                static::duplicateAction(),
                DeleteAction::make()->hidden(fn (Expense $record): bool => ! $record->canBeDeleted()),
            ]);
    }

    public static function duplicateAction(): Action
    {
        return Action::make('duplicate')
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->color('gray')
            ->action(function (Expense $record) {
                $copy = $record->duplicate();

                return redirect(ExpenseResource::getUrl('edit', ['record' => $copy]));
            });
    }
}

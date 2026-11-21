<?php

namespace App\Filament\Resources\Projects\Tables;

use App\Filament\Support\ArchiveActions;
use App\Filament\Support\GuardedDeleteAction;
use App\Support\Chf;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProjectsTable
{
    public static function configure(Table $table, bool $withClient = true): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                ...($withClient ? [TextColumn::make('client.name')->label('Client')->searchable()->sortable()] : []),
                TextColumn::make('rate_cents')
                    ->label('Rate')
                    ->state(fn ($record): string => 'CHF '.Chf::fromCents($record->effectiveRateCents()).($record->rate_cents === null ? ' (default)' : '')),
            ])
            ->filters([ArchiveActions::filter()])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ...ArchiveActions::make(),
                GuardedDeleteAction::make('its tasks and time entries'),
            ]);
    }
}

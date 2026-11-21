<?php

namespace App\Filament\Resources\Clients\Tables;

use App\Filament\Support\ArchiveActions;
use App\Filament\Support\GuardedDeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ClientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('contact_person')->searchable()->toggleable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('city')->sortable(),
                TextColumn::make('language')->formatStateUsing(fn ($state) => $state->label())->badge(),
                TextColumn::make('projects_count')->counts('projects')->label('Projects'),
            ])
            ->filters([ArchiveActions::filter()])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ...ArchiveActions::make(),
                GuardedDeleteAction::make('its projects, tasks, time entries and expenses'),
                GuardedDeleteAction::make('its projects, tasks, time entries and expenses'),
            ]);
    }
}

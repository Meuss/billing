<?php

namespace App\Filament\Resources\Tasks\Tables;

use App\Enums\TaskStatus;
use App\Support\Hours;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TasksTable
{
    public static function configure(Table $table, bool $withProject = true): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->withSum('timeEntries as total_minutes', 'duration_minutes'))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                ...($withProject ? [
                    TextColumn::make('project.name')->label('Project')->searchable()->sortable(),
                    TextColumn::make('project.client.name')->label('Client')->searchable(),
                ] : []),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (TaskStatus $state): string => $state->label())
                    ->color(fn (TaskStatus $state): string => $state->color()),
                TextColumn::make('total_minutes')->label('Hours')->sortable()
                    ->state(fn ($record): string => Hours::format((int) $record->total_minutes)),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(TaskStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}

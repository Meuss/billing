<?php

namespace App\Filament\Resources\Tasks\Schemas;

use App\Enums\TaskStatus;
use App\Models\Task;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TaskForm
{
    public static function configure(Schema $schema, bool $withProject = true): Schema
    {
        return $schema->components([
            ...($withProject ? [static::projectSelect()] : []),
            TextInput::make('name')->required()->maxLength(255),
            Select::make('status')
                ->options(TaskStatus::manualOptions())
                ->default(TaskStatus::Todo->value)
                ->required()
                // A billed Task keeps its status; invoicing alone moves it.
                ->disabled(fn (?Model $record): bool => $record instanceof Task && $record->status === TaskStatus::Billed)
                ->dehydrated(fn (?Model $record): bool => ! ($record instanceof Task && $record->status === TaskStatus::Billed)),
            Textarea::make('description')->columnSpanFull(),
        ])->columns(2);
    }

    protected static function projectSelect(): Select
    {
        return Select::make('project_id')
            ->label('Project')
            ->relationship(
                'project',
                'name',
                // Archived Projects and Projects of archived Clients are not offered, except the current one.
                modifyQueryUsing: fn (Builder $query, ?Model $record): Builder => $query->where(
                    fn (Builder $q) => $q->selectable()
                        ->when($record instanceof Task, fn (Builder $q) => $q->orWhere('projects.id', $record->project_id))
                ),
            )
            ->required()
            // A billed Task stays in its Project.
            ->disabled(fn (?Model $record): bool => $record instanceof Task && $record->status === TaskStatus::Billed)
            ->dehydrated(fn (?Model $record): bool => ! ($record instanceof Task && $record->status === TaskStatus::Billed))
            ->searchable()
            ->preload();
    }
}

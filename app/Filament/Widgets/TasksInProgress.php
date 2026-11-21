<?php

namespace App\Filament\Widgets;

use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use App\Support\Hours;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class TasksInProgress extends TableWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Tasks in progress';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Task::query()
                ->where('status', TaskStatus::InProgress)
                ->with('project.client')
                ->withSum('timeEntries as total_minutes', 'duration_minutes')
                ->latest('updated_at'))
            ->paginated(false)
            ->columns([
                TextColumn::make('name')
                    ->url(fn (Task $record): string => TaskResource::getUrl('view', ['record' => $record])),
                TextColumn::make('project.name')->label('Project'),
                TextColumn::make('project.client.name')->label('Client'),
                TextColumn::make('total_minutes')->label('Hours')->alignEnd()
                    ->formatStateUsing(fn ($state): string => Hours::format((int) $state)),
            ])
            ->emptyStateHeading('No task in progress');
    }
}

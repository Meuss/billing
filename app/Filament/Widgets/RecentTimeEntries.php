<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Tasks\TaskResource;
use App\Models\TimeEntry;
use App\Support\Hours;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentTimeEntries extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Recent time entries';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => TimeEntry::query()->with('task.project.client')->latest('started_at')->latest('id')->limit(10))
            ->paginated(false)
            ->columns([
                TextColumn::make('started_at')->label('Date')->dateTime('d.m.Y H:i'),
                TextColumn::make('task.name')->label('Task')
                    ->url(fn (TimeEntry $record): string => TaskResource::getUrl('view', ['record' => $record->task_id])),
                TextColumn::make('task.project.client.name')->label('Client'),
                TextColumn::make('duration_minutes')->label('Duration')->alignEnd()
                    ->formatStateUsing(fn (int $state): string => Hours::format($state)),
                TextColumn::make('description')->limit(60)->placeholder('-'),
            ])
            ->emptyStateHeading('No time entries yet');
    }
}

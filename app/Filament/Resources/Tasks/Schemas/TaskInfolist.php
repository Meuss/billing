<?php

namespace App\Filament\Resources\Tasks\Schemas;

use App\Enums\TaskStatus;
use App\Support\Hours;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class TaskInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextEntry::make('project.client.name')->label('Client'),
            TextEntry::make('project.name')->label('Project'),
            TextEntry::make('name'),
            TextEntry::make('status')->badge()
                ->formatStateUsing(fn (TaskStatus $state): string => $state->label())
                ->color(fn (TaskStatus $state): string => $state->color()),
            TextEntry::make('total_hours')->label('Total hours')
                ->state(fn ($record): string => Hours::format($record->totalMinutes())),
            TextEntry::make('description')->placeholder('-')->columnSpanFull(),
        ]);
    }
}

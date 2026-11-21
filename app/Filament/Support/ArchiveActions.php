<?php

namespace App\Filament\Support;

use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ArchiveActions
{
    /** @return array<int, Action> */
    public static function make(): array
    {
        return [
            Action::make('archive')
                ->icon(Heroicon::OutlinedArchiveBox)
                ->color('gray')
                ->requiresConfirmation()
                ->visible(fn (Model $record): bool => ! $record->isArchived())
                ->action(fn (Model $record) => $record->archive()),
            Action::make('unarchive')
                ->icon(Heroicon::OutlinedArchiveBoxXMark)
                ->color('gray')
                ->visible(fn (Model $record): bool => $record->isArchived())
                ->action(fn (Model $record) => $record->unarchive()),
        ];
    }

    /** Active records by default; switch to archived only, or all. */
    public static function filter(): TernaryFilter
    {
        return TernaryFilter::make('archived')
            ->label('Show')
            ->placeholder('All')
            ->trueLabel('Archived only')
            ->falseLabel('Active only')
            ->default(false)
            ->queries(
                true: fn (Builder $query): Builder => $query->archived(),
                false: fn (Builder $query): Builder => $query->active(),
                blank: fn (Builder $query): Builder => $query,
            );
    }
}

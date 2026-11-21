<?php

namespace App\Filament\Support;

use Filament\Actions\DeleteAction;
use Illuminate\Database\Eloquent\Model;

/** Delete, offered only while the record's canBeDeleted() allows it (nothing invoiced references it). */
class GuardedDeleteAction
{
    /** @param  string  $cascades  what goes with the record, e.g. "its tasks and time entries" */
    public static function make(string $cascades): DeleteAction
    {
        return DeleteAction::make()
            ->modalDescription("This also deletes {$cascades}. This cannot be undone.")
            ->visible(fn (Model $record): bool => $record->canBeDeleted());
    }
}

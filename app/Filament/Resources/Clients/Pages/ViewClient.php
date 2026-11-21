<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Support\ArchiveActions;
use App\Filament\Support\GuardedDeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewClient extends ViewRecord
{
    protected static string $resource = ClientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            ...ArchiveActions::make(),
            GuardedDeleteAction::make('its projects, tasks, time entries and expenses'),
        ];
    }
}

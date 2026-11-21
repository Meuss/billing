<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Support\ArchiveActions;
use App\Filament\Support\GuardedDeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditClient extends EditRecord
{
    protected static string $resource = ClientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            ...ArchiveActions::make(),
            GuardedDeleteAction::make('its projects, tasks, time entries and expenses'),
        ];
    }
}

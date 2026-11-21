<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Support\Chf;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ProjectInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextEntry::make('client.name')->label('Client'),
            TextEntry::make('name'),
            TextEntry::make('rate_cents')
                ->label('Hourly rate')
                ->state(fn ($record): string => 'CHF '.Chf::fromCents($record->effectiveRateCents()).($record->rate_cents === null ? ' (default)' : '')),
            TextEntry::make('archived_at')->label('Archived')->dateTime()->visible(fn ($record) => $record->isArchived()),
            TextEntry::make('notes')->placeholder('-')->columnSpanFull(),
        ]);
    }
}

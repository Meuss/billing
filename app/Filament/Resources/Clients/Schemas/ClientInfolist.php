<?php

namespace App\Filament\Resources\Clients\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ClientInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Client')->columns(2)->schema([
                TextEntry::make('name'),
                TextEntry::make('contact_person')->placeholder('-'),
                TextEntry::make('email'),
                TextEntry::make('phone')->placeholder('-'),
                TextEntry::make('website')->placeholder('-'),
                TextEntry::make('language')->formatStateUsing(fn ($state) => $state->label()),
                TextEntry::make('archived_at')->label('Archived')->dateTime()->visible(fn ($record) => $record->isArchived()),
            ]),
            Section::make('Address')->columns(2)->schema([
                TextEntry::make('address_line1')->label('Address'),
                TextEntry::make('address_line2')->label('Address line 2')->placeholder('-'),
                TextEntry::make('postal_code')->label('Postal code'),
                TextEntry::make('city'),
                TextEntry::make('country'),
            ]),
            Section::make('Notes')->schema([
                TextEntry::make('notes')->hiddenLabel()->placeholder('-'),
            ]),
        ]);
    }
}

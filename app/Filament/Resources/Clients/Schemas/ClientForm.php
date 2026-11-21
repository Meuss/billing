<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\Enums\Language;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ClientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Client')->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('contact_person')->maxLength(255),
                TextInput::make('email')->email()->required()->maxLength(255),
                TextInput::make('phone')->tel()->maxLength(50),
                TextInput::make('website')->maxLength(255),
                Select::make('language')
                    ->label('Invoice language')
                    ->options(collect(Language::cases())->mapWithKeys(fn (Language $l) => [$l->value => $l->label()]))
                    ->default(Language::Fr->value)
                    ->required()
                    ->native(false),
            ]),
            Section::make('Address')->columns(2)->schema([
                TextInput::make('address_line1')->label('Address')->required()->maxLength(255),
                TextInput::make('address_line2')->label('Address line 2')->maxLength(255),
                TextInput::make('postal_code')->label('Postal code')->required()->maxLength(20),
                TextInput::make('city')->required()->maxLength(255),
                TextInput::make('country')
                    ->label('Country (ISO code)')
                    ->default('CH')
                    ->required()
                    ->length(2)
                    ->alpha()
                    ->dehydrateStateUsing(fn (?string $state): string => strtoupper((string) $state)),
            ]),
            Section::make('Notes')->schema([
                Textarea::make('notes')->hiddenLabel()->columnSpanFull(),
            ]),
        ]);
    }
}

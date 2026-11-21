<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Models\Project;
use App\Settings\BillingSettings;
use App\Support\Chf;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ProjectForm
{
    public static function configure(Schema $schema, bool $withClient = true): Schema
    {
        return $schema->components([
            ...($withClient ? [static::clientSelect()] : []),
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('rate_cents')
                ->label('Hourly rate')
                ->prefix('CHF')
                ->placeholder('Default')
                ->helperText(fn (): string => 'Leave blank for Default (CHF '.Chf::fromCents(app(BillingSettings::class)->default_rate_cents).')')
                ->rule('regex:/^\d{1,7}(\.\d{1,2})?$/')
                ->validationMessages(['regex' => 'Enter an amount in CHF with at most two decimals, e.g. 135.00.'])
                ->formatStateUsing(fn ($state): ?string => $state === null || $state === '' ? null : Chf::fromCents((int) $state))
                ->dehydrateStateUsing(fn ($state): ?int => blank($state) ? null : Chf::toCents((string) $state)),
            Textarea::make('notes')->columnSpanFull(),
        ])->columns(2);
    }

    protected static function clientSelect(): Select
    {
        return Select::make('client_id')
            ->label('Client')
            ->relationship(
                'client',
                'name',
                // Archived Clients are not offered, except the one a record already belongs to.
                modifyQueryUsing: fn (Builder $query, ?Model $record): Builder => $query->where(
                    fn (Builder $q) => $q->whereNull('archived_at')
                        ->when($record instanceof Project, fn (Builder $q) => $q->orWhere('id', $record->client_id))
                ),
            )
            ->required()
            ->searchable()
            ->preload();
    }
}

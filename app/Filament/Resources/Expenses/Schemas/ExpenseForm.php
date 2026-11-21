<?php

namespace App\Filament\Resources\Expenses\Schemas;

use App\Models\Client;
use App\Models\Expense;
use App\Models\Project;
use App\Support\Chf;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ExpenseForm
{
    private const CHF_REGEX = 'regex:/^\d{1,7}(\.\d{1,2})?$/';

    private const CHF_MESSAGE = 'Enter an amount in CHF with at most two decimals, e.g. 99.50.';

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            static::clientSelect(),
            static::projectSelect(),
            DatePicker::make('date')->required()->default(today()),
            TextInput::make('supplier')->required()->maxLength(255),
            TextInput::make('description')->required()->maxLength(255)->columnSpanFull(),
            TextInput::make('cost_cents')
                ->label('Cost')
                ->prefix('CHF')
                ->required()
                ->rule(self::CHF_REGEX)
                ->rule(fn (): \Closure => fn (string $attribute, $value, \Closure $fail) => Chf::toCents((string) $value) > 0 ?: $fail('The cost must be greater than zero.'))
                ->validationMessages(['regex' => self::CHF_MESSAGE])
                ->formatStateUsing(fn ($state): ?string => blank($state) ? null : Chf::fromCents((int) $state))
                ->dehydrateStateUsing(fn ($state): int => Chf::toCents((string) $state)),
            TextInput::make('billed_amount_cents')
                ->label('Billed amount')
                ->prefix('CHF')
                ->placeholder('Same as cost')
                ->helperText('Leave blank to re-bill at cost.')
                ->rule(self::CHF_REGEX)
                ->validationMessages(['regex' => self::CHF_MESSAGE])
                ->formatStateUsing(fn ($state): ?string => blank($state) ? null : Chf::fromCents((int) $state))
                // Blank is resolved to the cost by the model.
                ->dehydrateStateUsing(fn ($state): ?int => blank($state) ? null : Chf::toCents((string) $state)),
            TextInput::make('original_amount_note')
                ->label('Original amount')
                ->placeholder('e.g. EUR 45.00')
                ->maxLength(255),
            FileUpload::make('receipt_path')
                ->label('Receipt')
                ->disk(Expense::RECEIPT_DISK)
                ->directory(Expense::RECEIPT_DIRECTORY)
                ->visibility('private')
                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/heic', 'image/heif'])
                ->maxSize(10240)
                ->downloadable()
                ->columnSpanFull(),
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
                    fn (Builder $q) => $q->active()
                        ->when($record instanceof Expense, fn (Builder $q) => $q->orWhere('clients.id', $record->client_id))
                ),
            )
            ->required()
            ->searchable()
            ->preload()
            ->live()
            ->afterStateUpdated(fn (Set $set) => $set('project_id', null));
    }

    protected static function projectSelect(): Select
    {
        return Select::make('project_id')
            ->label('Project')
            ->relationship(
                'project',
                'name',
                // Only the selected Client's Projects; archived ones are not offered, except the current one.
                modifyQueryUsing: fn (Builder $query, Get $get, ?Model $record): Builder => $query
                    ->where('projects.client_id', $get('client_id'))
                    ->where(fn (Builder $q) => $q->selectable()
                        ->when($record instanceof Expense, fn (Builder $q) => $q->orWhere('projects.id', $record->project_id))
                    ),
            )
            ->rule(fn (Get $get): \Closure => fn (string $attribute, $value, \Closure $fail) => blank($value)
                || Project::whereKey($value)->where('client_id', $get('client_id'))->exists()
                    ?: $fail('The project must belong to the selected client.'))
            ->searchable()
            ->preload()
            ->placeholder('No project')
            ->disabled(fn (Get $get): bool => blank($get('client_id')));
    }
}

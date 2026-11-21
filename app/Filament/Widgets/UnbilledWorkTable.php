<?php

namespace App\Filament\Widgets;

use App\Domain\Reporting\UnbilledWork;
use App\Filament\Resources\Clients\ClientResource;
use App\Support\Chf;
use App\Support\Hours;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class UnbilledWorkTable extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Unbilled work';

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => UnbilledWork::perClient()
                ->mapWithKeys(fn (array $row): array => [$row['client']->id => [
                    'client_id' => $row['client']->id,
                    'client' => $row['client']->name.($row['client']->isArchived() ? ' (archived)' : ''),
                    'minutes' => $row['minutes'],
                    'task_amount_cents' => $row['task_amount_cents'],
                    'expense_amount_cents' => $row['expense_amount_cents'],
                    'total_cents' => $row['total_cents'],
                ]])
                ->all())
            ->paginated(false)
            ->columns([
                TextColumn::make('client')
                    ->url(fn (array $record): string => ClientResource::getUrl('view', ['record' => $record['client_id']])),
                TextColumn::make('minutes')->label('Hours')->alignEnd()
                    ->formatStateUsing(fn (int $state): string => Hours::format($state)),
                TextColumn::make('task_amount_cents')->label('Tasks')->alignEnd()
                    ->formatStateUsing(fn (int $state): string => Chf::format($state)),
                TextColumn::make('expense_amount_cents')->label('Expenses')->alignEnd()
                    ->formatStateUsing(fn (int $state): string => Chf::format($state)),
                TextColumn::make('total_cents')->label('Total')->alignEnd()->weight('bold')
                    ->formatStateUsing(fn (int $state): string => Chf::format($state)),
            ])
            ->emptyStateHeading('Nothing to invoice')
            ->emptyStateDescription('Done tasks and unbilled expenses show up here.');
    }
}

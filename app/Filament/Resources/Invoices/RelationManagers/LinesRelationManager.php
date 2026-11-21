<?php

namespace App\Filament\Resources\Invoices\RelationManagers;

use App\Domain\Invoicing\InvoiceService;
use App\Domain\Invoicing\Money;
use App\Enums\ExpenseStatus;
use App\Enums\InvoiceLineType;
use App\Enums\TaskStatus;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Project;
use App\Models\Task;
use App\Support\Chf;
use App\Support\Hours;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The lines of an Invoice. On a draft, work is added and removed here through InvoiceService;
 * on an issued Invoice the table is read-only and shows the frozen hours and amounts.
 */
class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Lines';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['invoice', 'project', 'expense', 'task.timeEntries']))
            ->paginated(false)
            ->columns([
                TextColumn::make('type')->badge()->color('gray')
                    ->formatStateUsing(fn (InvoiceLineType $state): string => ucfirst($state->value)),
                TextColumn::make('project.name')->label('Project')->placeholder('-'),
                TextColumn::make('description')->wrap()
                    ->description(fn (InvoiceLine $record): ?string => $record->expense?->supplier),
                TextColumn::make('quantity')->label('Qty')->alignEnd()
                    ->state(fn (InvoiceLine $record): string => match ($record->type) {
                        InvoiceLineType::Task => Hours::format($record->billableMinutes()).' h',
                        InvoiceLineType::Item => (string) $record->quantity,
                        InvoiceLineType::Expense => '-',
                    }),
                TextColumn::make('unit_price_cents')->label('Unit price')->alignEnd()
                    ->formatStateUsing(fn (int $state, InvoiceLine $record): string => Chf::format($state).($record->type === InvoiceLineType::Task ? '/h' : '')),
                TextColumn::make('amount')->alignEnd()
                    ->state(fn (InvoiceLine $record): string => Chf::format($record->amountCents())),
            ])
            ->headerActions([
                $this->addTasksAction(),
                $this->addExpensesAction(),
                $this->addItemAction(),
            ])
            ->recordActions([
                Action::make('remove')
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('danger')
                    ->visible(fn (): bool => $this->invoice()->isDraft())
                    ->requiresConfirmation()
                    ->modalDescription('Its task or expense becomes available to invoice again.')
                    ->action(fn (InvoiceLine $record) => $this->change(fn (InvoiceService $invoices) => $invoices->removeLine($record))),
            ]);
    }

    protected function addTasksAction(): Action
    {
        return Action::make('addTasks')
            ->label('Add tasks')
            ->icon(Heroicon::OutlinedClock)
            ->visible(fn (): bool => $this->invoice()->isDraft())
            ->modalDescription('Non-billed tasks of this client\'s projects, archived ones included.')
            ->schema(fn (): array => [
                CheckboxList::make('tasks')
                    ->hiddenLabel()
                    ->options(fn (): array => $this->availableTasks()->mapWithKeys(fn (Task $task) => [$task->id => $task->project->name.($task->project->isArchived() ? ' (archived)' : '').' — '.$task->name])->all())
                    ->descriptions(fn (): array => $this->availableTasks()->mapWithKeys(fn (Task $task) => [
                        $task->id => Hours::format($task->totalMinutes()).' h · '.Chf::format(Money::forMinutes($task->totalMinutes(), $task->project->effectiveRateCents())).' · '.$task->status->label(),
                    ])->all())
                    ->bulkToggleable()
                    ->required()
                    ->validationMessages(['required' => 'Select at least one task.']),
            ])
            ->action(fn (array $data) => $this->change(function (InvoiceService $invoices) use ($data): void {
                // Only eligible ids are taken, whatever was posted.
                foreach ($this->availableTasks()->only($data['tasks']) as $task) {
                    $invoices->addTask($this->invoice(), $task);
                }
            }));
    }

    protected function addExpensesAction(): Action
    {
        return Action::make('addExpenses')
            ->label('Add expenses')
            ->icon(Heroicon::OutlinedReceiptPercent)
            ->visible(fn (): bool => $this->invoice()->isDraft())
            ->modalDescription('Unbilled expenses of this client.')
            ->schema(fn (): array => [
                CheckboxList::make('expenses')
                    ->hiddenLabel()
                    ->options(fn (): array => $this->availableExpenses()->mapWithKeys(fn (Expense $expense) => [$expense->id => $expense->date->format('d.m.Y').' · '.$expense->supplier.' — '.$expense->description])->all())
                    ->descriptions(fn (): array => $this->availableExpenses()->mapWithKeys(fn (Expense $expense) => [$expense->id => Chf::format($expense->billed_amount_cents)])->all())
                    ->bulkToggleable()
                    ->required()
                    ->validationMessages(['required' => 'Select at least one expense.']),
            ])
            ->action(fn (array $data) => $this->change(function (InvoiceService $invoices) use ($data): void {
                foreach ($this->availableExpenses()->only($data['expenses']) as $expense) {
                    $invoices->addExpense($this->invoice(), $expense);
                }
            }));
    }

    protected function addItemAction(): Action
    {
        return Action::make('addItem')
            ->label('Add item')
            ->icon(Heroicon::OutlinedPlus)
            ->visible(fn (): bool => $this->invoice()->isDraft())
            ->schema([
                TextInput::make('description')->required()->maxLength(255),
                TextInput::make('quantity')
                    ->default('1')
                    ->required()
                    ->rule(fn (): \Closure => fn (string $attribute, mixed $value, \Closure $fail) => Money::isQuantity((string) $value) && Money::hundredths((string) $value) !== 0
                        ?: $fail('Enter a non-zero quantity with at most two decimals.')),
                TextInput::make('unit_price')
                    ->label('Unit price')
                    ->prefix('CHF')
                    ->helperText('May be negative, e.g. -50.00 for a credit.')
                    ->required()
                    ->regex('/^-?\d{1,7}(\.\d{1,2})?$/')
                    ->validationMessages(['regex' => 'Enter an amount in CHF with at most two decimals, e.g. -50.00.']),
            ])
            ->action(fn (array $data) => $this->change(fn (InvoiceService $invoices) => $invoices->addItem(
                $this->invoice(),
                $data['description'],
                trim($data['quantity']),
                Chf::toCents($data['unit_price']),
            )));
    }

    /** All of the Client's non-billed Tasks, including those in archived Projects (Ruling R13), keyed by id. */
    protected function availableTasks(): Collection
    {
        return Task::query()
            ->where('status', '!=', TaskStatus::Billed)
            ->whereHas('project', fn (Builder $project) => $project->where('client_id', $this->invoice()->client_id))
            ->with(['project', 'timeEntries'])
            ->orderBy(Project::select('name')->whereColumn('projects.id', 'tasks.project_id'))
            ->orderBy('id')
            ->get()
            ->keyBy('id');
    }

    /** The Client's unbilled Expenses, keyed by id. */
    protected function availableExpenses(): Collection
    {
        return Expense::query()
            ->where('client_id', $this->invoice()->client_id)
            ->where('status', ExpenseStatus::Unbilled)
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->keyBy('id');
    }

    protected function invoice(): Invoice
    {
        /** @var Invoice */
        return $this->getOwnerRecord();
    }

    /** Runs a line change; a refused one becomes a danger notification. The page re-renders its totals either way. */
    protected function change(\Closure $callback): void
    {
        try {
            $callback(app(InvoiceService::class));
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
        $this->dispatch('invoice-lines-updated');
    }
}

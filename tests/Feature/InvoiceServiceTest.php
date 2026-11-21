<?php

use App\Domain\Invoicing\InvoiceService;
use App\Enums\DiscountType;
use App\Enums\ExpenseStatus;
use App\Enums\InvoiceStatus;
use App\Enums\Language;
use App\Enums\TaskStatus;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;

// The fixed dates below assume this day; freezing it keeps them valid on any real date.
beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-11-21 12:00')));

function invoicing(): InvoiceService
{
    return app(InvoiceService::class);
}

/** A done Task for the Client with the given minutes logged. */
function doneTask(Client $client, int $minutes = 60, ?int $rateCents = null): Task
{
    $project = Project::factory()->for($client)->create(['rate_cents' => $rateCents]);
    $task = Task::factory()->for($project)->create();
    TimeEntry::fromStartAndDuration($task, CarbonImmutable::parse('2026-11-02 09:00'), $minutes, 'Work')->save();
    $task->update(['status' => TaskStatus::Done]);

    return $task->fresh();
}

function day(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date);
}

test('adding a task bills it and copies the default rate', function () {
    $client = Client::factory()->create();
    $task = doneTask($client, 90);
    $invoice = invoicing()->createDraft($client);

    $line = invoicing()->addTask($invoice, $task);

    expect($task->fresh()->status)->toBe(TaskStatus::Billed)
        ->and($line->unit_price_cents)->toBe(12000)
        ->and($line->project_id)->toBe($task->project_id)
        ->and($line->description)->toBe($task->name)
        ->and($line->amountCents())->toBe(18000)
        ->and($invoice->status)->toBe(InvoiceStatus::Draft)
        ->and($invoice->number)->toBeNull();
});

test('task line copies the project rate and ignores later rate changes', function () {
    $client = Client::factory()->create();
    $task = doneTask($client, 60, rateCents: 10000);
    $invoice = invoicing()->createDraft($client);

    $line = invoicing()->addTask($invoice, $task);
    $task->project->update(['rate_cents' => 15000]);

    expect($line->fresh()->unit_price_cents)->toBe(10000)
        ->and($line->fresh()->amountCents())->toBe(10000);
});

test('adding a task of another client is refused', function () {
    $invoice = invoicing()->createDraft(Client::factory()->create());
    $task = doneTask(Client::factory()->create());

    expect(fn () => invoicing()->addTask($invoice, $task))->toThrow(DomainException::class);
    expect($task->fresh()->status)->toBe(TaskStatus::Done)->and($invoice->lines()->count())->toBe(0);
});

test('a billed task cannot be added twice', function () {
    $client = Client::factory()->create();
    $task = doneTask($client);
    invoicing()->addTask(invoicing()->createDraft($client), $task);

    expect(fn () => invoicing()->addTask(invoicing()->createDraft($client), $task))->toThrow(DomainException::class);
});

test('adding an expense bills it and copies its billed amount', function () {
    $client = Client::factory()->create();
    $expense = Expense::factory()->for($client)->create(['cost_cents' => 4000, 'billed_amount_cents' => 5000]);
    $invoice = invoicing()->createDraft($client);

    $line = invoicing()->addExpense($invoice, $expense);

    expect($expense->fresh()->status)->toBe(ExpenseStatus::Billed)
        ->and($line->unit_price_cents)->toBe(5000)
        ->and($line->amountCents())->toBe(5000);
    expect(fn () => invoicing()->addExpense(invoicing()->createDraft(Client::factory()->create()), Expense::factory()->create()))
        ->toThrow(DomainException::class);
});

test('item lines accept negative prices and refuse a zero quantity', function () {
    $invoice = invoicing()->createDraft(Client::factory()->create());

    $line = invoicing()->addItem($invoice, 'Goodwill', '1', -2500);

    expect($line->amountCents())->toBe(-2500)->and($line->quantity)->toBe('1.00');
    expect(fn () => invoicing()->addItem($invoice, 'Nothing', '0', 1000))->toThrow(DomainException::class);
    expect(fn () => invoicing()->addItem($invoice, 'Bad', '1.234', 1000))->toThrow(DomainException::class);
});

test('removing a line releases its task and expense', function () {
    $client = Client::factory()->create();
    $task = doneTask($client);
    $expense = Expense::factory()->for($client)->create();
    $invoice = invoicing()->createDraft($client);
    $taskLine = invoicing()->addTask($invoice, $task);
    $expenseLine = invoicing()->addExpense($invoice, $expense);

    invoicing()->removeLine($taskLine);
    invoicing()->removeLine($expenseLine);

    expect($task->fresh()->status)->toBe(TaskStatus::Done)
        ->and($expense->fresh()->status)->toBe(ExpenseStatus::Unbilled)
        ->and($invoice->lines()->count())->toBe(0);
});

test('deleting a draft releases everything', function () {
    $client = Client::factory()->create();
    $task = doneTask($client);
    $expense = Expense::factory()->for($client)->create();
    $invoice = invoicing()->createDraft($client);
    invoicing()->addTask($invoice, $task);
    invoicing()->addExpense($invoice, $expense);
    invoicing()->addItem($invoice, 'Setup', '1', 1000);

    invoicing()->deleteDraft($invoice);

    expect(Invoice::count())->toBe(0)
        ->and($task->fresh()->status)->toBe(TaskStatus::Done)
        ->and($expense->fresh()->status)->toBe(ExpenseStatus::Unbilled);
});

test('time entries of a task on a draft invoice stay editable and the amount follows', function () {
    $client = Client::factory()->create();
    $task = doneTask($client, 60);
    $invoice = invoicing()->createDraft($client);
    $line = invoicing()->addTask($invoice, $task);

    $task->timeEntries()->first()->update(['duration_minutes' => 120]);

    expect($line->fresh()->amountCents())->toBe(24000);
    expect(fn () => TimeEntry::fromStartAndDuration($task->fresh(), day('2026-11-03 09:00'), 30, 'More')->save())
        ->toThrow(DomainException::class);
});

test('after sending, time entries are locked and amounts frozen', function () {
    $client = Client::factory()->create();
    $task = doneTask($client, 60);
    $invoice = invoicing()->createDraft($client);
    $line = invoicing()->addTask($invoice, $task);
    invoicing()->markSent($invoice, day('2026-11-20'));
    $entry = $task->timeEntries()->first();

    expect(fn () => $entry->update(['duration_minutes' => 120]))->toThrow(DomainException::class);
    expect(fn () => $entry->delete())->toThrow(DomainException::class);
    expect($entry->fresh()->duration_minutes)->toBe(60)
        ->and($line->fresh()->quantity_minutes)->toBe(60)
        ->and($line->fresh()->amountCents())->toBe(12000)
        ->and($invoice->fresh()->total_cents)->toBe(12000);
});

test('frozen minutes ignore entries changed behind the guard', function () {
    $client = Client::factory()->create();
    $task = doneTask($client, 60);
    $invoice = invoicing()->createDraft($client);
    $line = invoicing()->addTask($invoice, $task);
    invoicing()->markSent($invoice, day('2026-11-20'));

    TimeEntry::query()->update(['duration_minutes' => 600]); // bypasses model hooks

    expect($line->fresh()->amountCents())->toBe(12000);
});

test('sending an invoice with a negative total is refused', function () {
    $invoice = invoicing()->createDraft(Client::factory()->create());
    invoicing()->addItem($invoice, 'Work', '1', 12000);
    invoicing()->setDiscount($invoice, DiscountType::Amount, 20000);

    expect(fn () => invoicing()->markSent($invoice, day('2026-11-20')))->toThrow(DomainException::class);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Draft)->and($invoice->fresh()->number)->toBeNull();
});

test('sending an empty invoice or one dated in the future is refused', function () {
    $invoice = invoicing()->createDraft(Client::factory()->create());
    expect(fn () => invoicing()->markSent($invoice, day('2026-11-20')))->toThrow(DomainException::class);

    invoicing()->addItem($invoice, 'Work', '1', 12000);
    expect(fn () => invoicing()->markSent($invoice, CarbonImmutable::today()->addDay()))->toThrow(DomainException::class);

    invoicing()->markSent($invoice, CarbonImmutable::today());
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Sent);
});

test('sending freezes total, language and dates', function () {
    $client = Client::factory()->create(['language' => Language::En]);
    $invoice = invoicing()->createDraft($client);
    invoicing()->addItem($invoice, 'Work', '1', 12347);
    invoicing()->setDiscount($invoice, DiscountType::Percent, 1000);

    invoicing()->markSent($invoice, day('2026-11-20'));
    $invoice->refresh();

    expect($invoice->status)->toBe(InvoiceStatus::Sent)
        ->and($invoice->language)->toBe(Language::En)
        ->and($invoice->total_cents)->toBe(11110) // 12347 − 1235 = 11112 → 11110
        ->and($invoice->sent_on->toDateString())->toBe('2026-11-20')
        ->and($invoice->due_on->toDateString())->toBe('2026-12-20');
});

test('discount validation', function () {
    $invoice = invoicing()->createDraft(Client::factory()->create());

    expect(fn () => invoicing()->setDiscount($invoice, DiscountType::Percent, 0))->toThrow(DomainException::class);
    expect(fn () => invoicing()->setDiscount($invoice, DiscountType::Percent, 10001))->toThrow(DomainException::class);
    expect(fn () => invoicing()->setDiscount($invoice, DiscountType::Amount, -5))->toThrow(DomainException::class);
    expect(fn () => invoicing()->setDiscount($invoice, DiscountType::Amount, null))->toThrow(DomainException::class);

    invoicing()->setDiscount($invoice, DiscountType::Amount, 500);
    expect($invoice->fresh()->discount_value)->toBe(500);

    invoicing()->setDiscount($invoice, null, null);
    expect($invoice->fresh()->discount_type)->toBeNull()->and($invoice->fresh()->discount_value)->toBeNull();
});

test('mark paid', function () {
    $invoice = invoicing()->createDraft(Client::factory()->create());
    invoicing()->addItem($invoice, 'Work', '1', 12000);
    expect(fn () => invoicing()->markPaid($invoice, day('2026-11-20')))->toThrow(DomainException::class);

    invoicing()->markSent($invoice, day('2026-11-10'));
    expect(fn () => invoicing()->markPaid($invoice, day('2026-11-09')))->toThrow(DomainException::class);
    invoicing()->markPaid($invoice, day('2026-11-20'));

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->fresh()->paid_on->toDateString())->toBe('2026-11-20')
        ->and($invoice->fresh()->isOverdue())->toBeFalse();
});

test('cancel releases task and expense and keeps the number', function () {
    $client = Client::factory()->create();
    $task = doneTask($client);
    $expense = Expense::factory()->for($client)->create();
    $invoice = invoicing()->createDraft($client);
    invoicing()->addTask($invoice, $task);
    invoicing()->addExpense($invoice, $expense);
    expect(fn () => invoicing()->cancel($invoice, day('2026-11-20')))->toThrow(DomainException::class);

    invoicing()->markSent($invoice, day('2026-11-20'));
    $number = $invoice->fresh()->number;
    invoicing()->cancel($invoice, day('2026-11-21'));
    $invoice->refresh();

    expect($invoice->status)->toBe(InvoiceStatus::Cancelled)
        ->and($invoice->number)->toBe($number)
        ->and($invoice->cancelled_on->toDateString())->toBe('2026-11-21')
        ->and($invoice->lines()->count())->toBe(2)
        ->and($task->fresh()->status)->toBe(TaskStatus::Done)
        ->and($expense->fresh()->status)->toBe(ExpenseStatus::Unbilled);

    // Released work can be invoiced again; the cancelled invoice keeps its frozen amounts.
    $again = invoicing()->createDraft($client);
    invoicing()->addTask($again, $task->fresh());
    invoicing()->addExpense($again, $expense->fresh());
    expect($again->lines()->count())->toBe(2)->and($invoice->totals()->totalCents)->toBe($invoice->total_cents);

    // Its time entries follow the new draft invoice.
    $task->timeEntries()->first()->update(['duration_minutes' => 120]);
    expect($task->timeEntries()->first()->duration_minutes)->toBe(120);
});

test('overdue is derived from the due date', function () {
    $invoice = invoicing()->createDraft(Client::factory()->create());
    invoicing()->addItem($invoice, 'Work', '1', 12000);
    invoicing()->markSent($invoice, day('2026-11-01'));

    $this->travelTo(day('2026-12-01 12:00')); // day 30
    expect($invoice->fresh()->isOverdue())->toBeFalse()
        ->and($invoice->fresh()->displayStatus())->toBe('sent')
        ->and(Invoice::overdue()->count())->toBe(0)
        ->and(Invoice::open()->count())->toBe(1);

    $this->travelTo(day('2026-12-02 00:01')); // day 31
    expect($invoice->fresh()->isOverdue())->toBeTrue()
        ->and($invoice->fresh()->displayStatus())->toBe('overdue')
        ->and(Invoice::overdue()->count())->toBe(1)
        ->and(Invoice::open()->count())->toBe(1);
});

test('lines cannot be added to or removed from a sent invoice', function () {
    $client = Client::factory()->create();
    $invoice = invoicing()->createDraft($client);
    $line = invoicing()->addItem($invoice, 'Work', '1', 12000);
    invoicing()->markSent($invoice, day('2026-11-20'));

    expect(fn () => invoicing()->addItem($invoice, 'More', '1', 100))->toThrow(DomainException::class);
    expect(fn () => invoicing()->addTask($invoice, doneTask($client)))->toThrow(DomainException::class);
    expect(fn () => invoicing()->addExpense($invoice, Expense::factory()->for($client)->create()))->toThrow(DomainException::class);
    expect(fn () => invoicing()->removeLine($line))->toThrow(DomainException::class);
    expect(fn () => invoicing()->setDiscount($invoice, DiscountType::Amount, 100))->toThrow(DomainException::class);
    expect(fn () => invoicing()->deleteDraft($invoice))->toThrow(DomainException::class);
    expect(fn () => $line->delete())->toThrow(DomainException::class);
    expect(fn () => $invoice->fresh()->delete())->toThrow(DomainException::class);
    expect($invoice->lines()->count())->toBe(1);
});

test('client with an invoice cannot be deleted', function () {
    $client = Client::factory()->create();
    expect($client->canBeDeleted())->toBeTrue();

    invoicing()->createDraft($client);

    expect($client->canBeDeleted())->toBeFalse();
    expect(fn () => $client->delete())->toThrow(DomainException::class);
    expect(Client::count())->toBe(1);
});

test('project with invoiced work cannot be deleted', function () {
    $client = Client::factory()->create();
    $task = doneTask($client);
    $project = $task->project;
    expect($project->canBeDeleted())->toBeTrue();

    invoicing()->addTask(invoicing()->createDraft($client), $task);

    expect($project->canBeDeleted())->toBeFalse();
    expect(fn () => $project->delete())->toThrow(DomainException::class);
});

test('deleting a project cascades its tasks and time entries', function () {
    $task = doneTask(Client::factory()->create());
    $expense = Expense::factory()->for($task->project->client)->create(['project_id' => $task->project_id]);

    $task->project->delete();

    expect(Task::count())->toBe(0)
        ->and(TimeEntry::count())->toBe(0)
        ->and($expense->fresh()->project_id)->toBeNull();
});

test('deleting a client cascades projects, tasks, time entries and expenses', function () {
    $client = Client::factory()->create();
    doneTask($client);
    Expense::factory()->for($client)->create();

    $client->delete();

    expect(Project::count())->toBe(0)
        ->and(Task::count())->toBe(0)
        ->and(TimeEntry::count())->toBe(0)
        ->and(Expense::count())->toBe(0);
});

test('deleting a draft directly releases its work so it can be invoiced again', function () {
    $client = Client::factory()->create();
    $task = doneTask($client);
    $expense = Expense::factory()->for($client)->create();
    $invoice = invoicing()->createDraft($client);
    invoicing()->addTask($invoice, $task);
    invoicing()->addExpense($invoice, $expense);

    $invoice->delete();

    expect(Invoice::count())->toBe(0)
        ->and($task->fresh()->status)->toBe(TaskStatus::Done)
        ->and($expense->fresh()->status)->toBe(ExpenseStatus::Unbilled);
    $again = invoicing()->createDraft($client);
    invoicing()->addTask($again, $task->fresh());
    invoicing()->addExpense($again, $expense->fresh());
    expect($again->lines()->count())->toBe(2);
});

test('deleting a draft line directly releases its task or expense', function () {
    $client = Client::factory()->create();
    $task = doneTask($client);
    $expense = Expense::factory()->for($client)->create();
    $invoice = invoicing()->createDraft($client);
    $taskLine = invoicing()->addTask($invoice, $task);
    $expenseLine = invoicing()->addExpense($invoice, $expense);

    $taskLine->delete();
    $expenseLine->delete();

    expect($invoice->lines()->count())->toBe(0)
        ->and($task->fresh()->status)->toBe(TaskStatus::Done)
        ->and($expense->fresh()->status)->toBe(ExpenseStatus::Unbilled);
    invoicing()->addTask($invoice, $task->fresh());
    invoicing()->addExpense($invoice, $expense->fresh());
    expect($invoice->lines()->count())->toBe(2);
});

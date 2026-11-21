<?php

use App\Domain\Invoicing\InvoiceService;
use App\Enums\DiscountType;
use App\Enums\ExpenseStatus;
use App\Enums\InvoiceStatus;
use App\Enums\TaskStatus;
use App\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\Invoices\RelationManagers\LinesRelationManager;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-21 12:00'));
    Storage::fake('local');
    actingAs(User::factory()->withMfa()->create());
});

/** A Task in the given Project with the minutes logged. */
function uiTask(Project $project, string $name, int $minutes = 60, TaskStatus $status = TaskStatus::Done): Task
{
    $task = Task::factory()->for($project)->create(['name' => $name]);
    TimeEntry::fromStartAndDuration($task, CarbonImmutable::parse('2026-11-02 09:00'), $minutes, 'Work')->save();
    $task->update(['status' => $status]);

    return $task->fresh();
}

function linesManager(Invoice $invoice, string $page = EditInvoice::class)
{
    return Livewire::test(LinesRelationManager::class, ['ownerRecord' => $invoice, 'pageClass' => $page]);
}

/** A draft with one 1:00 task line at CHF 120. */
function uiDraftWithLine(): Invoice
{
    $client = Client::factory()->create();
    $invoice = app(InvoiceService::class)->createDraft($client);
    app(InvoiceService::class)->addTask($invoice, uiTask(Project::factory()->for($client)->create(), 'Build'));

    return $invoice->fresh();
}

test('create draft for an active client and land on its edit page', function () {
    $client = Client::factory()->create();
    $archived = Client::factory()->create(['archived_at' => now()]);

    $options = Livewire::test(CreateInvoice::class)->instance()->getSchema('form')->getFlatFields()['client_id']->getOptions();
    expect($options)->toHaveKey($client->id)->not->toHaveKey($archived->id);

    $component = Livewire::test(CreateInvoice::class)
        ->fillForm(['client_id' => $client->id])
        ->call('create')
        ->assertHasNoFormErrors();

    $invoice = Invoice::sole();
    expect($invoice->status)->toBe(InvoiceStatus::Draft)
        ->and($invoice->client_id)->toBe($client->id)
        ->and($invoice->language)->toBe($client->language);
    $component->assertRedirect(EditInvoice::getUrl(['record' => $invoice]));
});

test('add tasks lists only the client\'s non-billed tasks from active projects and adds the selected ones', function () {
    $client = Client::factory()->create();
    $project = Project::factory()->for($client)->create(['name' => 'Website']);
    $done = uiTask($project, 'Homepage', 90);
    $todo = uiTask($project, 'Contact form', 30, TaskStatus::Todo);
    $billedElsewhere = uiTask($project, 'Already invoiced');
    app(InvoiceService::class)->addTask(app(InvoiceService::class)->createDraft($client), $billedElsewhere);
    $archivedProject = uiTask(Project::factory()->for($client)->create(['archived_at' => now()]), 'Old archived work');
    $foreign = uiTask(Project::factory()->create(), 'Someone else');

    $invoice = app(InvoiceService::class)->createDraft($client);

    linesManager($invoice)
        ->mountAction(TestAction::make('addTasks')->table())
        ->assertMountedActionModalSee(['Website', 'Homepage', '1:30', 'CHF 180.00', 'Contact form'])
        ->assertMountedActionModalDontSee(['Already invoiced', 'Old archived work', 'Someone else']);

    linesManager($invoice)
        ->callAction(TestAction::make('addTasks')->table(), data: ['tasks' => [$done->id, $todo->id]])
        ->assertHasNoActionErrors();

    expect($invoice->lines()->pluck('task_id')->all())->toEqualCanonicalizing([$done->id, $todo->id])
        ->and($done->fresh()->status)->toBe(TaskStatus::Billed);

    // Ineligible ids sent anyway are never added.
    linesManager($invoice)->callAction(TestAction::make('addTasks')->table(), data: ['tasks' => [$foreign->id, $archivedProject->id]]);
    expect($invoice->lines()->count())->toBe(2);
});

test('add expenses lists only the client\'s unbilled expenses and adds the selected ones', function () {
    $client = Client::factory()->create();
    $mine = Expense::factory()->for($client)->create(['description' => 'Domain renewal', 'billed_amount_cents' => 2500, 'cost_cents' => 2500]);
    $foreign = Expense::factory()->create(['description' => 'Other client licence']);
    $billed = Expense::factory()->for($client)->create(['description' => 'Billed licence']);
    app(InvoiceService::class)->addExpense(app(InvoiceService::class)->createDraft($client), $billed);

    $invoice = app(InvoiceService::class)->createDraft($client);

    linesManager($invoice)
        ->mountAction(TestAction::make('addExpenses')->table())
        ->assertMountedActionModalSee(['Domain renewal', 'CHF 25.00'])
        ->assertMountedActionModalDontSee(['Other client licence', 'Billed licence']);

    linesManager($invoice)->callAction(TestAction::make('addExpenses')->table(), data: ['expenses' => [$foreign->id]]);
    expect($invoice->lines()->count())->toBe(0);

    linesManager($invoice)
        ->callAction(TestAction::make('addExpenses')->table(), data: ['expenses' => [$mine->id]])
        ->assertHasNoActionErrors();

    expect($invoice->lines()->pluck('expense_id')->all())->toBe([$mine->id])
        ->and($mine->fresh()->status)->toBe(ExpenseStatus::Billed);
});

test('add item accepts a negative unit price and remove releases a line', function () {
    $invoice = uiDraftWithLine();

    linesManager($invoice)
        ->callAction(TestAction::make('addItem')->table(), data: ['description' => 'Goodwill', 'quantity' => '1', 'unit_price' => '-50.00'])
        ->assertHasNoActionErrors();

    $item = $invoice->lines()->where('type', 'item')->sole();
    expect($item->unit_price_cents)->toBe(-5000)->and($item->amountCents())->toBe(-5000);

    linesManager($invoice)
        ->callAction(TestAction::make('addItem')->table(), data: ['description' => 'Bad', 'quantity' => '0', 'unit_price' => '10'])
        ->assertHasActionErrors(['quantity']);

    $taskLine = $invoice->lines()->where('type', 'task')->sole();
    linesManager($invoice)->callAction(TestAction::make('remove')->table($taskLine));
    expect($invoice->lines()->count())->toBe(1)
        ->and($taskLine->task->fresh()->status)->toBe(TaskStatus::Done);
});

test('discount and note are set from the edit form and totals follow', function () {
    $invoice = uiDraftWithLine();

    Livewire::test(EditInvoice::class, ['record' => $invoice->id])
        ->fillForm(['note' => 'Period: November 2026', 'discount_type' => 'percent', 'discount_value' => '10'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertSee('CHF -12.00')
        ->assertSee('CHF 108.00');

    $invoice->refresh();
    expect($invoice->discount_type)->toBe(DiscountType::Percent)
        ->and($invoice->discount_value)->toBe(1000)
        ->and($invoice->note)->toBe('Period: November 2026');

    Livewire::test(EditInvoice::class, ['record' => $invoice->id])
        ->fillForm(['discount_type' => 'amount', 'discount_value' => '20.50'])
        ->call('save');
    expect($invoice->fresh()->discount_value)->toBe(2050);

    Livewire::test(EditInvoice::class, ['record' => $invoice->id])
        ->fillForm(['discount_type' => 'percent', 'discount_value' => '150'])
        ->call('save')
        ->assertHasFormErrors(['discount_value']);

    Livewire::test(EditInvoice::class, ['record' => $invoice->id])
        ->fillForm(['discount_type' => null, 'discount_value' => null])
        ->call('save');
    expect($invoice->fresh()->discount_type)->toBeNull()->and($invoice->fresh()->discount_value)->toBeNull();
});

test('mark as sent asks for a sent date defaulting to today', function () {
    $invoice = uiDraftWithLine();

    Livewire::test(EditInvoice::class, ['record' => $invoice->id])
        ->mountAction('markSent')
        ->assertActionDataSet(['sent_on' => '2026-11-21'])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertRedirect(ViewInvoice::getUrl(['record' => $invoice]));

    $invoice->refresh();
    expect($invoice->status)->toBe(InvoiceStatus::Sent)
        ->and($invoice->number)->toBe('2026-024')
        ->and($invoice->pdf_path)->not->toBeNull();
});

test('a refused mark as sent shows a danger notification', function () {
    $invoice = app(InvoiceService::class)->createDraft(Client::factory()->create());

    Livewire::test(EditInvoice::class, ['record' => $invoice->id])
        ->callAction('markSent', data: ['sent_on' => '2026-11-21'])
        ->assertNotified('An invoice needs at least one line.');

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Draft);
});

test('mark as paid and cancel', function () {
    $paid = uiDraftWithLine();
    app(InvoiceService::class)->markSent($paid, CarbonImmutable::parse('2026-11-20'));

    Livewire::test(ViewInvoice::class, ['record' => $paid->id])
        ->assertActionVisible('markPaid')
        ->mountAction('markPaid')
        ->assertActionDataSet(['paid_on' => '2026-11-21'])
        ->callMountedAction();
    expect($paid->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($paid->fresh()->paid_on->toDateString())->toBe('2026-11-21');

    $cancelled = uiDraftWithLine();
    app(InvoiceService::class)->markSent($cancelled, CarbonImmutable::parse('2026-11-21'));
    Livewire::test(ViewInvoice::class, ['record' => $cancelled->id])->callAction('cancelInvoice');

    $cancelled->refresh();
    expect($cancelled->status)->toBe(InvoiceStatus::Cancelled)
        ->and($cancelled->lines->first()->task->status)->toBe(TaskStatus::Done);

    Livewire::test(ViewInvoice::class, ['record' => $cancelled->id])
        ->assertActionHidden('markPaid')
        ->assertActionHidden('cancelInvoice')
        ->assertActionVisible('downloadPdf');
});

test('delete draft releases its work', function () {
    $invoice = uiDraftWithLine();
    $task = $invoice->lines->first()->task;

    Livewire::test(EditInvoice::class, ['record' => $invoice->id])
        ->callAction('deleteDraft')
        ->assertRedirect(ListInvoices::getUrl());

    expect(Invoice::count())->toBe(0)->and($task->fresh()->status)->toBe(TaskStatus::Done);
});

test('edit actions are hidden on non-draft invoices', function () {
    $invoice = uiDraftWithLine();
    Livewire::test(EditInvoice::class, ['record' => $invoice->id])
        ->assertActionVisible('previewPdf')
        ->assertActionVisible('markSent')
        ->assertActionVisible('deleteDraft');
    linesManager($invoice)
        ->assertActionVisible(TestAction::make('addTasks')->table())
        ->assertActionVisible(TestAction::make('remove')->table($invoice->lines->first()));

    app(InvoiceService::class)->markSent($invoice, CarbonImmutable::today());
    $invoice->refresh();

    Livewire::test(ListInvoices::class)->assertActionHidden(TestAction::make('edit')->table($invoice));
    Livewire::test(ViewInvoice::class, ['record' => $invoice->id])
        ->assertActionHidden('edit')
        ->assertActionHidden('markSent')
        ->assertActionHidden('deleteDraft')
        ->assertActionHidden('previewPdf');
    linesManager($invoice, ViewInvoice::class)
        ->assertActionHidden(TestAction::make('addTasks')->table())
        ->assertActionHidden(TestAction::make('addExpenses')->table())
        ->assertActionHidden(TestAction::make('addItem')->table())
        ->assertActionHidden(TestAction::make('remove')->table($invoice->lines->first()));

    $this->get(EditInvoice::getUrl(['record' => $invoice]))->assertForbidden();
});

test('list shows computed status, number, client, total and dates, and filters by status including overdue', function () {
    $draft = uiDraftWithLine();

    $overdue = uiDraftWithLine();
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00'));
    app(InvoiceService::class)->markSent($overdue, CarbonImmutable::parse('2026-10-01'));
    $this->travelTo(CarbonImmutable::parse('2026-11-21 12:00'));

    $sent = uiDraftWithLine();
    app(InvoiceService::class)->markSent($sent, CarbonImmutable::parse('2026-11-20'));

    Livewire::test(ListInvoices::class)
        ->assertCanSeeTableRecords([$draft, $overdue, $sent])
        ->assertTableColumnFormattedStateSet('status', 'Overdue', $overdue)
        ->assertTableColumnFormattedStateSet('status', 'Sent', $sent)
        ->assertTableColumnFormattedStateSet('status', 'Draft', $draft)
        ->assertTableColumnStateSet('number', '2026-025', $sent)
        ->assertTableColumnStateSet('client.name', $sent->client->name, $sent)
        ->assertTableColumnFormattedStateSet('total', 'CHF 120.00', $sent)
        ->assertTableColumnFormattedStateSet('total', 'CHF 120.00', $draft)
        ->assertTableColumnFormattedStateSet('sent_on', '20.11.2026', $sent)
        ->assertTableColumnFormattedStateSet('due_on', '20.12.2026', $sent)
        ->filterTable('status', 'overdue')
        ->assertCanSeeTableRecords([$overdue])
        ->assertCanNotSeeTableRecords([$draft, $sent])
        ->resetTableFilters()
        // Filter values match the badges: "sent" leaves out the overdue ones.
        ->filterTable('status', 'sent')
        ->assertCanSeeTableRecords([$sent])
        ->assertCanNotSeeTableRecords([$draft, $overdue])
        ->filterTable('status', 'draft')
        ->assertCanSeeTableRecords([$draft])
        ->assertCanNotSeeTableRecords([$sent, $overdue]);
});

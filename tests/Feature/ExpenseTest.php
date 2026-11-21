<?php

use App\Domain\Invoicing\InvoiceService;
use App\Enums\ExpenseStatus;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Resources\Expenses\Pages\EditExpense;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\Expenses\Pages\ViewExpense;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(fn () => actingAs(User::factory()->withMfa()->create()));

function expenseData(Client $client, array $overrides = []): array
{
    return [
        'client_id' => $client->id,
        'date' => '2026-11-20',
        'supplier' => 'Digitec',
        'description' => 'USB-C cable',
        'cost_cents' => '99.50',
        ...$overrides,
    ];
}

test('billed amount defaults to cost', function () {
    $client = Client::factory()->create();

    Livewire::test(CreateExpense::class)
        ->fillForm(expenseData($client, ['billed_amount_cents' => '']))
        ->call('create')
        ->assertHasNoFormErrors();

    $expense = Expense::firstOrFail();
    expect($expense->cost_cents)->toBe(9950)->and($expense->billed_amount_cents)->toBe(9950);
});

test('billed amount can differ from cost but not be negative; cost must be positive', function () {
    $client = Client::factory()->create();

    Livewire::test(CreateExpense::class)
        ->fillForm(expenseData($client, ['billed_amount_cents' => '120.00']))
        ->call('create')
        ->assertHasNoFormErrors();
    expect(Expense::first()->billed_amount_cents)->toBe(12000);

    Livewire::test(CreateExpense::class)
        ->fillForm(expenseData($client, ['cost_cents' => '0']))
        ->call('create')
        ->assertHasFormErrors(['cost_cents']);

    Livewire::test(CreateExpense::class)
        ->fillForm(expenseData($client, ['billed_amount_cents' => '-5']))
        ->call('create')
        ->assertHasFormErrors(['billed_amount_cents']);
});

test('project must belong to the expense client', function () {
    $client = Client::factory()->create();
    $foreign = Project::factory()->create();
    $own = Project::factory()->for($client)->create();

    Livewire::test(CreateExpense::class)
        ->fillForm(expenseData($client, ['project_id' => $foreign->id]))
        ->call('create')
        ->assertHasFormErrors(['project_id']);
    expect(Expense::count())->toBe(0);

    Livewire::test(CreateExpense::class)
        ->fillForm(expenseData($client, ['project_id' => $own->id]))
        ->call('create')
        ->assertHasNoFormErrors();
    expect(Expense::first()->project_id)->toBe($own->id);
});

test('duplicate copies fields with todays date and unbilled status', function () {
    $this->travelTo('2026-11-21 10:00:00');
    $project = Project::factory()->create();
    $expense = Expense::factory()->billed()->create([
        'client_id' => $project->client_id,
        'project_id' => $project->id,
        'date' => '2026-01-05',
        'supplier' => 'SBB',
        'cost_cents' => 5000,
        'billed_amount_cents' => 6000,
        'original_amount_note' => 'EUR 50',
        'receipt_path' => 'receipts/x.pdf',
    ]);

    $copy = $expense->duplicate();

    expect($copy->exists)->toBeTrue()
        ->and($copy->id)->not->toBe($expense->id)
        ->and($copy->date->toDateString())->toBe('2026-11-21')
        ->and($copy->status)->toBe(ExpenseStatus::Unbilled)
        ->and($copy->receipt_path)->toBeNull()
        ->and($copy->only(['client_id', 'project_id', 'supplier', 'description', 'cost_cents', 'billed_amount_cents', 'original_amount_note']))
        ->toBe($expense->only(['client_id', 'project_id', 'supplier', 'description', 'cost_cents', 'billed_amount_cents', 'original_amount_note']));
});

test('duplicate is a row and header action', function () {
    $expense = Expense::factory()->create();

    Livewire::test(ListExpenses::class)->callAction(TestAction::make('duplicate')->table($expense));
    expect(Expense::count())->toBe(2);

    Livewire::test(ViewExpense::class, ['record' => $expense->id])->callAction('duplicate');
    Livewire::test(EditExpense::class, ['record' => $expense->id])->callAction('duplicate');
    expect(Expense::count())->toBe(4);
});

test('receipt stored on private local disk', function () {
    Storage::fake('local');
    Storage::fake('public');
    $client = Client::factory()->create();

    Livewire::test(CreateExpense::class)
        ->fillForm(expenseData($client, ['receipt_path' => UploadedFile::fake()->create('r.pdf', 100, 'application/pdf')]))
        ->call('create')
        ->assertHasNoFormErrors();

    $path = Expense::firstOrFail()->receipt_path;
    expect($path)->toStartWith('receipts/');
    Storage::disk('local')->assertExists($path);
    Storage::disk('public')->assertMissing($path);
});

test('receipt over 10 MB or wrong type is rejected', function () {
    Storage::fake('local');
    $client = Client::factory()->create();

    Livewire::test(CreateExpense::class)
        ->fillForm(expenseData($client, ['receipt_path' => UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf')]))
        ->call('create')
        ->assertHasFormErrors(['receipt_path']);

    Livewire::test(CreateExpense::class)
        ->fillForm(expenseData($client, ['receipt_path' => UploadedFile::fake()->create('x.exe', 10, 'application/x-msdownload')]))
        ->call('create')
        ->assertHasFormErrors(['receipt_path']);
});

test('receipt download requires auth', function () {
    Storage::fake('local');
    Storage::disk('local')->put('receipts/a.pdf', 'pdf-bytes');
    $expense = Expense::factory()->create(['receipt_path' => 'receipts/a.pdf']);

    auth()->logout();
    get(route('expenses.receipt', $expense))->assertRedirect('/login');

    actingAs(User::factory()->create())
        ->get(route('expenses.receipt', $expense))
        ->assertRedirect();

    actingAs(User::factory()->withMfa()->create())
        ->get(route('expenses.receipt', $expense))
        ->assertOk();
});

test('receipt download is not found without a receipt', function () {
    $expense = Expense::factory()->create();

    get(route('expenses.receipt', $expense))->assertNotFound();
});

test('client picker excludes archived clients but keeps the current one', function () {
    $active = Client::factory()->create();
    $archived = Client::factory()->archived()->create();

    $options = Livewire::test(CreateExpense::class)->instance()->getSchema('form')->getFlatFields()['client_id']->getOptions();
    expect($options)->toHaveKey($active->id)->not->toHaveKey($archived->id);

    $expense = Expense::factory()->for($archived)->create();
    $options = Livewire::test(EditExpense::class, ['record' => $expense->id])->instance()->getSchema('form')->getFlatFields()['client_id']->getOptions();
    expect($options)->toHaveKey($archived->id);
});

test('project picker offers only the selected clients selectable projects', function () {
    $client = Client::factory()->create();
    $mine = Project::factory()->for($client)->create();
    $archived = Project::factory()->for($client)->archived()->create();
    $other = Project::factory()->create();

    $component = Livewire::test(CreateExpense::class)->fillForm(['client_id' => $client->id]);
    $options = $component->instance()->getSchema('form')->getFlatFields()['project_id']->getOptions();

    expect($options)->toHaveKey($mine->id)->not->toHaveKey($archived->id)->not->toHaveKey($other->id);
});

test('billed expense is read-only in the UI and model', function () {
    $expense = Expense::factory()->billed()->create();

    expect(fn () => $expense->update(['cost_cents' => 1]))->toThrow(DomainException::class)
        ->and(fn () => $expense->update(['client_id' => Client::factory()->create()->id]))->toThrow(DomainException::class)
        ->and(fn () => $expense->delete())->toThrow(DomainException::class);

    Livewire::test(ListExpenses::class)
        ->assertActionHidden(TestAction::make('edit')->table($expense))
        ->assertActionHidden(TestAction::make('delete')->table($expense));

    Livewire::test(ViewExpense::class, ['record' => $expense->id])->assertActionHidden('edit');

    // Status can still change (invoicing toggles it).
    $expense = $expense->fresh();
    $expense->update(['status' => ExpenseStatus::Unbilled]);
    expect($expense->fresh()->status)->toBe(ExpenseStatus::Unbilled);
});

test('unbilled expense can be edited and deleted, deleting removes the receipt', function () {
    Storage::fake('local');
    Storage::disk('local')->put('receipts/a.pdf', 'x');
    $expense = Expense::factory()->create(['receipt_path' => 'receipts/a.pdf']);

    Livewire::test(ListExpenses::class)->assertActionVisible(TestAction::make('edit')->table($expense));

    $expense->update(['supplier' => 'New']);
    $expense->delete();

    expect(Expense::count())->toBe(0);
    Storage::disk('local')->assertMissing('receipts/a.pdf');
});

test('an expense referenced by a released invoice line cannot be deleted and never raises a 500', function () {
    $client = Client::factory()->create();
    $expense = Expense::factory()->for($client)->create();
    $service = app(InvoiceService::class);
    $invoice = $service->createDraft($client);
    $service->addExpense($invoice, $expense);
    $service->markSent($invoice, CarbonImmutable::today());
    $service->cancel($invoice, CarbonImmutable::today());
    $expense->refresh();

    expect($expense->isBilled())->toBeFalse()
        ->and($expense->canBeDeleted())->toBeFalse()
        ->and(fn () => $expense->delete())->toThrow(DomainException::class)
        ->and(ExpenseResource::canDelete($expense))->toBeFalse();

    Livewire::test(EditExpense::class, ['record' => $expense->getKey()])
        ->assertActionHidden('delete');
    Livewire::test(ListExpenses::class)
        ->assertActionHidden(TestAction::make('delete')->table($expense));
    expect(Expense::whereKey($expense->id)->exists())->toBeTrue();
});

test('an unreferenced expense can be deleted', function () {
    expect(Expense::factory()->create()->canBeDeleted())->toBeTrue();
});

test('billed amount follows a corrected cost unless it was explicitly different', function () {
    $followed = Expense::factory()->create(['cost_cents' => 1000, 'billed_amount_cents' => 1000]);
    $followed->update(['cost_cents' => 1200]);
    expect($followed->fresh()->billed_amount_cents)->toBe(1200);

    $marked = Expense::factory()->create(['cost_cents' => 1000, 'billed_amount_cents' => 1500]);
    $marked->update(['cost_cents' => 1200]);
    expect($marked->fresh()->billed_amount_cents)->toBe(1500);

    $explicit = Expense::factory()->create(['cost_cents' => 1000, 'billed_amount_cents' => 1000]);
    $explicit->update(['cost_cents' => 1200, 'billed_amount_cents' => 1100]);
    expect($explicit->fresh()->billed_amount_cents)->toBe(1100);
});

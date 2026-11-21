<?php

use App\Domain\Invoicing\InvoiceService;
use App\Domain\Reporting\UnbilledWork;
use App\Enums\TaskStatus;
use App\Filament\Widgets\OpenInvoices;
use App\Filament\Widgets\RecentTimeEntries;
use App\Filament\Widgets\RevenueThisYear;
use App\Filament\Widgets\TasksInProgress;
use App\Filament\Widgets\UnbilledWorkTable;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-21 12:00'));
    Storage::fake('local');
    actingAs(User::factory()->withMfa()->create());
});

function dashTask(Project $project, int $minutes, TaskStatus $status, string $name = 'Task'): Task
{
    $task = Task::factory()->for($project)->create(['name' => $name]);
    if ($minutes > 0) {
        TimeEntry::fromStartAndDuration($task, CarbonImmutable::parse('2026-11-02 09:00'), $minutes, 'Work')->save();
    }
    $task->update(['status' => $status]);

    return $task->fresh();
}

/** A sent invoice (one 1:00 task at CHF 120 => CHF 120.00), optionally paid/cancelled. */
function dashInvoice(Client $client, string $sentOn, ?string $paidOn = null, bool $cancel = false): Invoice
{
    $service = app(InvoiceService::class);
    $invoice = $service->createDraft($client);
    $service->addTask($invoice, dashTask(Project::factory()->for($client)->create(), 60, TaskStatus::Done, 'Billable'));
    $service->markSent($invoice, CarbonImmutable::parse($sentOn));
    $invoice = $invoice->fresh();
    if ($paidOn) {
        $service->markPaid($invoice, CarbonImmutable::parse($paidOn));
    }
    if ($cancel) {
        $service->cancel($invoice, CarbonImmutable::parse($sentOn));
    }

    return $invoice->fresh();
}

test('unbilled work sums done tasks and unbilled expenses per client', function () {
    $a = Client::factory()->create(['name' => 'Alpha']);
    $b = Client::factory()->create(['name' => 'Beta']);
    $empty = Client::factory()->create(['name' => 'Empty']);
    $projectA = Project::factory()->for($a)->create(['rate_cents' => 10000]);
    $defaultRate = Project::factory()->for($a)->create(['rate_cents' => null]); // 12000
    $projectB = Project::factory()->for($b)->create(['rate_cents' => 10000]);

    dashTask($projectA, 90, TaskStatus::Done);          // 15000
    dashTask($defaultRate, 60, TaskStatus::Done);       // 12000
    dashTask($projectA, 60, TaskStatus::Todo);
    dashTask($projectA, 60, TaskStatus::InProgress);
    dashTask($projectA, 60, TaskStatus::Billed);
    Expense::factory()->for($a)->create(['cost_cents' => 5000, 'billed_amount_cents' => 6000]);
    Expense::factory()->for($a)->billed()->create(['billed_amount_cents' => 9999]);
    Expense::factory()->for($b)->create(['billed_amount_cents' => 2500]);
    dashTask($projectB, 15, TaskStatus::Done);          // 2500

    $rows = UnbilledWork::perClient();

    expect($rows->pluck('client.id')->all())->toBe([$a->id, $b->id])
        ->and($rows[0])->toMatchArray(['minutes' => 150, 'task_amount_cents' => 27000, 'expense_amount_cents' => 6000, 'total_cents' => 33000])
        ->and($rows[1])->toMatchArray(['minutes' => 15, 'task_amount_cents' => 2500, 'expense_amount_cents' => 2500, 'total_cents' => 5000])
        ->and($rows->pluck('client.id'))->not->toContain($empty->id);
});

test('unbilled work rounds each task half up and does not query per client', function () {
    $project = Project::factory()->create(['rate_cents' => 10001]);
    dashTask($project, 15, TaskStatus::Done, 'One');   // 2500.25 -> 2500
    dashTask($project, 30, TaskStatus::Done, 'Two');   // 5000.5 -> 5001
    foreach (range(1, 3) as $i) {
        dashTask(Project::factory()->create(), 60, TaskStatus::Done);
        Expense::factory()->create();
    }

    DB::enableQueryLog();
    $rows = UnbilledWork::perClient();
    $queries = count(DB::getQueryLog());

    expect($rows->firstWhere('client.id', $project->client_id)['task_amount_cents'])->toBe(7501)
        ->and($queries)->toBeLessThanOrEqual(5);
});

test('revenue this year sums total_cents of invoices paid this calendar year', function () {
    $client = Client::factory()->create();
    // Sent dates must ascend.
    dashInvoice($client, '2025-12-01', '2025-12-20'); // paid last year
    dashInvoice($client, '2026-03-01', '2026-03-10');
    dashInvoice($client, '2026-04-01', '2026-12-31');
    dashInvoice($client, '2026-05-01', null, cancel: true);
    dashInvoice($client, '2026-06-01');               // sent, unpaid

    expect(RevenueThisYear::paidThisYearCents())->toBe(24000);

    Livewire::test(RevenueThisYear::class)->assertSee('CHF 240.00')->assertSee('2026');
});

test('open invoices widget lists sent and overdue, not paid/cancelled/draft', function () {
    $client = Client::factory()->create();
    $overdue = dashInvoice($client, '2026-09-01');              // due 2026-10-01
    $paid = dashInvoice($client, '2026-09-02', '2026-09-05');
    $cancelled = dashInvoice($client, '2026-09-03', null, cancel: true);
    $sent = dashInvoice($client, '2026-11-10');                 // due 2026-12-10
    $draft = app(InvoiceService::class)->createDraft($client);

    Livewire::test(OpenInvoices::class)
        ->assertCanSeeTableRecords([$sent, $overdue])
        ->assertCanNotSeeTableRecords([$paid, $cancelled, $draft])
        ->assertSee('Overdue');
});

test('recent time entries shows the latest 10 and tasks in progress only in-progress ones', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->for($project)->create();
    foreach (range(1, 12) as $day) {
        TimeEntry::fromStartAndDuration($task, CarbonImmutable::parse('2026-11-01 09:00')->addDays($day), 60, "Entry $day")->save();
    }
    $todo = Task::factory()->for($project)->create();
    $done = dashTask($project, 60, TaskStatus::Done);

    $oldest = TimeEntry::where('description', 'Entry 1')->first();
    $newest = TimeEntry::where('description', 'Entry 12')->first();

    $recent = Livewire::test(RecentTimeEntries::class);
    expect($recent->instance()->getTableRecords())->toHaveCount(10);
    $recent
        ->assertCanSeeTableRecords([$newest])
        ->assertCanNotSeeTableRecords([$oldest]);

    Livewire::test(TasksInProgress::class)
        ->assertCanSeeTableRecords([$task->fresh()])
        ->assertCanNotSeeTableRecords([$todo, $done]);
});

test('unbilled work widget renders client rows', function () {
    $client = Client::factory()->create(['name' => 'Acme']);
    dashTask(Project::factory()->for($client)->create(['rate_cents' => 12000]), 90, TaskStatus::Done);

    Livewire::test(UnbilledWorkTable::class)->assertSee('Acme')->assertSee('1:30')->assertSee('CHF 180.00');
});

test('dashboard renders', function () {
    $this->get('/')->assertOk()
        ->assertSeeLivewire(RevenueThisYear::class)
        ->assertSeeLivewire(UnbilledWorkTable::class)
        ->assertSeeLivewire(RecentTimeEntries::class)
        ->assertSeeLivewire(TasksInProgress::class)
        ->assertSeeLivewire(OpenInvoices::class)
        ->assertDontSeeLivewire(FilamentInfoWidget::class);
});

<?php

use App\Enums\TaskStatus;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\Clients\RelationManagers\ProjectsRelationManager;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Projects\RelationManagers\TasksRelationManager;
use App\Filament\Resources\Tasks\Pages\CreateTask;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Filament\Resources\Tasks\RelationManagers\TimeEntriesRelationManager;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(fn () => actingAs(User::factory()->withMfa()->create()));

function at(string $time): CarbonImmutable
{
    return CarbonImmutable::parse("2026-11-21 $time");
}

test('start plus duration computes end', function () {
    $entry = TimeEntry::fromStartAndDuration(Task::factory()->create(), at('09:00'), 20, 'Work');

    expect($entry->duration_minutes)->toBe(30)
        ->and($entry->ended_at->format('H:i'))->toBe('09:30');
});

test('start plus end computes rounded duration', function () {
    $entry = TimeEntry::fromStartAndEnd(Task::factory()->create(), at('09:00'), at('10:20'), 'Work');

    expect($entry->duration_minutes)->toBe(90)
        ->and($entry->ended_at->format('H:i'))->toBe('10:30');
});

test('end before or equal start is rejected', function () {
    $task = Task::factory()->create();

    expect(fn () => TimeEntry::fromStartAndEnd($task, at('09:00'), at('09:00'), 'Work'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => TimeEntry::fromStartAndEnd($task, at('09:00'), at('08:00'), 'Work'))
        ->toThrow(InvalidArgumentException::class);

    Livewire::test(TimeEntriesRelationManager::class, ['ownerRecord' => $task, 'pageClass' => ViewTask::class])
        ->callAction(TestAction::make('create')->table(), [
            'started_on' => '2026-11-21',
            'started_time' => '09:00',
            'use_end_time' => true,
            'ended_on' => '2026-11-21',
            'ended_time' => '08:00',
            'description' => 'Work',
        ])
        ->assertHasFormErrors(['ended_time']);

    expect(TimeEntry::count())->toBe(0);
});

test('entry across midnight is valid', function () {
    $entry = TimeEntry::fromStartAndDuration(Task::factory()->create(), at('23:30'), 60, 'Late');

    expect($entry->ended_at->toDateTimeString())->toBe('2026-11-22 00:30:00');

    $task = Task::factory()->create();
    Livewire::test(TimeEntriesRelationManager::class, ['ownerRecord' => $task, 'pageClass' => ViewTask::class])
        ->callAction(TestAction::make('create')->table(), [
            'started_on' => '2026-11-21',
            'started_time' => '23:30',
            'use_end_time' => true,
            'ended_on' => '2026-11-22',
            'ended_time' => '00:30',
            'description' => 'Late',
        ])
        ->assertHasNoFormErrors();

    expect($task->timeEntries()->first()->duration_minutes)->toBe(60);
});

test('saving re-applies rounding on every write path', function () {
    $entry = TimeEntry::create([
        'task_id' => Task::factory()->create()->id,
        'started_at' => at('09:00'),
        'ended_at' => at('09:07'),
        'duration_minutes' => 7,
        'description' => 'Raw',
    ]);

    expect($entry->fresh()->duration_minutes)->toBe(15)
        ->and($entry->fresh()->ended_at->format('H:i'))->toBe('09:15');

    $entry->update(['duration_minutes' => 50]);
    expect($entry->fresh()->duration_minutes)->toBe(60)
        ->and($entry->fresh()->ended_at->format('H:i'))->toBe('10:00');
});

test('first entry moves todo task to in progress and done stays done', function () {
    $task = Task::factory()->create(['status' => TaskStatus::Todo]);
    TimeEntry::fromStartAndDuration($task, at('09:00'), 30, 'One')->save();
    expect($task->fresh()->status)->toBe(TaskStatus::InProgress);

    $done = Task::factory()->create(['status' => TaskStatus::Done]);
    TimeEntry::fromStartAndDuration($done, at('09:00'), 30, 'Two')->save();
    expect($done->fresh()->status)->toBe(TaskStatus::Done)
        ->and($done->totalMinutes())->toBe(30);
});

test('billed task refuses new entries edits and deletes', function () {
    $task = Task::factory()->create();
    $entry = TimeEntry::fromStartAndDuration($task, at('09:00'), 30, 'One');
    $entry->save();
    $task->update(['status' => TaskStatus::Billed]);

    expect(fn () => TimeEntry::fromStartAndDuration($task->fresh(), at('11:00'), 30, 'Two')->save())
        ->toThrow(DomainException::class)
        ->and(fn () => $entry->fresh()->update(['description' => 'Changed']))
        ->toThrow(DomainException::class)
        ->and(fn () => $entry->fresh()->delete())
        ->toThrow(DomainException::class);

    expect($task->timeEntries()->count())->toBe(1);
});

test('billed task refusal surfaces as a notification in the UI', function () {
    $task = Task::factory()->create(['status' => TaskStatus::Billed]);

    Livewire::test(TimeEntriesRelationManager::class, ['ownerRecord' => $task, 'pageClass' => ViewTask::class])
        ->callAction(TestAction::make('create')->table(), [
            'started_on' => '2026-11-21',
            'started_time' => '09:00',
            'duration' => 30,
            'description' => 'Work',
        ])
        ->assertNotified();

    expect(TimeEntry::count())->toBe(0);
});

test('create action works with start and duration', function () {
    $task = Task::factory()->create();

    Livewire::test(TimeEntriesRelationManager::class, ['ownerRecord' => $task, 'pageClass' => ViewTask::class])
        ->callAction(TestAction::make('create')->table(), [
            'started_on' => '2026-11-21',
            'started_time' => '09:00',
            'duration' => 90,
            'description' => 'Work',
        ])
        ->assertHasNoFormErrors();

    $entry = $task->timeEntries()->sole();
    expect($entry->duration_minutes)->toBe(90)->and($entry->ended_at->format('H:i'))->toBe('10:30');
});

test('description is required', function () {
    $task = Task::factory()->create();

    Livewire::test(TimeEntriesRelationManager::class, ['ownerRecord' => $task, 'pageClass' => ViewTask::class])
        ->callAction(TestAction::make('create')->table(), [
            'started_on' => '2026-11-21',
            'started_time' => '09:00',
            'duration' => 30,
            'description' => '',
        ])
        ->assertHasFormErrors(['description' => 'required']);
});

test('task create form lists only selectable projects', function () {
    $ok = Project::factory()->create();
    $archivedProject = Project::factory()->archived()->create();
    $archivedClient = Client::factory()->archived()->create();
    $ofArchivedClient = Project::factory()->for($archivedClient)->create();

    $select = collect(Livewire::test(CreateTask::class)->instance()->getSchema('form')->getFlatComponents())
        ->first(fn ($c) => $c->getName() === 'project_id');

    expect($select->getOptions())->toHaveKey($ok->id)
        ->not->toHaveKey($archivedProject->id)
        ->not->toHaveKey($ofArchivedClient->id);
});

test('create actions are hidden for archived parents', function () {
    $archivedClient = Client::factory()->archived()->create();
    Livewire::test(ProjectsRelationManager::class, ['ownerRecord' => $archivedClient, 'pageClass' => ViewClient::class])
        ->assertActionHidden(TestAction::make('create')->table());

    $activeClient = Client::factory()->create();
    Livewire::test(ProjectsRelationManager::class, ['ownerRecord' => $activeClient, 'pageClass' => ViewClient::class])
        ->assertActionVisible(TestAction::make('create')->table());

    $project = Project::factory()->for($archivedClient)->create();
    Livewire::test(TasksRelationManager::class, ['ownerRecord' => $project, 'pageClass' => ViewProject::class])
        ->assertActionHidden(TestAction::make('create')->table());

    $archivedProject = Project::factory()->for($activeClient)->archived()->create();
    Livewire::test(TasksRelationManager::class, ['ownerRecord' => $archivedProject, 'pageClass' => ViewProject::class])
        ->assertActionHidden(TestAction::make('create')->table());

    $ok = Project::factory()->for($activeClient)->create();
    Livewire::test(TasksRelationManager::class, ['ownerRecord' => $ok, 'pageClass' => ViewProject::class])
        ->assertActionVisible(TestAction::make('create')->table());
});

test('editing and deleting entries through the relation manager respects the guard', function () {
    $task = Task::factory()->create();
    $entry = TimeEntry::fromStartAndDuration($task, at('09:00'), 30, 'One');
    $entry->save();

    $manager = fn () => Livewire::test(TimeEntriesRelationManager::class, ['ownerRecord' => $task, 'pageClass' => ViewTask::class]);

    $manager()->callAction(TestAction::make('edit')->table($entry), [
        'started_on' => '2026-11-21',
        'started_time' => '09:00',
        'duration' => 60,
        'description' => 'Edited',
    ])->assertHasNoFormErrors();
    expect($entry->fresh())->duration_minutes->toBe(60)->description->toBe('Edited');

    $task->update(['status' => TaskStatus::Billed]);

    $manager()->callAction(TestAction::make('delete')->table($entry))->assertNotified();
    expect($entry->fresh())->not->toBeNull();
});

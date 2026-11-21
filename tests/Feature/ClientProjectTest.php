<?php

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\Clients\RelationManagers\ProjectsRelationManager;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Settings\BillingSettings;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(fn () => actingAs(User::factory()->withMfa()->create()));

test('project rate falls back to default', function () {
    $project = Project::factory()->create(['rate_cents' => null]);
    expect($project->effectiveRateCents())->toBe(12000);

    $project->update(['rate_cents' => 10000]);
    expect($project->effectiveRateCents())->toBe(10000);

    $project->update(['rate_cents' => null]);
    $settings = app(BillingSettings::class);
    $settings->default_rate_cents = 13000;
    $settings->save();
    expect($project->fresh()->effectiveRateCents())->toBe(13000);
});

test('archive scopes', function () {
    $active = Client::factory()->create();
    $archived = Client::factory()->create();
    $archived->archive();

    expect($archived->fresh()->isArchived())->toBeTrue()
        ->and($active->isArchived())->toBeFalse()
        ->and(Client::active()->pluck('id')->all())->toBe([$active->id])
        ->and(Client::archived()->pluck('id')->all())->toBe([$archived->id]);

    $archived->unarchive();
    expect(Client::active()->count())->toBe(2)->and(Client::archived()->count())->toBe(0);

    $project = Project::factory()->for($active)->create();
    $project->archive();
    expect(Project::active()->count())->toBe(0)->and(Project::archived()->count())->toBe(1);
});

test('project client picker lists only active clients', function () {
    $active = Client::factory()->create(['name' => 'Active Co']);
    $archived = Client::factory()->archived()->create(['name' => 'Gone Co']);

    $options = Livewire::test(CreateProject::class)->instance()->getSchema('form')->getFlatComponents();
    $select = collect($options)->first(fn ($c) => $c->getName() === 'client_id');

    expect($select->getOptions())->toHaveKey($active->id)->not->toHaveKey($archived->id);
});

test('archived client still viewable', function () {
    $client = Client::factory()->archived()->create();

    $this->get(ClientResource::getUrl('view', ['record' => $client]))->assertOk();
});

test('lists default to active and can show archived', function () {
    $active = Client::factory()->create();
    $archived = Client::factory()->archived()->create();

    Livewire::test(ListClients::class)
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$archived])
        ->filterTable('archived', true)
        ->assertCanSeeTableRecords([$archived]);

    $project = Project::factory()->for($active)->create();
    $archivedProject = Project::factory()->for($active)->archived()->create();

    Livewire::test(ListProjects::class)
        ->assertCanSeeTableRecords([$project])
        ->assertCanNotSeeTableRecords([$archivedProject]);
});

test('archive and unarchive actions work', function () {
    $client = Client::factory()->create();

    Livewire::test(ListClients::class)->callAction(TestAction::make('archive')->table($client));
    expect($client->fresh()->isArchived())->toBeTrue();

    Livewire::test(ListClients::class)
        ->filterTable('archived', true)
        ->callAction(TestAction::make('unarchive')->table($client));
    expect($client->fresh()->isArchived())->toBeFalse();
});

test('project rate is entered in chf and blank is null', function () {
    $client = Client::factory()->create();

    Livewire::test(ProjectsRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class])
        ->assertOk();

    Livewire::test(CreateProject::class)
        ->fillForm(['client_id' => $client->id, 'name' => 'Site', 'rate_cents' => '95.50'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Project::first()->rate_cents)->toBe(9550);

    Livewire::test(CreateProject::class)
        ->fillForm(['client_id' => $client->id, 'name' => 'Other', 'rate_cents' => ''])
        ->call('create')
        ->assertHasNoFormErrors();
    expect(Project::where('name', 'Other')->first()->rate_cents)->toBeNull();
});

test('archive and unarchive header actions work on record pages', function () {
    $client = Client::factory()->create();
    $project = Project::factory()->for($client)->create();

    foreach ([
        [ViewClient::class, $client],
        [EditClient::class, $client],
        [ViewProject::class, $project],
        [EditProject::class, $project],
    ] as [$page, $record]) {
        $record->unarchive();

        Livewire::test($page, ['record' => $record->getKey()])
            ->assertActionVisible('archive')
            ->assertActionHidden('unarchive')
            ->callAction('archive');
        expect($record->fresh()->isArchived())->toBeTrue();

        Livewire::test($page, ['record' => $record->getKey()])
            ->assertActionHidden('archive')
            ->callAction('unarchive');
        expect($record->fresh()->isArchived())->toBeFalse();
    }
});

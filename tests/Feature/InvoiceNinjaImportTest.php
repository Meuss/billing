<?php

use App\Domain\Import\InvoiceNinjaImporter;
use App\Enums\Language;
use App\Models\Client;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const IN_FIXTURE = __DIR__.'/../Fixtures/invoice-ninja-backup.json';

function importFixture(): void
{
    test()->artisan('billing:import-invoice-ninja', ['path' => IN_FIXTURE])->assertSuccessful();
}

it('imports clients and projects, skipping deleted ones and orphans', function () {
    importFixture();

    expect(Client::count())->toBe(5)
        ->and(Project::count())->toBe(5)
        ->and(Client::where('name', 'Deleted Co')->exists())->toBeFalse()
        ->and(Project::where('name', 'Deleted project')->exists())->toBeFalse()
        ->and(Project::where('name', 'Orphan of deleted client')->exists())->toBeFalse();
});

it('maps client fields', function () {
    importFixture();

    $acme = Client::where('invoice_ninja_id', 'CLfr0001aa')->first();
    expect($acme->name)->toBe('Acme SA')
        ->and($acme->address_line1)->toBe('Rue de Test 1')
        ->and($acme->address_line2)->toBe('Etage 2')
        ->and($acme->postal_code)->toBe('1000')
        ->and($acme->city)->toBe('Lausanne')
        ->and($acme->country)->toBe('FR')
        ->and($acme->email)->toBe('alice@example.ch')
        ->and($acme->phone)->toBe('+41 79 000 00 00')
        ->and($acme->contact_person)->toBe('Alice Martin')
        ->and($acme->website)->toBe('https://acme.example.ch')
        ->and($acme->notes)->toBe('Synthetic note')
        ->and($acme->language)->toBe(Language::Fr);

    $beta = Client::where('invoice_ninja_id', 'CLnull002b')->first();
    expect($beta->country)->toBe('CH')
        ->and($beta->address_line2)->toBeNull()
        ->and($beta->website)->toBeNull()
        ->and($beta->notes)->toBeNull()
        ->and($beta->contact_person)->toBeNull()
        ->and($beta->phone)->toBeNull();

    $gamma = Client::where('invoice_ninja_id', 'CLen0003cc')->first();
    expect($gamma->contact_person)->toBe('Bob')
        ->and($gamma->phone)->toBe('+41 44 000 00 00')
        ->and($gamma->country)->toBe('DE');
});

it('maps languages', function () {
    importFixture();

    expect(Client::where('invoice_ninja_id', 'CLfr0001aa')->first()->language)->toBe(Language::Fr)
        ->and(Client::where('invoice_ninja_id', 'CLnull002b')->first()->language)->toBe(Language::Fr)
        ->and(Client::where('invoice_ninja_id', 'CLen0003cc')->first()->language)->toBe(Language::En)
        ->and(Client::where('invoice_ninja_id', 'CLmiss005e')->first()->language)->toBe(Language::Fr);
});

it('maps project rates and archive state', function () {
    importFixture();

    expect(Project::where('invoice_ninja_id', 'PRdef001aa')->first()->rate_cents)->toBeNull()
        ->and(Project::where('invoice_ninja_id', 'PRdef001aa')->first()->notes)->toBe('Project note')
        ->and(Project::where('invoice_ninja_id', 'PRcus002bb')->first()->rate_cents)->toBe(10000)
        ->and(Project::where('invoice_ninja_id', 'PRzer003cc')->first()->rate_cents)->toBeNull()
        ->and(Project::where('invoice_ninja_id', 'PRarc005ee')->first()->rate_cents)->toBe(9550)
        ->and(Project::where('invoice_ninja_id', 'PRarc005ee')->first()->archived_at)->not->toBeNull()
        ->and(Project::where('invoice_ninja_id', 'PRdef001aa')->first()->archived_at)->toBeNull()
        ->and(Project::where('invoice_ninja_id', 'PRdef001aa')->first()->client->invoice_ninja_id)->toBe('CLfr0001aa');
});

it('imports incomplete clients with empty strings and reports warnings without names', function () {
    $this->artisan('billing:import-invoice-ninja', ['path' => IN_FIXTURE])
        ->expectsOutputToContain('CLmiss005e: address_line1')
        ->doesntExpectOutputToContain('Incomplete SA')
        ->assertSuccessful();

    $client = Client::where('invoice_ninja_id', 'CLmiss005e')->first();
    expect($client->address_line1)->toBe('')
        ->and($client->email)->toBe('')
        ->and($client->country)->toBe('CH');
});

it('is idempotent and never overwrites local edits', function () {
    importFixture();
    Client::where('invoice_ninja_id', 'CLfr0001aa')->update(['name' => 'Edited locally']);

    $result = app(InvoiceNinjaImporter::class)->import(json_decode(file_get_contents(IN_FIXTURE), true));

    expect($result['clients'])->toBe(0)
        ->and($result['projects'])->toBe(0)
        ->and($result['skipped'])->toBe(10)
        ->and(Client::count())->toBe(5)
        ->and(Project::count())->toBe(5)
        ->and(Client::where('invoice_ninja_id', 'CLfr0001aa')->first()->name)->toBe('Edited locally');
});

it('fails on a missing file', function () {
    $this->artisan('billing:import-invoice-ninja', ['path' => '/nonexistent/backup.json'])->assertFailed();
});

it('archives clients and projects flagged only by deleted_at, as in the real export', function () {
    importFixture();

    expect(Client::where('invoice_ninja_id', 'CLarc006ff')->first()->archived_at)->not->toBeNull()
        ->and(Client::where('invoice_ninja_id', 'CLfr0001aa')->first()->archived_at)->toBeNull()
        ->and(Project::where('invoice_ninja_id', 'PRarc007gg')->first()->archived_at)->not->toBeNull();
});

it('rejects a backup with a missing or blank id without importing anything', function (array $patch) {
    $backup = json_decode(file_get_contents(IN_FIXTURE), true);
    $backup['clients'][1] = $patch + $backup['clients'][1];
    unset($backup['clients'][1]['hashed_id']);
    $backup['clients'][1] = $patch + $backup['clients'][1];
    $path = tempnam(sys_get_temp_dir(), 'in');
    file_put_contents($path, json_encode($backup));

    $this->artisan('billing:import-invoice-ninja', ['path' => $path])
        ->expectsOutputToContain('clients row 1 has no hashed_id')
        ->assertFailed();

    expect(Client::count())->toBe(0);
    unlink($path);
})->with([[[]], [['hashed_id' => '']], [['hashed_id' => null]]]);

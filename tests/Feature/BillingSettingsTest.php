<?php

use App\Filament\Pages\ManageBillingSettings;
use App\Models\User;
use App\Settings\BillingSettings;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

test('defaults are seeded', function () {
    $s = app(BillingSettings::class);

    expect($s->sender_name)->toBe('Thomas Miller')
        ->and($s->sender_address_line1)->toBe('')
        ->and($s->sender_postal_code)->toBe('')
        ->and($s->sender_city)->toBe('Bulle')
        ->and($s->sender_country)->toBe('CH')
        ->and($s->sender_email)->toBe('contact@thomasmiller.ch')
        ->and($s->sender_phone)->toBe('+41 79 560 20 71')
        ->and($s->sender_website)->toBe('thomasmiller.ch')
        ->and($s->sender_iban)->toBe('')
        ->and($s->footer_text)->toBe('')
        ->and($s->default_rate_cents)->toBe(12000)
        ->and($s->number_seed_year)->toBe(2026)
        ->and($s->number_seed_next)->toBe(24);
});

test('settings page requires an MFA-satisfied user and renders', function () {
    actingAs(User::factory()->create())->get('/settings')->assertRedirect();

    actingAs(User::factory()->withMfa()->create())->get('/settings')->assertOk();
});

test('settings page saves values', function () {
    actingAs(User::factory()->withMfa()->create());

    Livewire::test(ManageBillingSettings::class)
        ->assertFormSet(['default_rate' => '120.00'])
        ->fillForm([
            'default_rate' => '135.00',
            'sender_iban' => 'ch93 0076 2011 6238 5295 7',
            'sender_city' => 'Fribourg',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $s = app(BillingSettings::class);
    expect($s->default_rate_cents)->toBe(13500)
        ->and($s->sender_iban)->toBe('CH9300762011623852957')
        ->and($s->sender_city)->toBe('Fribourg');
});

test('rate conversion is float-safe', function (string $input, int $cents) {
    actingAs(User::factory()->withMfa()->create());

    Livewire::test(ManageBillingSettings::class)
        ->fillForm(['default_rate' => $input])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(BillingSettings::class)->default_rate_cents)->toBe($cents);
})->with([['135.00', 13500], ['0.29', 29], ['19.99', 1999], ['120', 12000], ['99.9', 9990]]);

test('invalid rate is rejected', function (string $input) {
    actingAs(User::factory()->withMfa()->create());

    Livewire::test(ManageBillingSettings::class)
        ->fillForm(['default_rate' => $input])
        ->call('save')
        ->assertHasFormErrors(['default_rate']);
})->with(['abc', '-5', '12.345', '']);

test('empty IBAN is allowed', function () {
    actingAs(User::factory()->withMfa()->create());

    Livewire::test(ManageBillingSettings::class)
        ->fillForm(['sender_iban' => ''])
        ->call('save')
        ->assertHasNoFormErrors();
});

test('invalid IBANs are rejected', function (string $iban) {
    actingAs(User::factory()->withMfa()->create());

    Livewire::test(ManageBillingSettings::class)
        ->fillForm(['sender_iban' => $iban])
        ->call('save')
        ->assertHasFormErrors(['sender_iban']);
})->with([
    'too short' => 'CH00 123',
    'bad checksum' => 'CH9400762011623852957',
    'foreign country' => 'DE89 3704 0044 0532 0130 00',
]);

test('LI IBAN is accepted', function () {
    actingAs(User::factory()->withMfa()->create());

    Livewire::test(ManageBillingSettings::class)
        ->fillForm(['sender_iban' => 'LI21 0881 0000 2324 013A A'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(BillingSettings::class)->sender_iban)->toBe('LI21088100002324013AA');
});

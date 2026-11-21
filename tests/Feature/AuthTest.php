<?php

use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\get;

test('guest is redirected to login', function () {
    get('/')->assertRedirect('/login');
});

test('registration is disabled', function () {
    get('/register')->assertNotFound();
});

test('create-user command creates the single user', function () {
    artisan('billing:create-user', ['email' => 't@example.ch', 'name' => 'Thomas'])
        ->expectsQuestion('Password', 'secret-password-1')
        ->assertSuccessful();

    expect(User::count())->toBe(1);
    expect(User::first()->email)->toBe('t@example.ch');

    artisan('billing:create-user', ['email' => 'other@example.ch', 'name' => 'Other'])
        ->assertFailed();

    expect(User::count())->toBe(1);
});

test('user without 2FA is forced to set it up', function () {
    $user = User::factory()->create();

    $response = actingAs($user)->get('/')->assertRedirect();

    expect($response->headers->get('Location'))->toContain('multi-factor-authentication');
});

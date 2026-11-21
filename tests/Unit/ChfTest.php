<?php

use App\Support\Chf;

test('chf strings convert to cents, including negatives', function (string $chf, int $cents) {
    expect(Chf::toCents($chf))->toBe($cents);
})->with([
    ['12.50', 1250],
    ['12.5', 1250],
    ['12', 1200],
    ['0.05', 5],
    ['-12.50', -1250],
    ['-0.50', -50],
    ['-0.05', -5],
    [' -3 ', -300],
]);

test('cents convert to chf strings, including negatives', function (int $cents, string $chf) {
    expect(Chf::fromCents($cents))->toBe($chf);
})->with([
    [1250, '12.50'],
    [5, '0.05'],
    [0, '0.00'],
    [-1250, '-12.50'],
    [-50, '-0.50'],
    [-5, '-0.05'],
]);

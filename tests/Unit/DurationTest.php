<?php

use App\Domain\Time\Duration;
use Carbon\CarbonImmutable;

test('duration rounds up to 15 minute steps with a minimum of 15', function (int $minutes, int $expected) {
    expect(Duration::roundUp($minutes))->toBe($expected);
})->with([
    [1, 15],
    [15, 15],
    [16, 30],
    [20, 30],
    [45, 45],
    [50, 60],
    [0, 15],
]);

test('floorToFiveMinutes floors to the previous 5 minute mark', function (string $time, string $expected) {
    $floored = Duration::floorToFiveMinutes(CarbonImmutable::parse("2026-11-21 $time"));

    expect($floored->format('H:i:s'))->toBe($expected);
})->with([
    ['09:00:00', '09:00:00'],
    ['09:03:10', '09:00:00'],
    ['09:05:00', '09:05:00'],
    ['09:08:59', '09:05:00'],
    ['23:59:59', '23:55:00'],
]);

test('floorToFiveMinutes accepts a mutable Carbon and returns an immutable one', function () {
    expect(Duration::floorToFiveMinutes(Carbon\Carbon::parse('2026-11-21 10:14:00')))
        ->toBeInstanceOf(CarbonImmutable::class)
        ->format('H:i')->toBe('10:10');
});

<?php

use App\Domain\Time\Duration;

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

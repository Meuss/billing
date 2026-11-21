<?php

namespace App\Domain\Time;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class Duration
{
    public const STEP_MINUTES = 15;

    public const CLOCK_STEP_MINUTES = 5;

    /** Ceil to a multiple of 15 minutes, minimum 15. */
    public static function roundUp(int $minutes): int
    {
        return max(self::STEP_MINUTES, (int) ceil($minutes / self::STEP_MINUTES) * self::STEP_MINUTES);
    }

    /** Floor to the previous 5-minute mark (seconds dropped); never moves a time into the future. */
    public static function floorToFiveMinutes(CarbonInterface $time): CarbonImmutable
    {
        $time = CarbonImmutable::instance($time)->startOfMinute();

        return $time->subMinutes($time->minute % self::CLOCK_STEP_MINUTES);
    }
}

<?php

namespace App\Domain\Time;

class Duration
{
    public const STEP_MINUTES = 15;

    /** Ceil to a multiple of 15 minutes, minimum 15. */
    public static function roundUp(int $minutes): int
    {
        return max(self::STEP_MINUTES, (int) ceil($minutes / self::STEP_MINUTES) * self::STEP_MINUTES);
    }
}

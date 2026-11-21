<?php

namespace App\Domain\Invoicing;

use App\Support\Chf;
use InvalidArgumentException;

/** Integer-only money arithmetic; every rounding is half away from zero ("half up") to the centime. */
final class Money
{
    public static function divideRounded(int $numerator, int $denominator): int
    {
        if ($denominator <= 0) {
            throw new InvalidArgumentException('The denominator must be positive.');
        }
        $rounded = intdiv(2 * abs($numerator) + $denominator, 2 * $denominator);

        return $numerator < 0 ? -$rounded : $rounded;
    }

    /** Minutes at an hourly rate. */
    public static function forMinutes(int $minutes, int $hourlyRateCents): int
    {
        return self::divideRounded($minutes * $hourlyRateCents, 60);
    }

    /** A decimal quantity ("1.5") times a unit price. */
    public static function forQuantity(string $quantity, int $unitPriceCents): int
    {
        return self::divideRounded(self::hundredths($quantity) * $unitPriceCents, 100);
    }

    public static function percentOf(int $cents, int $basisPoints): int
    {
        return self::divideRounded($cents * $basisPoints, 10000);
    }

    public static function toNearestFiveCents(int $cents): int
    {
        return self::divideRounded($cents, 5) * 5;
    }

    public static function isQuantity(string $quantity): bool
    {
        return preg_match('/^-?\d{1,8}(\.\d{1,2})?$/', trim($quantity)) === 1;
    }

    /** "1.5" → 150. */
    public static function hundredths(string $quantity): int
    {
        if (! self::isQuantity($quantity)) {
            throw new InvalidArgumentException("Invalid quantity [$quantity].");
        }

        return Chf::toCents($quantity);
    }
}

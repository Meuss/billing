<?php

namespace App\Enums;

enum DiscountType: string
{
    /** Value in basis points: 10% = 1000. */
    case Percent = 'percent';
    /** Value in centimes. */
    case Amount = 'amount';

    public function label(): string
    {
        return match ($this) {
            self::Percent => 'Percentage',
            self::Amount => 'Fixed amount',
        };
    }
}

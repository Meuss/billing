<?php

namespace App\Support;

class Chf
{
    public static function toCents(string $chf): int
    {
        [$whole, $fraction] = array_pad(explode('.', trim($chf), 2), 2, '');

        return ((int) $whole) * 100 + (int) str_pad($fraction, 2, '0');
    }

    public static function fromCents(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}

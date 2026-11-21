<?php

namespace App\Support;

class Chf
{
    /** "12.50" → 1250, "-0.5" → -50. */
    public static function toCents(string $chf): int
    {
        $chf = trim($chf);
        $negative = str_starts_with($chf, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($chf, '-+'), 2), 2, '');
        $cents = ((int) $whole) * 100 + (int) str_pad($fraction, 2, '0');

        return $negative ? -$cents : $cents;
    }

    /** 1250 → "12.50", -50 → "-0.50". */
    public static function fromCents(int $cents): string
    {
        $abs = abs($cents);

        return ($cents < 0 ? '-' : '').intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Swiss display format: 123450 → "CHF 1'234.50", -1250 → "CHF -12.50". */
    public static function format(int $cents): string
    {
        $abs = abs($cents);

        return 'CHF '.($cents < 0 ? '-' : '').number_format(intdiv($abs, 100), 0, '', "'").'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}

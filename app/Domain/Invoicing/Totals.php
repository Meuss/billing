<?php

namespace App\Domain\Invoicing;

final readonly class Totals
{
    public function __construct(
        public int $subtotalCents,
        public int $discountCents,
        public int $roundingCents,
        public int $totalCents,
    ) {}
}

<?php

namespace App\Enums;

enum ExpenseStatus: string
{
    case Unbilled = 'unbilled';
    case Billed = 'billed';

    public function label(): string
    {
        return match ($this) {
            self::Unbilled => 'Unbilled',
            self::Billed => 'Billed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Unbilled => 'warning',
            self::Billed => 'info',
        };
    }
}

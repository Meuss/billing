<?php

namespace App\Enums;

enum Language: string
{
    case Fr = 'fr';
    case En = 'en';

    public function label(): string
    {
        return match ($this) {
            self::Fr => 'Français',
            self::En => 'English',
        };
    }
}

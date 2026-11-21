<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Billed = 'billed';

    public function label(): string
    {
        return match ($this) {
            self::Todo => 'To do',
            self::InProgress => 'In progress',
            self::Done => 'Done',
            self::Billed => 'Billed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Todo => 'gray',
            self::InProgress => 'warning',
            self::Done => 'success',
            self::Billed => 'info',
        };
    }

    /** Statuses the freelancer may set by hand; Billed is only set by invoicing. @return array<string, string> */
    public static function manualOptions(): array
    {
        return collect([self::Todo, self::InProgress, self::Done])
            ->mapWithKeys(fn (self $status) => [$status->value => $status->label()])
            ->all();
    }
}

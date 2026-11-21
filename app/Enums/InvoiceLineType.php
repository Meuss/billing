<?php

namespace App\Enums;

enum InvoiceLineType: string
{
    case Task = 'task';
    case Expense = 'expense';
    case Item = 'item';

    public function label(): string
    {
        return match ($this) {
            self::Task => 'Task',
            self::Expense => 'Expense',
            self::Item => 'Item',
        };
    }
}

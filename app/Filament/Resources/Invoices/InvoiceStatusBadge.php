<?php

namespace App\Filament\Resources\Invoices;

use App\Enums\InvoiceStatus;

/** Label and colour of Invoice::displayStatus(), which adds the derived "overdue" to the stored statuses. */
class InvoiceStatusBadge
{
    public const OVERDUE = 'overdue';

    /** @return array<string, string> value => label, in lifecycle order */
    public static function options(): array
    {
        return [
            InvoiceStatus::Draft->value => InvoiceStatus::Draft->label(),
            InvoiceStatus::Sent->value => InvoiceStatus::Sent->label(),
            self::OVERDUE => 'Overdue',
            InvoiceStatus::Paid->value => InvoiceStatus::Paid->label(),
            InvoiceStatus::Cancelled->value => InvoiceStatus::Cancelled->label(),
        ];
    }

    public static function label(string $status): string
    {
        return self::options()[$status];
    }

    public static function color(string $status): string
    {
        return $status === self::OVERDUE ? 'warning' : InvoiceStatus::from($status)->color();
    }
}

<?php

namespace App\Filament\Widgets;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Support\Chf;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class RevenueThisYear extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $year = today()->year;

        return [
            Stat::make("Revenue paid in {$year}", Chf::format(self::paidThisYearCents())),
        ];
    }

    public static function paidThisYearCents(): int
    {
        return (int) Invoice::query()
            ->where('status', InvoiceStatus::Paid)
            ->whereBetween('paid_on', [today()->startOfYear(), today()->endOfYear()])
            ->sum('total_cents');
    }
}

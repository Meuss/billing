<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceActions;
use App\Filament\Resources\Invoices\InvoiceResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Attributes\On;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            ...InvoiceActions::draft(),
            ...InvoiceActions::issued(),
        ];
    }

    #[On('invoice-lines-updated')]
    public function refreshTotals(): void {}
}

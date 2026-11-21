<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Domain\Invoicing\InvoiceService;
use App\Filament\Resources\Invoices\InvoiceActions;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Invoices\Schemas\InvoiceForm;
use App\Models\Invoice;
use DomainException;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;

/** The draft workspace: note, discount, live totals, and the lines relation manager below. */
class EditInvoice extends EditRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            InvoiceActions::previewPdf(),
            // Sending freezes the invoice: save the form first so unsaved discount/note edits (already shown in
            // the live totals) are issued too. A validation error or refused save halts the send.
            InvoiceActions::markSent()->before(fn () => $this->save(shouldRedirect: false, shouldSendSavedNotification: false)),
            InvoiceActions::deleteDraft(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return InvoiceForm::fillDiscount($data);
    }

    /** @param Invoice $record */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        [$type, $value] = InvoiceForm::discountFromForm($data);

        try {
            DB::transaction(function () use ($record, $data, $type, $value): void {
                app(InvoiceService::class)->setDiscount($record, $type, $value);
                $record->update(['note' => $data['note'] ?? null]);
            });
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $this->halt();
        }

        return $record;
    }

    /** The lines relation manager changed the lines: re-render so the totals follow. */
    #[On('invoice-lines-updated')]
    public function refreshTotals(): void {}
}

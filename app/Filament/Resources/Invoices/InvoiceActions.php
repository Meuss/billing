<?php

namespace App\Filament\Resources\Invoices;

use App\Domain\Invoicing\InvoiceService;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Carbon\CarbonImmutable;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/** Header actions for the Invoice lifecycle; each shows only in the states where it applies. */
class InvoiceActions
{
    /** @return array<Action> */
    public static function draft(): array
    {
        return [static::previewPdf(), static::markSent(), static::deleteDraft()];
    }

    /** @return array<Action> */
    public static function issued(): array
    {
        return [static::downloadPdf(), static::markPaid(), static::cancel()];
    }

    public static function previewPdf(): Action
    {
        return Action::make('previewPdf')
            ->label('Preview PDF')
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->visible(fn (Invoice $record): bool => $record->isDraft())
            ->url(fn (Invoice $record): string => route('invoices.pdf', $record), shouldOpenInNewTab: true);
    }

    public static function downloadPdf(): Action
    {
        return Action::make('downloadPdf')
            ->label('Download PDF')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->visible(fn (Invoice $record): bool => ! $record->isDraft() && $record->pdf_path !== null)
            ->url(fn (Invoice $record): string => route('invoices.pdf', $record), shouldOpenInNewTab: true);
    }

    public static function markSent(): Action
    {
        return Action::make('markSent')
            ->label('Mark as sent')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->visible(fn (Invoice $record): bool => $record->isDraft())
            ->modalDescription('Assigns the invoice number, freezes the invoice and stores its PDF.')
            ->schema([
                DatePicker::make('sent_on')->label('Sent date')->default(fn () => today())->maxDate(fn () => today())->required(),
            ])
            ->action(fn (Invoice $record, array $data) => static::run(
                fn (InvoiceService $invoices) => $invoices->markSent($record, CarbonImmutable::parse($data['sent_on'])),
                'Invoice sent',
                $record,
            ));
    }

    public static function markPaid(): Action
    {
        return Action::make('markPaid')
            ->label('Mark as paid')
            ->icon(Heroicon::OutlinedBanknotes)
            ->visible(fn (Invoice $record): bool => $record->status === InvoiceStatus::Sent)
            ->schema([
                DatePicker::make('paid_on')->label('Payment date')->default(fn () => today())->required(),
            ])
            ->action(fn (Invoice $record, array $data) => static::run(
                fn (InvoiceService $invoices) => $invoices->markPaid($record, CarbonImmutable::parse($data['paid_on'])),
                'Invoice marked as paid',
                $record,
            ));
    }

    public static function cancel(): Action
    {
        return Action::make('cancelInvoice')
            ->label('Cancel invoice')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Invoice $record): bool => $record->status === InvoiceStatus::Sent)
            ->requiresConfirmation()
            ->modalHeading('Cancel invoice')
            ->modalDescription('The number and PDF are kept; its tasks and expenses become available to invoice again.')
            ->modalSubmitActionLabel('Cancel invoice')
            ->action(fn (Invoice $record) => static::run(
                fn (InvoiceService $invoices) => $invoices->cancel($record, CarbonImmutable::today()),
                'Invoice cancelled',
                $record,
            ));
    }

    public static function deleteDraft(): Action
    {
        return Action::make('deleteDraft')
            ->label('Delete draft')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->visible(fn (Invoice $record): bool => $record->isDraft())
            ->requiresConfirmation()
            ->modalDescription('Its tasks and expenses become available to invoice again.')
            ->action(function (Invoice $record) {
                try {
                    app(InvoiceService::class)->deleteDraft($record);
                } catch (DomainException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return null;
                }
                Notification::make()->success()->title('Draft deleted')->send();

                return redirect(InvoiceResource::getUrl('index'));
            });
    }

    /** Runs a lifecycle change; a refused one becomes a danger notification, a done one reloads the invoice page. */
    private static function run(\Closure $change, string $done, Invoice $record): mixed
    {
        try {
            $change(app(InvoiceService::class));
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return null;
        }
        Notification::make()->success()->title($done)->send();

        return redirect(InvoiceResource::getUrl('view', ['record' => $record]));
    }
}

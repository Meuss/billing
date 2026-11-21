<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Domain\Invoicing\InvoiceService;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Client;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/** A new Invoice starts as an empty draft for an active Client; lines are added on its edit page. */
class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

    protected static bool $canCreateAnother = false;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('client_id')
                ->label('Client')
                ->options(fn (): array => Client::query()->active()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->required(),
        ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        return app(InvoiceService::class)->createDraft(Client::query()->active()->findOrFail($data['client_id']));
    }

    protected function getRedirectUrl(): string
    {
        return InvoiceResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}

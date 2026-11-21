<?php

use App\Domain\Invoicing\InvoiceNumberGenerator;
use App\Domain\Invoicing\InvoiceService;
use App\Models\Client;
use App\Models\Invoice;
use App\Settings\BillingSettings;
use Carbon\CarbonImmutable;

function sendNew(string $sentOn): Invoice
{
    $service = app(InvoiceService::class);
    $invoice = $service->createDraft(Client::factory()->create());
    $service->addItem($invoice, 'Work', '1', 10000);
    $service->markSent($invoice, CarbonImmutable::parse($sentOn));

    return $invoice->fresh();
}

test('2026 numbering continues from the seed', function () {
    expect(sendNew('2026-11-02')->number)->toBe('2026-024')
        ->and(sendNew('2026-11-03')->number)->toBe('2026-025');
});

test('first invoice of 2027 restarts at 001', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-30 10:00'));
    sendNew('2026-12-30');
    $this->travelTo(CarbonImmutable::parse('2027-01-05 10:00'));

    expect(sendNew('2027-01-04')->number)->toBe('2027-001')
        ->and(sendNew('2027-01-05')->number)->toBe('2027-002');
});

test('the seed only applies while no invoice of that year is numbered', function () {
    sendNew('2026-11-02');
    $settings = app(BillingSettings::class);
    $settings->number_seed_next = 5;
    $settings->save();

    expect(app(InvoiceNumberGenerator::class)->next(2026))->toBe('2026-025')
        ->and(app(InvoiceNumberGenerator::class)->next(2025))->toBe('2025-001');
});

test('deleting a draft never consumes a number', function () {
    $service = app(InvoiceService::class);
    $draft = $service->createDraft(Client::factory()->create());
    $service->addItem($draft, 'Work', '1', 10000);
    $service->deleteDraft($draft);

    expect(sendNew('2026-11-02')->number)->toBe('2026-024');
});

test('a sent date earlier than another invoice is refused', function () {
    sendNew('2026-11-10');

    expect(fn () => sendNew('2026-11-09'))->toThrow(DomainException::class);
    expect(sendNew('2026-11-10')->number)->toBe('2026-025');
});

test('due date is sent date plus 30 days', function () {
    expect(sendNew('2026-11-20')->due_on->toDateString())->toBe('2026-12-20')
        ->and(sendNew('2026-11-21')->due_on->toDateString())->toBe('2026-12-21');
});

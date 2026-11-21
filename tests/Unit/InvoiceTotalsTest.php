<?php

use App\Domain\Invoicing\InvoiceTotals;
use App\Enums\DiscountType;
use App\Enums\InvoiceLineType;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Task;
use App\Models\TimeEntry;

/** An Invoice held in memory with the given lines; nothing touches the database. */
function memoryInvoice(array $lines, ?DiscountType $type = null, ?int $value = null, InvoiceStatus $status = InvoiceStatus::Draft): Invoice
{
    $invoice = (new Invoice)->forceFill(['status' => $status, 'discount_type' => $type, 'discount_value' => $value]);
    foreach ($lines as $line) {
        $line->setRelation('invoice', $invoice);
    }

    return $invoice->setRelation('lines', collect($lines));
}

function memoryItemLine(string $quantity, int $unitPriceCents): InvoiceLine
{
    return (new InvoiceLine)->forceFill(['type' => InvoiceLineType::Item, 'quantity' => $quantity, 'unit_price_cents' => $unitPriceCents]);
}

function memoryTaskLine(int $minutes, int $rateCents): InvoiceLine
{
    $task = (new Task)->setRelation('timeEntries', collect([(new TimeEntry)->forceFill(['duration_minutes' => $minutes])]));

    return (new InvoiceLine)->forceFill(['type' => InvoiceLineType::Task, 'unit_price_cents' => $rateCents])->setRelation('task', $task);
}

test('totals round to the nearest 5 centimes', function (int $subtotal, int $rounding, int $total) {
    $totals = InvoiceTotals::for(memoryInvoice([memoryItemLine('1', $subtotal)]));

    expect($totals->subtotalCents)->toBe($subtotal)
        ->and($totals->discountCents)->toBe(0)
        ->and($totals->roundingCents)->toBe($rounding)
        ->and($totals->totalCents)->toBe($total);
})->with([
    [12345, 0, 12345],
    [12347, -2, 12345],
    [12348, 2, 12350],
]);

test('percent discount rounds half up to centimes', function () {
    $totals = InvoiceTotals::for(memoryInvoice([memoryItemLine('1', 12345)], DiscountType::Percent, 1000));

    expect($totals->discountCents)->toBe(1235)
        ->and($totals->roundingCents)->toBe(0)
        ->and($totals->totalCents)->toBe(11110);
});

test('amount discount larger than the subtotal yields a negative total', function () {
    $totals = InvoiceTotals::for(memoryInvoice([memoryItemLine('1', 12000)], DiscountType::Amount, 20000));

    expect($totals->subtotalCents)->toBe(12000)
        ->and($totals->discountCents)->toBe(20000)
        ->and($totals->totalCents)->toBe(-8000);
});

test('task line amount is minutes times hourly rate, half up', function (int $minutes, int $rate, int $amount) {
    $line = memoryTaskLine($minutes, $rate);
    memoryInvoice([$line]);

    expect($line->amountCents())->toBe($amount);
})->with([
    [50, 12000, 10000],
    [20, 10000, 3333],
    [10, 12030, 2005], // 2005.0
    [1, 90, 2], // 1.5 → 2
]);

test('a sent task line uses its frozen minutes, not the live time entries', function () {
    $line = memoryTaskLine(600, 12000)->forceFill(['quantity_minutes' => 60]);
    memoryInvoice([$line], status: InvoiceStatus::Sent);

    expect($line->amountCents())->toBe(12000);
});

test('item lines may be negative and round half away from zero', function (string $quantity, int $price, int $amount) {
    expect(memoryItemLine($quantity, $price)->amountCents())->toBe($amount);
})->with([
    ['1', -1250, -1250],
    ['1.5', 333, 500],
    ['-1.5', 333, -500],
    ['1.5', -333, -500],
    ['0.25', 1000, 250],
]);

test('expense line amount is its unit price', function () {
    $line = (new InvoiceLine)->forceFill(['type' => InvoiceLineType::Expense, 'unit_price_cents' => 4999]);

    expect($line->amountCents())->toBe(4999);
});

test('subtotal sums mixed lines including negative items', function () {
    $totals = InvoiceTotals::for(memoryInvoice([memoryTaskLine(60, 12000), memoryItemLine('1', -2001)]));

    expect($totals->subtotalCents)->toBe(9999)
        ->and($totals->roundingCents)->toBe(1)
        ->and($totals->totalCents)->toBe(10000);
});

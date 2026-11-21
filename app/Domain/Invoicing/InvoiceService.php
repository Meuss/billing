<?php

namespace App\Domain\Invoicing;

use App\Enums\DiscountType;
use App\Enums\ExpenseStatus;
use App\Enums\InvoiceLineType;
use App\Enums\InvoiceStatus;
use App\Enums\TaskStatus;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Task;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The Invoice lifecycle. Every method runs in one transaction and throws DomainException on a rule violation.
 * Writes always go through model instances so model guards and hooks fire.
 */
class InvoiceService
{
    public const PAYMENT_TERM_DAYS = 30;

    public function __construct(private InvoiceNumberGenerator $numbers) {}

    public function createDraft(Client $client): Invoice
    {
        return DB::transaction(fn (): Invoice => Invoice::create([
            'client_id' => $client->getKey(),
            'language' => $client->language,
        ]));
    }

    public function addTask(Invoice $invoice, Task $task): InvoiceLine
    {
        return DB::transaction(function () use ($invoice, $task): InvoiceLine {
            $this->lock($invoice, InvoiceStatus::Draft);
            $this->lockRow($task);

            if ($task->project->client_id !== $invoice->client_id) {
                throw new DomainException('The task belongs to another client.');
            }
            if ($task->status === TaskStatus::Billed) {
                throw new DomainException('The task is already billed.');
            }

            $line = $this->createLine($invoice, [
                'type' => InvoiceLineType::Task,
                'task_id' => $task->getKey(),
                'project_id' => $task->project_id,
                'description' => $task->name,
                'unit_price_cents' => $task->project->effectiveRateCents(),
            ]);
            $task->update(['status' => TaskStatus::Billed]);

            return $line;
        });
    }

    public function addExpense(Invoice $invoice, Expense $expense): InvoiceLine
    {
        return DB::transaction(function () use ($invoice, $expense): InvoiceLine {
            $this->lock($invoice, InvoiceStatus::Draft);
            $this->lockRow($expense);

            if ($expense->client_id !== $invoice->client_id) {
                throw new DomainException('The expense belongs to another client.');
            }
            if ($expense->status === ExpenseStatus::Billed) {
                throw new DomainException('The expense is already billed.');
            }

            $line = $this->createLine($invoice, [
                'type' => InvoiceLineType::Expense,
                'expense_id' => $expense->getKey(),
                'project_id' => $expense->project_id,
                'description' => $expense->description,
                'unit_price_cents' => $expense->billed_amount_cents,
            ]);
            $expense->update(['status' => ExpenseStatus::Billed]);

            return $line;
        });
    }

    /** $quantity is a decimal string with up to 2 decimals, not zero; prices may be negative. */
    public function addItem(Invoice $invoice, string $description, string $quantity, int $unitPriceCents): InvoiceLine
    {
        return DB::transaction(function () use ($invoice, $description, $quantity, $unitPriceCents): InvoiceLine {
            $this->lock($invoice, InvoiceStatus::Draft);

            if (trim($description) === '') {
                throw new DomainException('An item needs a description.');
            }
            if (! Money::isQuantity($quantity) || Money::hundredths($quantity) === 0) {
                throw new DomainException('The quantity must be a non-zero number with at most 2 decimals.');
            }

            return $this->createLine($invoice, [
                'type' => InvoiceLineType::Item,
                'description' => trim($description),
                'quantity' => trim($quantity),
                'unit_price_cents' => $unitPriceCents,
            ]);
        });
    }

    public function removeLine(InvoiceLine $line): void
    {
        DB::transaction(function () use ($line): void {
            $this->lock($line->invoice, InvoiceStatus::Draft);
            $this->release($line);
            $line->delete();
        });
    }

    /** Both null clears the Discount. Percent is in basis points (10% = 1000), Amount in centimes. */
    public function setDiscount(Invoice $invoice, ?DiscountType $type, ?int $value): void
    {
        DB::transaction(function () use ($invoice, $type, $value): void {
            $this->lock($invoice, InvoiceStatus::Draft);

            $valid = match ($type) {
                null => $value === null,
                DiscountType::Percent => $value !== null && $value > 0 && $value <= 10000,
                DiscountType::Amount => $value !== null && $value > 0,
            };
            if (! $valid) {
                throw new DomainException('Invalid discount: a percentage between 0 and 100, or a positive amount.');
            }

            $invoice->forceFill(['discount_type' => $type, 'discount_value' => $value])->save();
        });
    }

    public function deleteDraft(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice): void {
            $this->lock($invoice, InvoiceStatus::Draft);
            foreach ($invoice->lines()->get() as $line) {
                $this->release($line);
                $line->delete();
            }
            $invoice->delete();
        });
    }

    public function markSent(Invoice $invoice, CarbonImmutable $sentOn): void
    {
        DB::transaction(function () use ($invoice, $sentOn): void {
            $this->lock($invoice, InvoiceStatus::Draft);
            $sentOn = $sentOn->startOfDay();

            if ($sentOn->greaterThan(today())) {
                throw new DomainException('The sent date cannot be in the future.');
            }
            $latest = Invoice::query()->whereKeyNot($invoice->getKey())->whereNotNull('sent_on')->max('sent_on');
            if ($latest !== null && $sentOn->lessThan(CarbonImmutable::parse($latest))) {
                throw new DomainException('The sent date cannot be earlier than '.CarbonImmutable::parse($latest)->toDateString().', the latest sent date.');
            }

            $lines = $invoice->lines()->with('task.timeEntries')->get();
            if ($lines->isEmpty()) {
                throw new DomainException('An invoice needs at least one line.');
            }
            $invoice->setRelation('lines', $lines);
            $totals = InvoiceTotals::for($invoice);
            if ($totals->totalCents < 0) {
                throw new DomainException('The invoice total cannot be negative.');
            }

            foreach ($lines->where('type', InvoiceLineType::Task) as $line) {
                $line->update(['quantity_minutes' => $line->task->totalMinutes()]);
            }

            $invoice->forceFill([
                'status' => InvoiceStatus::Sent,
                'number' => $this->numbers->next($sentOn->year),
                'sent_on' => $sentOn,
                'due_on' => $sentOn->addDays(self::PAYMENT_TERM_DAYS),
                'total_cents' => $totals->totalCents,
                'language' => $invoice->client->language,
            ])->save();
        });
    }

    public function markPaid(Invoice $invoice, CarbonImmutable $paidOn): void
    {
        DB::transaction(function () use ($invoice, $paidOn): void {
            $this->lock($invoice, InvoiceStatus::Sent);
            $this->assertNotBeforeSent($invoice, $paidOn);

            $invoice->forceFill(['status' => InvoiceStatus::Paid, 'paid_on' => $paidOn->startOfDay()])->save();
        });
    }

    /** Keeps the number, lines and PDF; the Tasks and Expenses become available to invoice again. */
    public function cancel(Invoice $invoice, CarbonImmutable $on): void
    {
        DB::transaction(function () use ($invoice, $on): void {
            $this->lock($invoice, InvoiceStatus::Sent);
            $this->assertNotBeforeSent($invoice, $on);

            foreach ($invoice->lines()->get() as $line) {
                $this->release($line);
                $line->update(['released' => true]);
            }
            $invoice->forceFill(['status' => InvoiceStatus::Cancelled, 'cancelled_on' => $on->startOfDay()])->save();
        });
    }

    private function createLine(Invoice $invoice, array $attributes): InvoiceLine
    {
        return $invoice->lines()->create([
            ...$attributes,
            'position' => (int) $invoice->lines()->max('position') + 1,
        ]);
    }

    /** Returns the Task to done or the Expense to unbilled. */
    private function release(InvoiceLine $line): void
    {
        match ($line->type) {
            InvoiceLineType::Task => $line->task->update(['status' => TaskStatus::Done]),
            InvoiceLineType::Expense => $line->expense->update(['status' => ExpenseStatus::Unbilled]),
            InvoiceLineType::Item => null,
        };
    }

    /** Locks the invoice row, reloads it and checks its status. */
    private function lock(Invoice $invoice, InvoiceStatus $expected): void
    {
        $this->lockRow($invoice);

        if ($invoice->status !== $expected) {
            throw new DomainException("This action needs a {$expected->value} invoice; this one is {$invoice->status->value}.");
        }
    }

    private function lockRow(Model $model): void
    {
        $model->newQuery()->whereKey($model->getKey())->lockForUpdate()->firstOrFail();
        $model->refresh();
    }

    private function assertNotBeforeSent(Invoice $invoice, CarbonImmutable $date): void
    {
        if ($date->startOfDay()->lessThan($invoice->sent_on)) {
            throw new DomainException('The date cannot be earlier than the sent date.');
        }
    }
}

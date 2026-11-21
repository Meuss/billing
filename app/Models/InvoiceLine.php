<?php

namespace App\Models;

use App\Domain\Invoicing\Money;
use App\Enums\ExpenseStatus;
use App\Enums\InvoiceLineType;
use App\Enums\InvoiceStatus;
use App\Enums\TaskStatus;
use Database\Factories\InvoiceLineFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lines are created and removed through App\Domain\Invoicing\InvoiceService. */
#[Fillable(['invoice_id', 'type', 'task_id', 'expense_id', 'project_id', 'description', 'quantity_minutes', 'quantity', 'unit_price_cents', 'position', 'released'])]
class InvoiceLine extends Model
{
    /** @use HasFactory<InvoiceLineFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => InvoiceLineType::class,
            'quantity_minutes' => 'integer',
            'quantity' => 'decimal:2',
            'unit_price_cents' => 'integer',
            'position' => 'integer',
            'released' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Lines of an issued invoice are frozen; cancelling only marks them released.
        static::saving(function (InvoiceLine $line): void {
            if (! $line->invoiceIsDraft() && array_keys($line->getDirty()) !== ['released']) {
                throw new DomainException('Only lines of a draft invoice can be changed.');
            }
        });

        // Every delete path gives the Task/Expense back, not only InvoiceService.
        static::deleting(function (InvoiceLine $line): void {
            if (! $line->invoiceIsDraft()) {
                throw new DomainException('Only lines of a draft invoice can be removed.');
            }
            $line->releaseSource();
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** Task lines: live Time Entry total while draft, the frozen total once sent. */
    public function billableMinutes(): ?int
    {
        if ($this->type !== InvoiceLineType::Task) {
            return null;
        }

        return $this->invoiceIsDraft() ? $this->task->totalMinutes() : (int) $this->quantity_minutes;
    }

    public function amountCents(): int
    {
        return match ($this->type) {
            InvoiceLineType::Task => Money::forMinutes($this->billableMinutes(), $this->unit_price_cents),
            InvoiceLineType::Expense => $this->unit_price_cents,
            InvoiceLineType::Item => Money::forQuantity((string) $this->quantity, $this->unit_price_cents),
        };
    }

    /** Returns the Task to done or the Expense to unbilled. */
    public function releaseSource(): void
    {
        match ($this->type) {
            InvoiceLineType::Task => $this->task->update(['status' => TaskStatus::Done]),
            InvoiceLineType::Expense => $this->expense->update(['status' => ExpenseStatus::Unbilled]),
            InvoiceLineType::Item => null,
        };
    }

    private function invoiceIsDraft(): bool
    {
        return $this->invoice->status === InvoiceStatus::Draft;
    }
}

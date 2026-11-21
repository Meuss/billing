<?php

namespace App\Models;

use App\Enums\ExpenseStatus;
use Database\Factories\ExpenseFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

#[Fillable(['client_id', 'project_id', 'date', 'supplier', 'description', 'cost_cents', 'billed_amount_cents', 'original_amount_note', 'receipt_path', 'status'])]
class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use HasFactory;

    public const RECEIPT_DISK = 'local';

    public const RECEIPT_DIRECTORY = 'receipts';

    /** Fields frozen once an Expense is billed; status alone may still change. */
    private const LOCKED_WHEN_BILLED = ['client_id', 'project_id', 'date', 'supplier', 'description', 'cost_cents', 'billed_amount_cents', 'original_amount_note', 'receipt_path'];

    protected $attributes = ['status' => 'unbilled'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'cost_cents' => 'integer',
            'billed_amount_cents' => 'integer',
            'status' => ExpenseStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Expense $expense): void {
            // A billed amount that equalled the cost follows a corrected cost, unless it was changed explicitly too.
            if ($expense->exists
                && $expense->isDirty('cost_cents')
                && ! $expense->isDirty('billed_amount_cents')
                && $expense->getOriginal('billed_amount_cents') === $expense->getOriginal('cost_cents')) {
                $expense->billed_amount_cents = $expense->cost_cents;
            }
            if ($expense->billed_amount_cents === null) {
                $expense->billed_amount_cents = $expense->cost_cents;
            }
            if ($expense->project_id !== null && Project::whereKey($expense->project_id)->value('client_id') !== (int) $expense->client_id) {
                throw new DomainException('The project must belong to the expense client.');
            }
        });

        static::updating(function (Expense $expense): void {
            if ($expense->getOriginal('status') === ExpenseStatus::Billed && $expense->isDirty(self::LOCKED_WHEN_BILLED)) {
                throw new DomainException('A billed expense cannot be changed.');
            }
        });

        static::deleting(function (Expense $expense): void {
            if ($expense->status === ExpenseStatus::Billed) {
                throw new DomainException('A billed expense cannot be deleted.');
            }
            if (! $expense->canBeDeleted()) {
                throw new DomainException('An expense that appeared on an invoice cannot be deleted.');
            }
        });

        static::deleted(function (Expense $expense): void {
            if ($expense->receipt_path) {
                Storage::disk(self::RECEIPT_DISK)->delete($expense->receipt_path);
            }
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** The Invoice Line currently holding this Expense (lines of cancelled invoices are released). */
    public function invoiceLine(): HasOne
    {
        return $this->hasOne(InvoiceLine::class)->where('released', false);
    }

    /** False once any Invoice Line, released or not, references this Expense. */
    public function canBeDeleted(): bool
    {
        return $this->exists && ! $this->isBilled() && ! InvoiceLine::query()->where('expense_id', $this->getKey())->exists();
    }

    public function isBilled(): bool
    {
        return $this->status === ExpenseStatus::Billed;
    }

    /** A saved copy with the same details, dated today, unbilled and without receipt. */
    public function duplicate(): self
    {
        $copy = (new self)->forceFill([
            ...$this->only(['client_id', 'project_id', 'supplier', 'description', 'cost_cents', 'billed_amount_cents', 'original_amount_note']),
            'date' => today(),
            'status' => ExpenseStatus::Unbilled,
            'receipt_path' => null,
        ]);
        $copy->save();

        return $copy;
    }
}

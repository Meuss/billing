<?php

namespace App\Models;

use App\Domain\Invoicing\InvoiceTotals;
use App\Domain\Invoicing\Totals;
use App\Enums\DiscountType;
use App\Enums\InvoiceStatus;
use App\Enums\Language;
use Database\Factories\InvoiceFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Lifecycle changes go through App\Domain\Invoicing\InvoiceService. */
#[Fillable(['client_id', 'note', 'language'])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    /** Fields frozen once an Invoice leaves draft; status, payment/cancel dates and the PDF path may still change. */
    private const LOCKED_WHEN_ISSUED = ['client_id', 'number', 'note', 'discount_type', 'discount_value', 'sent_on', 'due_on', 'language', 'total_cents'];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'discount_type' => DiscountType::class,
            'discount_value' => 'integer',
            'sent_on' => 'date',
            'due_on' => 'date',
            'paid_on' => 'date',
            'cancelled_on' => 'date',
            'language' => Language::class,
            'total_cents' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Invoice $invoice): void {
            if ($invoice->getOriginal('status') !== InvoiceStatus::Draft && $invoice->isDirty(self::LOCKED_WHEN_ISSUED)) {
                throw new DomainException('Only a draft invoice can be changed.');
            }
        });

        static::deleting(function (Invoice $invoice): void {
            if ($invoice->getOriginal('status') !== InvoiceStatus::Draft) {
                throw new DomainException('Only a draft invoice can be deleted.');
            }
            // Lines go as models (not by FK cascade) so each releases its Task/Expense.
            $invoice->lines()->get()->each->delete();
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('position')->orderBy('id')->chaperone('invoice');
    }

    public function isDraft(): bool
    {
        return $this->status === InvoiceStatus::Draft;
    }

    public function isOverdue(): bool
    {
        return $this->status === InvoiceStatus::Sent && $this->due_on !== null && today()->greaterThan($this->due_on);
    }

    /** The stored status, or "overdue" for a sent Invoice past its Due Date. */
    public function displayStatus(): string
    {
        return $this->isOverdue() ? 'overdue' : $this->status->value;
    }

    public function totals(): Totals
    {
        return InvoiceTotals::for($this);
    }

    /** Sent and unpaid, overdue included. */
    public function scopeOpen(Builder $query): void
    {
        $query->where($this->qualifyColumn('status'), InvoiceStatus::Sent);
    }

    public function scopeOverdue(Builder $query): void
    {
        $query->open()->whereDate($this->qualifyColumn('due_on'), '<', today());
    }
}

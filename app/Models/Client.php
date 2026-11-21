<?php

namespace App\Models;

use App\Enums\Language;
use App\Models\Concerns\Archivable;
use Database\Factories\ClientFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'contact_person', 'address_line1', 'address_line2', 'postal_code', 'city', 'country',
    'email', 'phone', 'website', 'language', 'notes', 'invoice_ninja_id',
])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use Archivable, HasFactory;

    protected function casts(): array
    {
        return ['language' => Language::class];
    }

    protected static function booted(): void
    {
        // Projects, Tasks and Time Entries go by FK cascade; Expenses are deleted as models so their receipts go too.
        static::deleting(function (Client $client): void {
            if (! $client->canBeDeleted()) {
                throw new DomainException('A client with invoices cannot be deleted; archive it instead.');
            }
            $client->expenses()->get()->each->delete();
        });
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** False once any Invoice, or any Invoice Line via its Projects, Tasks or Expenses, references it. */
    public function canBeDeleted(): bool
    {
        if (! $this->exists) {
            return false;
        }

        return ! $this->invoices()->exists()
            && ! InvoiceLine::query()
                ->where(fn (Builder $lines) => $lines
                    ->whereIn('project_id', $this->projects()->select('id'))
                    ->orWhereIn('task_id', Task::query()->whereIn('project_id', $this->projects()->select('id'))->select('id'))
                    ->orWhereIn('expense_id', $this->expenses()->select('id')))
                ->exists();
    }
}

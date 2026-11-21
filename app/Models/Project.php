<?php

namespace App\Models;

use App\Models\Concerns\Archivable;
use App\Settings\BillingSettings;
use Database\Factories\ProjectFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['client_id', 'name', 'rate_cents', 'notes', 'invoice_ninja_id'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use Archivable, HasFactory;

    protected function casts(): array
    {
        return ['rate_cents' => 'integer'];
    }

    protected static function booted(): void
    {
        // Tasks and Time Entries go by FK cascade; Expenses keep their Client and lose the Project.
        static::deleting(function (Project $project): void {
            if (! $project->canBeDeleted()) {
                throw new DomainException('A project with invoiced work cannot be deleted; archive it instead.');
            }
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** Projects that may receive new work: neither the Project nor its Client is archived. */
    public function scopeSelectable(Builder $query): void
    {
        $query->active()->whereHas('client', fn (Builder $client) => $client->active());
    }

    /** False once any Invoice Line references it or one of its Tasks. */
    public function canBeDeleted(): bool
    {
        if (! $this->exists) {
            return false;
        }

        return ! InvoiceLine::query()
            ->where(fn (Builder $lines) => $lines
                ->where('project_id', $this->getKey())
                ->orWhereIn('task_id', $this->tasks()->select('id')))
            ->exists();
    }

    public function effectiveRateCents(): int
    {
        return $this->rate_cents ?? app(BillingSettings::class)->default_rate_cents;
    }
}

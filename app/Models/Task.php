<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Database\Factories\TaskFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['project_id', 'name', 'description', 'status'])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    protected $attributes = ['status' => 'todo'];

    protected function casts(): array
    {
        return ['status' => TaskStatus::class];
    }

    protected static function booted(): void
    {
        static::updating(function (Task $task): void {
            if ($task->getOriginal('status') === TaskStatus::Billed && $task->isDirty('project_id')) {
                throw new DomainException('A billed task cannot be moved to another project.');
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /** The Invoice Line currently holding this Task (lines of cancelled invoices are released). */
    public function invoiceLine(): HasOne
    {
        return $this->hasOne(InvoiceLine::class)->where('released', false);
    }

    public function totalMinutes(): int
    {
        if ($this->relationLoaded('timeEntries')) {
            return (int) $this->timeEntries->sum('duration_minutes');
        }

        return (int) $this->timeEntries()->sum('duration_minutes');
    }
}

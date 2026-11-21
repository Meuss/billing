<?php

namespace App\Models;

use App\Domain\Time\Duration;
use App\Domain\Time\TimeEntryGuard;
use App\Enums\TaskStatus;
use Carbon\CarbonImmutable;
use Database\Factories\TimeEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

#[Fillable(['task_id', 'started_at', 'ended_at', 'duration_minutes', 'description'])]
class TimeEntry extends Model
{
    /** @use HasFactory<TimeEntryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_minutes' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Every write path is normalised: duration is rounded up and the end follows the start.
        static::saving(function (TimeEntry $entry): void {
            $entry->duration_minutes = Duration::roundUp((int) $entry->duration_minutes);
            $entry->ended_at = CarbonImmutable::instance($entry->started_at)->addMinutes($entry->duration_minutes);
        });

        static::creating(function (TimeEntry $entry): void {
            TimeEntryGuard::assertCanAdd(Task::findOrFail($entry->task_id));
        });

        static::updating(function (TimeEntry $entry): void {
            TimeEntryGuard::assertCanEdit($entry);
            if ($entry->isDirty('task_id')) {
                TimeEntryGuard::assertCanAdd(Task::findOrFail($entry->task_id));
            }
        });

        static::deleting(fn (TimeEntry $entry) => TimeEntryGuard::assertCanEdit($entry));

        static::created(function (TimeEntry $entry): void {
            Task::whereKey($entry->task_id)
                ->where('status', TaskStatus::Todo)
                ->update(['status' => TaskStatus::InProgress]);
        });
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public static function fromStartAndDuration(Task $task, CarbonImmutable $start, int $minutes, string $description): self
    {
        $rounded = Duration::roundUp($minutes);

        return (new self)->forceFill([
            'task_id' => $task->getKey(),
            'started_at' => $start,
            'ended_at' => $start->addMinutes($rounded),
            'duration_minutes' => $rounded,
            'description' => $description,
        ]);
    }

    public static function fromStartAndEnd(Task $task, CarbonImmutable $start, CarbonImmutable $end, string $description): self
    {
        if ($end->lessThanOrEqualTo($start)) {
            throw new InvalidArgumentException('The end must be after the start.');
        }

        return self::fromStartAndDuration($task, $start, (int) ceil($start->diffInSeconds($end) / 60), $description);
    }
}

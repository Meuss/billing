<?php

namespace App\Domain\Time;

use App\Enums\InvoiceStatus;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TimeEntry;
use DomainException;

class TimeEntryGuard
{
    /** A billed Task takes no new Time Entries, even while its Invoice is a draft. */
    public static function assertCanAdd(Task $task): void
    {
        if ($task->status === TaskStatus::Billed) {
            throw new DomainException('This Task is billed; log further work on a new Task.');
        }
    }

    /** Entries stay editable until the Task's Invoice leaves draft. */
    public static function assertCanEdit(TimeEntry $entry): void
    {
        // Fresh lookup by the ORIGINAL task: a pending task_id change must not hide a billed source Task.
        $task = Task::findOrFail($entry->getOriginal('task_id') ?? $entry->task_id);

        if ($task->status === TaskStatus::Billed && $task->invoiceLine?->invoice->status !== InvoiceStatus::Draft) {
            throw new DomainException('This Task is on a sent invoice; its Time Entries can no longer be changed.');
        }
    }
}

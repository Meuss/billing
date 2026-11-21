<?php

namespace App\Domain\Time;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TimeEntry;
use DomainException;

class TimeEntryGuard
{
    public static function assertCanAdd(Task $task): void
    {
        if ($task->status === TaskStatus::Billed) {
            throw new DomainException('This Task is billed; log further work on a new Task.');
        }
    }

    public static function assertCanEdit(TimeEntry $entry): void
    {
        if ($entry->task->status === TaskStatus::Billed) {
            throw new DomainException('This Task is billed; its Time Entries can no longer be changed.');
        }
    }
}

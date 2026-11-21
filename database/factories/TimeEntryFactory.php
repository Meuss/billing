<?php

namespace Database\Factories;

use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimeEntry>
 */
class TimeEntryFactory extends Factory
{
    public function definition(): array
    {
        $start = now()->startOfHour();

        return [
            'task_id' => Task::factory(),
            'started_at' => $start,
            'ended_at' => $start->copy()->addHour(),
            'duration_minutes' => 60,
            'description' => fake()->sentence(),
        ];
    }
}

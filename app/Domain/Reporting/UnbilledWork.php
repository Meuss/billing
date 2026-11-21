<?php

namespace App\Domain\Reporting;

use App\Domain\Invoicing\Money;
use App\Enums\ExpenseStatus;
use App\Enums\TaskStatus;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Task;
use Illuminate\Support\Collection;

/** Done Tasks and unbilled Expenses not yet on any Invoice, per Client. */
class UnbilledWork
{
    /** @return Collection<int, array{client: Client, minutes: int, task_amount_cents: int, expense_amount_cents: int, total_cents: int}> */
    public static function perClient(): Collection
    {
        $minutes = [];
        $taskAmounts = [];

        Task::query()
            ->where('status', TaskStatus::Done)
            ->with('project')
            ->withSum('timeEntries as logged_minutes', 'duration_minutes')
            ->get()
            ->each(function (Task $task) use (&$minutes, &$taskAmounts): void {
                $clientId = $task->project->client_id;
                $logged = (int) $task->logged_minutes;
                $minutes[$clientId] = ($minutes[$clientId] ?? 0) + $logged;
                // Priced per task, like its Invoice Line, so the figure matches what will be billed.
                $taskAmounts[$clientId] = ($taskAmounts[$clientId] ?? 0) + Money::forMinutes($logged, $task->project->effectiveRateCents());
            });

        $expenseAmounts = Expense::query()
            ->where('status', ExpenseStatus::Unbilled)
            ->selectRaw('client_id, SUM(billed_amount_cents) as amount')
            ->groupBy('client_id')
            ->pluck('amount', 'client_id')
            ->map(fn ($amount): int => (int) $amount)
            ->all();

        $clientIds = array_unique([...array_keys($taskAmounts), ...array_keys($expenseAmounts)]);
        $clients = Client::query()->whereKey($clientIds)->orderBy('name')->get();

        return $clients
            ->map(function (Client $client) use ($minutes, $taskAmounts, $expenseAmounts): array {
                $tasks = $taskAmounts[$client->id] ?? 0;
                $expenses = $expenseAmounts[$client->id] ?? 0;

                return [
                    'client' => $client,
                    'minutes' => $minutes[$client->id] ?? 0,
                    'task_amount_cents' => $tasks,
                    'expense_amount_cents' => $expenses,
                    'total_cents' => $tasks + $expenses,
                ];
            })
            ->filter(fn (array $row): bool => $row['total_cents'] !== 0 || $row['minutes'] !== 0)
            ->values();
    }
}

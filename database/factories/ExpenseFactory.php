<?php

namespace Database\Factories;

use App\Enums\ExpenseStatus;
use App\Models\Client;
use App\Models\Expense;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    public function definition(): array
    {
        $cost = fake()->numberBetween(500, 50000);

        return [
            'client_id' => Client::factory(),
            'project_id' => null,
            'date' => fake()->date(),
            'supplier' => fake()->company(),
            'description' => fake()->sentence(3),
            'cost_cents' => $cost,
            'billed_amount_cents' => $cost,
            'status' => ExpenseStatus::Unbilled,
        ];
    }

    public function billed(): static
    {
        return $this->state(['status' => ExpenseStatus::Billed]);
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => rtrim(fake()->sentence(4), '.'),
            'description' => fake()->optional()->paragraph(),
            'status' => fake()->randomElement(Task::STATUSES),
            'priority' => fake()->randomElement(Task::PRIORITIES),
            'due_on' => fake()->optional()->dateTimeBetween('now', '+2 months'),
            'estimated_minutes' => fake()->optional()->numberBetween(15, 480),
            'position' => fake()->randomFloat(2, 0, 10000),
        ];
    }

    public function todo(): static
    {
        return $this->state(fn () => ['status' => 'todo']);
    }

    public function done(): static
    {
        return $this->state(fn () => ['status' => 'done', 'completed_at' => now()]);
    }

    public function urgent(): static
    {
        return $this->state(fn () => ['priority' => 'urgent']);
    }
}

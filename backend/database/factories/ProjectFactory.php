<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Not fake()->catchPhrase(): that lives on the en_US provider only and
        // breaks if APP_FAKER_LOCALE changes. words() is locale-agnostic.
        return [
            'name' => Str::title(fake()->words(3, true)),
            'description' => fake()->paragraph(),
            'status' => fake()->randomElement(Project::STATUSES),
            'color' => fake()->hexColor(),
            'starts_on' => fake()->dateTimeBetween('-3 months', 'now'),
            'due_on' => fake()->dateTimeBetween('now', '+3 months'),
            'budget' => fake()->randomFloat(2, 1000, 100000),
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => 'active']);
    }

    public function completed(): static
    {
        return $this->state(fn () => ['status' => 'completed', 'completed_at' => now()]);
    }

    /** Due in the past and not finished — the overdue case. */
    public function overdue(): static
    {
        return $this->state(fn () => [
            'status' => 'active',
            'due_on' => now()->subWeek(),
        ]);
    }
}

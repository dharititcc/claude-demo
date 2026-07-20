<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    protected $model = Event::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-1 week', '+1 month');

        return [
            'title' => rtrim(fake()->sentence(3), '.'),
            'description' => fake()->optional()->sentence(),
            'type' => fake()->randomElement(Event::TYPES),
            'color' => fake()->hexColor(),
            'starts_at' => $start,
            'ends_at' => (clone $start)->modify('+1 hour'),
            'all_day' => false,
        ];
    }

    /**
     * @param array<int, string> $days
     */
    public function weekly(array $days = ['MO']): static
    {
        return $this->state(fn () => [
            'recurrence_frequency' => 'weekly',
            'recurrence_interval' => 1,
            'recurrence_by_day' => $days,
        ]);
    }

    public function daily(): static
    {
        return $this->state(fn () => [
            'recurrence_frequency' => 'daily',
            'recurrence_interval' => 1,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('+1-###-###-####'),
            'company' => fake()->company(),
            'website' => 'https://'.fake()->domainName(),
            'status' => fake()->randomElement(Customer::STATUSES),
            'address_line1' => fake()->streetAddress(),
            'city' => fake()->city(),
            // Not fake()->stateAbbr(): that lives on the en_US provider only, so
            // it breaks the factory if APP_FAKER_LOCALE changes.
            'state' => fake()->randomElement(['CA', 'NY', 'TX', 'FL', 'IL', 'WA', 'MA', 'CO']),
            'postal_code' => fake()->postcode(),
            'country' => fake()->countryCode(),
            'lifetime_value' => fake()->randomFloat(2, 0, 50000),
            'owner_id' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => 'active']);
    }

    public function lead(): static
    {
        return $this->state(fn () => ['status' => 'lead']);
    }
}

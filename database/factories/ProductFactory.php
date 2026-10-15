<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $identifier = fake()->unique()->bothify('SKU-#####');

        return [
            'identifier' => $identifier,
            'enabled' => true,
            'family' => fake()->word(),
            'categories' => [fake()->word()],
            'values' => [],
            'raw_payload' => ['identifier' => $identifier],
            'synced_at' => now(),
        ];
    }

    /**
     * Indicate that the product was last synced a day ago.
     */
    public function stale(): static
    {
        return $this->state(fn (array $attributes): array => [
            'synced_at' => now()->subDay(),
        ]);
    }
}

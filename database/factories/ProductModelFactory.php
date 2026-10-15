<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProductModel>
 */
class ProductModelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = fake()->unique()->slug(2);

        return [
            'code' => $code,
            'parent' => null,
            'family' => 'clothing',
            'family_variant' => 'clothing_colorsize',
            'variant_axes' => ['color', 'size'],
            'categories' => [fake()->word()],
            'values' => [],
            'raw_payload' => ['code' => $code],
            'synced_at' => now(),
        ];
    }

    /**
     * Indicate that the model was last synced a day ago.
     */
    public function stale(): static
    {
        return $this->state(fn (array $attributes): array => [
            'synced_at' => now()->subDay(),
        ]);
    }
}

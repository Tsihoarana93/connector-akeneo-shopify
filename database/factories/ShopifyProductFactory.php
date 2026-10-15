<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ShopifyProduct>
 */
class ShopifyProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'akeneo_code' => 'product:'.fake()->unique()->numerify('#####'),
            'shopify_product_id' => 'gid://shopify/Product/'.fake()->unique()->randomNumber(8),
            'payload_hash' => hash('sha256', fake()->uuid()),
            'pushed_at' => now(),
            'last_error' => null,
        ];
    }
}

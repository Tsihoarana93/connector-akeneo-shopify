<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ShopifyFile>
 */
class ShopifyFileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $hash = sha1(fake()->unique()->uuid());

        return [
            'akeneo_media_code' => implode('/', str_split(substr($hash, 0, 4)))."/{$hash}_image.jpg",
            'shopify_file_id' => 'gid://shopify/MediaImage/'.fake()->unique()->randomNumber(8),
        ];
    }
}

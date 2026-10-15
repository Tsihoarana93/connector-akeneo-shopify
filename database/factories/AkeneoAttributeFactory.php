<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AkeneoAttribute>
 */
class AkeneoAttributeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = fake()->unique()->lexify('attribute_????');

        return [
            'code' => $code,
            'type' => 'pim_catalog_text',
            'labels' => ['en_US' => ucfirst(str_replace('_', ' ', $code))],
            'localizable' => false,
            'scopable' => false,
            'options' => null,
            'raw_payload' => ['code' => $code, 'type' => 'pim_catalog_text'],
            'synced_at' => now(),
        ];
    }

    /**
     * A simple select attribute with the given options, as `{code: en_US label}`.
     *
     * @param  array<string, string>  $options
     */
    public function simpleSelect(array $options): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => 'pim_catalog_simpleselect',
            'options' => collect($options)->map(fn (string $label): array => ['en_US' => $label])->all(),
        ]);
    }

    /**
     * Indicate that the attribute is chosen to be sent to Shopify.
     */
    public function enabled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'shopify_enabled' => true,
        ]);
    }

    /**
     * Indicate that the attribute's Shopify metafield definition exists.
     */
    public function withDefinition(): static
    {
        return $this->state(fn (array $attributes): array => [
            'shopify_definition_id' => 'gid://shopify/MetafieldDefinition/'.fake()->unique()->randomNumber(5),
            'shopify_definition_checked_at' => now(),
        ]);
    }

    /**
     * Indicate that the attribute was last synced a day ago.
     */
    public function stale(): static
    {
        return $this->state(fn (array $attributes): array => [
            'synced_at' => now()->subDay(),
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\AkeneoAttribute;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AkeneoAttributeTest extends TestCase
{
    /**
     * @return array<string, array{string, ?string, mixed, ?string}>
     */
    public static function metafieldValues(): array
    {
        return [
            'simple select label' => ['pim_catalog_simpleselect', null, '600', '60°'],
            'unknown option falls back to its code' => ['pim_catalog_simpleselect', null, '900', '900'],
            'multi select as a JSON list' => ['pim_catalog_multiselect', null, ['600', 'hand'], '["60°","Hand wash"]'],
            'multi select as text' => ['pim_catalog_multiselect', 'single_line_text_field', ['600', 'hand'], '60°, Hand wash'],
            'boolean' => ['pim_catalog_boolean', null, false, 'false'],
            'boolean as text' => ['pim_catalog_boolean', 'single_line_text_field', true, 'Yes'],
            'decimal number' => ['pim_catalog_number', null, '12.5000', '12.5'],
            'integer number' => ['pim_catalog_number', 'number_integer', '12.0000', '12'],
            'whole decimal number' => ['pim_catalog_number', null, '12.0000', '12'],
            'large decimal keeps every digit' => ['pim_catalog_number', null, '12345678901234567890.5000', '12345678901234567890.5'],
            'small decimal is not in scientific notation' => ['pim_catalog_number', null, '0.0000100', '0.00001'],
            'decimal from a float' => ['pim_catalog_number', null, 1.0E-5, '0.00001'],
            'negative decimal' => ['pim_catalog_number', null, '-3.2500', '-3.25'],
            'metric' => ['pim_catalog_metric', null, ['amount' => '800.0000', 'unit' => 'GRAM'], '800 gram'],
            'date' => ['pim_catalog_date', null, '2026-10-15T00:00:00+00:00', '2026-10-15'],
            'single line text loses line breaks' => ['pim_catalog_textarea', 'single_line_text_field', "Dry clean\nonly", 'Dry clean only'],
            'multi line text keeps line breaks' => ['pim_catalog_textarea', null, "Dry clean\nonly", "Dry clean\nonly"],
            'empty value is not sent' => ['pim_catalog_text', null, '', null],
        ];
    }

    #[DataProvider('metafieldValues')]
    public function test_it_converts_an_akeneo_value_to_the_metafield_value_of_its_shopify_type(string $type, ?string $shopifyType, mixed $data, ?string $expected): void
    {
        $attribute = AkeneoAttribute::factory()->make([
            'type' => $type,
            'shopify_type' => $shopifyType,
            'options' => ['600' => ['en_US' => '60°'], 'hand' => ['en_US' => 'Hand wash']],
        ]);

        $this->assertSame($expected, $attribute->toMetafieldValue($data));
    }
}

<?php

namespace App\Services;

use App\Models\Product;

class AttributeUsage
{
    private const SAMPLES = 5;

    /**
     * Count, for each attribute, the synced products that have a value, and collect a few distinct sample values
     * and how many products use each option. Product values from Akeneo include values inherited from their models.
     *
     * @return array<string, array{products: int, samples: list<mixed>, options: array<string, int>}>
     */
    public function all(): array
    {
        $usage = [];

        foreach (Product::query()->toBase()->select('values')->cursor() as $row) {
            $values = json_decode($row->values ?? '[]', true) ?: [];

            foreach (array_keys($values) as $code) {
                $data = ShopifyProductMapper::pickValue($values, $code);

                if ($data === null || $data === '' || $data === []) {
                    continue;
                }

                $usage[$code] ??= ['products' => 0, 'samples' => [], 'options' => []];
                $usage[$code]['products']++;

                if (count($usage[$code]['samples']) < self::SAMPLES && ! in_array($data, $usage[$code]['samples'], true)) {
                    $usage[$code]['samples'][] = $data;
                }

                foreach (is_array($data) && array_is_list($data) ? $data : [$data] as $option) {
                    if (is_string($option)) {
                        $usage[$code]['options'][$option] = ($usage[$code]['options'][$option] ?? 0) + 1;
                    }
                }
            }
        }

        return $usage;
    }

    /**
     * @return array{products: int, samples: list<mixed>, options: array<string, int>}
     */
    public function of(string $code): array
    {
        return $this->all()[$code] ?? ['products' => 0, 'samples' => [], 'options' => []];
    }
}

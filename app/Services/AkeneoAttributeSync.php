<?php

namespace App\Services;

use App\Models\AkeneoAttribute;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;

class AkeneoAttributeSync
{
    private const BATCH_SIZE = 100;

    public function __construct(private AkeneoProductService $akeneo) {}

    /**
     * Upsert every Akeneo attribute, with the options of select attributes, and return how many were synced.
     * Only Akeneo columns are written, so the Shopify mapping chosen in the interface survives every sync.
     * Every row gets the same `synced_at`, which is also when the attribute list was last retrieved.
     *
     * @throws RequestException|ConnectionException
     */
    public function sync(Carbon $syncedAt): int
    {
        $synced = 0;
        $batch = [];

        foreach ($this->akeneo->getAllAttributes() as $item) {
            $batch[] = $this->toRow($item, $syncedAt);

            if (count($batch) === self::BATCH_SIZE) {
                $synced += $this->upsert($batch);
                $batch = [];
            }
        }

        return $synced + $this->upsert($batch);
    }

    /**
     * Map an Akeneo attribute payload to an `akeneo_attributes` row. JSON is encoded manually because upsert bypasses model casts.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    private function toRow(array $item, Carbon $syncedAt): array
    {
        $options = in_array($item['type'], AkeneoAttribute::SELECT_TYPES, true)
            ? $this->akeneo->getAttributeOptions($item['code'])
            : null;

        return [
            'code' => $item['code'],
            'type' => $item['type'],
            'labels' => json_encode($item['labels'] ?? []),
            'localizable' => (bool) ($item['localizable'] ?? false),
            'scopable' => (bool) ($item['scopable'] ?? false),
            'options' => $options === null ? null : json_encode($options),
            'raw_payload' => json_encode($item),
            'synced_at' => $syncedAt,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function upsert(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        AkeneoAttribute::query()->upsert($rows, ['code'], array_values(array_diff(array_keys($rows[0]), ['code'])));

        return count($rows);
    }
}

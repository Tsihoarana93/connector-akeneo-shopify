<?php

namespace App\Console\Commands;

use App\Console\Concerns\RecordsSyncRun;
use App\Models\AkeneoAttribute;
use App\Models\Product;
use App\Models\ProductModel;
use App\Services\AkeneoAttributeSync;
use App\Services\AkeneoProductService;
use Generator;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use RuntimeException;

class SyncProducts extends Command
{
    use RecordsSyncRun;

    protected $signature = 'fidelity:sync-products
                            {--prune : Delete local products and product models that no longer exist in Akeneo}
                            {--sync-run= : ID of a queued sync run to record into (used by the interface)}';

    protected $description = 'Sync attributes, product models and products from Akeneo PIM into the local database';

    private const BATCH_SIZE = 100;

    public function handle(AkeneoProductService $akeneo, AkeneoAttributeSync $attributeSync): int
    {
        return $this->recordSyncRun(['prune' => (bool) $this->option('prune')], fn (): int => $this->sync($akeneo, $attributeSync));
    }

    private function sync(AkeneoProductService $akeneo, AkeneoAttributeSync $attributeSync): int
    {
        $syncedAt = now()->startOfSecond();

        try {
            $this->info('Syncing attributes from Akeneo...');
            $attributes = $attributeSync->sync($syncedAt);
            $this->info("  {$attributes} attributes synced.");

            $this->info('Syncing product models from Akeneo...');
            $models = $this->syncAll(
                ProductModel::class,
                'code',
                $akeneo->getAllProductModels(),
                fn (array $item): array => $this->toProductModelRow($item, $syncedAt, $akeneo),
                'product models'
            );

            $this->info('Syncing products from Akeneo...');
            $products = $this->syncAll(
                Product::class,
                'identifier',
                $akeneo->getAllProducts(),
                fn (array $item): array => $this->toProductRow($item, $syncedAt),
                'products'
            );
        } catch (RequestException|ConnectionException|RuntimeException $exception) {
            $this->syncRunMessage = "Sync aborted: {$exception->getMessage()}";
            $this->error($this->syncRunMessage);

            return self::FAILURE;
        }

        $this->info("Done. {$attributes} attributes, {$models} product models and {$products} products synced.");

        $this->syncRunCounts = [
            'attributes' => $attributes,
            'product_models' => $models,
            'products' => $products,
            'pruned_product_models' => $this->handleStale(ProductModel::class, $syncedAt, $models, 'product models'),
            'pruned_products' => $this->handleStale(Product::class, $syncedAt, $products, 'products'),
            'pruned_attributes' => $this->handleStale(AkeneoAttribute::class, $syncedAt, $attributes, 'attributes'),
        ];

        return self::SUCCESS;
    }

    /**
     * Upsert every item in batches and return how many were synced.
     *
     * @param  class-string<Model>  $modelClass
     * @param  Generator<int, array<string, mixed>>  $items
     * @param  callable(array<string, mixed>): array<string, mixed>  $toRow
     */
    private function syncAll(string $modelClass, string $uniqueBy, Generator $items, callable $toRow, string $label): int
    {
        $synced = 0;
        $batch = [];

        foreach ($items as $item) {
            $batch[] = $toRow($item);

            if (count($batch) === self::BATCH_SIZE) {
                $synced += $this->upsertBatch($modelClass, $uniqueBy, $batch);
                $batch = [];
                $this->info("  {$synced} {$label} synced...");
            }
        }

        return $synced + $this->upsertBatch($modelClass, $uniqueBy, $batch);
    }

    /**
     * Map an Akeneo product payload to a `products` row. JSON is encoded manually because upsert bypasses model casts.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function toProductRow(array $item, Carbon $syncedAt): array
    {
        return [
            'identifier' => $item['identifier'],
            'enabled' => (bool) ($item['enabled'] ?? true),
            'family' => $item['family'] ?? null,
            'categories' => json_encode($item['categories'] ?? []),
            'values' => json_encode($item['values'] ?? []),
            'raw_payload' => json_encode($item),
            'synced_at' => $syncedAt,
        ];
    }

    /**
     * Map an Akeneo product model payload to a `product_models` row, resolving the variant axes of root models.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    private function toProductModelRow(array $item, Carbon $syncedAt, AkeneoProductService $akeneo): array
    {
        $isRoot = ($item['parent'] ?? null) === null;
        $axes = $isRoot && isset($item['family'], $item['family_variant'])
            ? $akeneo->getFamilyVariantAxes($item['family'], $item['family_variant'])
            : null;

        return [
            'code' => $item['code'],
            'parent' => $item['parent'] ?? null,
            'family' => $item['family'] ?? null,
            'family_variant' => $item['family_variant'] ?? null,
            'variant_axes' => $axes === null ? null : json_encode($axes),
            'categories' => json_encode($item['categories'] ?? []),
            'values' => json_encode($item['values'] ?? []),
            'raw_payload' => json_encode($item),
            'synced_at' => $syncedAt,
        ];
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  list<array<string, mixed>>  $rows
     */
    private function upsertBatch(string $modelClass, string $uniqueBy, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $modelClass::query()->upsert(
            $rows,
            [$uniqueBy],
            array_values(array_diff(array_keys($rows[0]), [$uniqueBy]))
        );

        return count($rows);
    }

    /**
     * Warn about, or with --prune delete, rows that were not part of this sync, and return how many were deleted.
     * Nothing is pruned when Akeneo returned no rows at all, since that usually means a configuration or permission problem.
     *
     * @param  class-string<Model>  $modelClass
     */
    private function handleStale(string $modelClass, Carbon $syncedAt, int $syncedCount, string $label): int
    {
        $staleRows = $modelClass::query()
            ->where(fn ($query) => $query->whereNull('synced_at')->orWhere('synced_at', '<', $syncedAt));

        if (! $this->option('prune')) {
            if (($staleCount = $staleRows->count()) > 0) {
                $this->warn("{$staleCount} local {$label} are no longer in Akeneo. Run with --prune to delete them.");
            }

            return 0;
        }

        if ($syncedCount === 0 && ($staleCount = $staleRows->count()) > 0) {
            $this->warn("Akeneo returned no {$label}, so the {$staleCount} local {$label} were not pruned. Check the Akeneo connection and permissions.");

            return 0;
        }

        $pruned = $staleRows->delete();
        $this->info("Pruned {$pruned} {$label} no longer in Akeneo.");

        return $pruned;
    }
}

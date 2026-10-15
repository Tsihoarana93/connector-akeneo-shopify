<?php

namespace App\Console\Commands;

use App\Console\Concerns\RecordsSyncRun;
use App\Models\Product;
use App\Models\ProductModel;
use App\Models\ShopifyProduct;
use App\Services\ShopifyAdminService;
use App\Services\ShopifyProductMapper;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;

class PushProducts extends Command
{
    use RecordsSyncRun;

    protected $signature = 'fidelity:push-products
                            {--force : Push every product even if it has not changed since the last push}
                            {--limit= : Push at most this many changed products}
                            {--sync-run= : ID of a queued push run to record into (used by the interface)}';

    protected $description = 'Push synced Akeneo products to Shopify, grouping variants under their root product model';

    public function handle(ShopifyAdminService $shopify, ShopifyProductMapper $mapper): int
    {
        return $this->recordSyncRun(
            ['force' => (bool) $this->option('force'), 'limit' => $this->option('limit') !== null ? (int) $this->option('limit') : null],
            fn (): int => $this->push($shopify, $mapper)
        );
    }

    private function push(ShopifyAdminService $shopify, ShopifyProductMapper $mapper): int
    {
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];

        try {
            $shopify->ensureAkeneoCodeDefinition();

            foreach ($this->groups() as $code => $group) {
                if ($limit !== null && $limit <= $counts['created'] + $counts['updated'] + $counts['failed']) {
                    break;
                }

                $counts[$this->pushGroup($shopify, $mapper, $code, $group)]++;
            }
        } catch (RequestException|ConnectionException|RuntimeException $exception) {
            $this->syncRunMessage = "Push aborted: {$exception->getMessage()}";
            $this->error($this->syncRunMessage);
            $this->summary($counts);

            return self::FAILURE;
        }

        $this->summary($counts);

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array{root: ?ProductModel, products: Collection<int, Product>, error: ?string}  $group
     * @return 'created'|'updated'|'skipped'|'failed'
     *
     * @throws RequestException|ConnectionException|RuntimeException
     */
    private function pushGroup(ShopifyAdminService $shopify, ShopifyProductMapper $mapper, string $code, array $group): string
    {
        $mapping = ShopifyProduct::query()->firstOrNew(['akeneo_code' => $code]);

        try {
            if ($group['error'] !== null) {
                throw new InvalidArgumentException($group['error']);
            }

            $input = $mapper->toProductSetInput($group['root'], $group['products']);
        } catch (InvalidArgumentException $exception) {
            return $this->recordFailure($mapping, $code, $exception->getMessage());
        }

        $hash = hash('sha256', (string) json_encode($input));
        $isNew = $mapping->shopify_product_id === null;

        if (! $this->option('force') && ! $isNew && $mapping->payload_hash === $hash && $mapping->last_error === null) {
            return 'skipped';
        }

        if ($isNew) {
            $input['status'] = 'DRAFT';
        }

        $result = $shopify->productSet($input, $code);

        if ($result['userErrors'] !== [] || $result['productId'] === null) {
            $message = collect($result['userErrors'])->pluck('message')->implode('; ') ?: 'Shopify returned no product.';

            return $this->recordFailure($mapping, $code, $message);
        }

        $mapping->fill([
            'shopify_product_id' => $result['productId'],
            'payload_hash' => $hash,
            'pushed_at' => now(),
            'last_error' => null,
        ])->save();

        return $isNew ? 'created' : 'updated';
    }

    /**
     * Group products by their root product model; products without a parent form their own group.
     *
     * @return array<string, array{root: ?ProductModel, products: Collection<int, Product>, error: ?string}>
     */
    private function groups(): array
    {
        $models = ProductModel::query()->get()->keyBy('code');
        $groups = [];

        foreach (Product::query()->orderBy('identifier')->get() as $product) {
            $parent = $product->raw_payload['parent'] ?? null;
            $root = $parent !== null ? $this->rootModel($models, $parent) : null;
            $code = $parent !== null ? 'model:'.($root?->code ?? $parent) : "product:{$product->identifier}";

            $groups[$code] ??= [
                'root' => $root,
                'products' => collect(),
                'error' => $parent !== null && $root === null ? "Product model {$parent} has not been synced from Akeneo." : null,
            ];
            $groups[$code]['products']->push($product);
        }

        return $groups;
    }

    /**
     * @param  Collection<string, ProductModel>  $models
     */
    private function rootModel(Collection $models, string $code): ?ProductModel
    {
        $model = $models->get($code);

        while ($model !== null && $model->parent !== null) {
            $model = $models->get($model->parent);
        }

        return $model;
    }

    private function recordFailure(ShopifyProduct $mapping, string $code, string $message): string
    {
        $mapping->fill(['last_error' => $message])->save();
        $this->warn("  {$code}: {$message}");

        return 'failed';
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function summary(array $counts): void
    {
        $this->syncRunCounts = $counts;
        $this->table(['Created', 'Updated', 'Skipped', 'Failed'], [array_values($counts)]);
    }
}

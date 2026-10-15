<?php

namespace Tests\Feature;

use App\Enums\SyncRunStatus;
use App\Models\Product;
use App\Models\ProductModel;
use App\Models\SyncRun;
use App\Services\AkeneoProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class SyncProductsTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://akeneo.test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.akeneo.base_url' => self::BASE_URL,
            'services.akeneo.client_id' => 'client',
            'services.akeneo.client_secret' => 'secret',
            'services.akeneo.username' => 'user',
            'services.akeneo.password' => 'pass',
        ]);

        Sleep::fake();
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    public function test_it_syncs_every_page_by_following_next_links(): void
    {
        Http::fake([
            self::BASE_URL.'/api/oauth/v1/token' => Http::response(['access_token' => 'token-1', 'expires_in' => 3600]),
            self::BASE_URL.'/api/rest/v1/product-models*' => Http::response($this->page([])),
            self::BASE_URL.'/api/rest/v1/products?pagination_type=search_after&limit=100' => Http::response(
                $this->page([$this->product('SKU-1'), $this->product('SKU-2')], self::BASE_URL.'/api/rest/v1/products?search_after=SKU-2')
            ),
            self::BASE_URL.'/api/rest/v1/products?search_after=SKU-2' => Http::response(
                $this->page([$this->product('SKU-3', enabled: false)])
            ),
        ]);

        $this->artisan('fidelity:sync-products')
            ->expectsOutput('Done. 0 product models and 3 products synced.')
            ->assertSuccessful();

        $this->assertSame(3, Product::query()->count());

        $product = Product::query()->where('identifier', 'SKU-3')->firstOrFail();
        $this->assertFalse($product->enabled);
        $this->assertSame('shoes', $product->family);
        $this->assertSame(['summer'], $product->categories);
        $this->assertSame('SKU-3', $product->raw_payload['identifier']);
        $this->assertNotNull($product->synced_at);

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer token-1'));
    }

    public function test_it_updates_existing_products(): void
    {
        Product::factory()->stale()->create(['identifier' => 'SKU-1', 'family' => 'old']);

        $this->fakeAkeneo([$this->product('SKU-1')]);

        $this->artisan('fidelity:sync-products')->assertSuccessful();

        $this->assertSame(1, Product::query()->count());
        $product = Product::query()->where('identifier', 'SKU-1')->firstOrFail();
        $this->assertSame('shoes', $product->family);
        $this->assertTrue($product->synced_at->isToday());
    }

    public function test_it_warns_about_stale_products_without_deleting_them(): void
    {
        Product::factory()->stale()->create(['identifier' => 'GONE']);

        $this->fakeAkeneo([$this->product('SKU-1')]);

        $this->artisan('fidelity:sync-products')
            ->expectsOutput('1 local products are no longer in Akeneo. Run with --prune to delete them.')
            ->assertSuccessful();

        $this->assertDatabaseHas('products', ['identifier' => 'GONE']);
    }

    public function test_prune_deletes_products_no_longer_in_akeneo(): void
    {
        Product::factory()->stale()->create(['identifier' => 'GONE']);

        $this->fakeAkeneo([$this->product('SKU-1')]);

        $this->artisan('fidelity:sync-products --prune')
            ->expectsOutput('Pruned 1 products no longer in Akeneo.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('products', ['identifier' => 'GONE']);
        $this->assertDatabaseHas('products', ['identifier' => 'SKU-1']);
    }

    public function test_it_refreshes_an_expired_token_once(): void
    {
        Cache::put(AkeneoProductService::TOKEN_CACHE_KEY, 'expired-token');

        Http::fake([
            self::BASE_URL.'/api/oauth/v1/token' => Http::response(['access_token' => 'fresh-token', 'expires_in' => 3600]),
            self::BASE_URL.'/api/rest/v1/product-models*' => Http::response($this->page([])),
            self::BASE_URL.'/api/rest/v1/products*' => function (Request $request) {
                return $request->hasHeader('Authorization', 'Bearer fresh-token')
                    ? Http::response($this->page([$this->product('SKU-1')]))
                    : Http::response(['message' => 'Expired'], 401);
            },
        ]);

        $this->artisan('fidelity:sync-products')->assertSuccessful();

        $this->assertDatabaseHas('products', ['identifier' => 'SKU-1']);
        $this->assertSame('fresh-token', Cache::get(AkeneoProductService::TOKEN_CACHE_KEY));
    }

    public function test_it_fails_without_pruning_when_akeneo_errors(): void
    {
        Product::factory()->stale()->create(['identifier' => 'KEEP']);

        Http::fake([
            self::BASE_URL.'/api/oauth/v1/token' => Http::response(['access_token' => 'token-1', 'expires_in' => 3600]),
            self::BASE_URL.'/api/rest/v1/product-models*' => Http::response($this->page([])),
            self::BASE_URL.'/api/rest/v1/products*' => Http::response(['message' => 'Server error'], 500),
        ]);

        $this->artisan('fidelity:sync-products --prune')->assertFailed();

        $this->assertDatabaseHas('products', ['identifier' => 'KEEP']);
    }

    public function test_it_fails_when_authentication_is_rejected(): void
    {
        Http::fake([
            self::BASE_URL.'/api/oauth/v1/token' => Http::response(['message' => 'Invalid credentials'], 422),
        ]);

        $this->artisan('fidelity:sync-products')->assertFailed();

        $this->assertNull(Cache::get(AkeneoProductService::TOKEN_CACHE_KEY));
        $this->assertSame(0, Product::query()->count());
    }

    public function test_it_fails_when_akeneo_returns_no_access_token(): void
    {
        Http::fake([
            self::BASE_URL.'/api/oauth/v1/token' => Http::response(['expires_in' => 3600]),
        ]);

        $this->artisan('fidelity:sync-products')
            ->expectsOutput('Sync aborted: Akeneo did not return an access token.')
            ->assertFailed();

        $this->assertSame(0, Product::query()->count());
    }

    public function test_prune_keeps_local_products_when_akeneo_returns_none(): void
    {
        Product::factory()->stale()->create(['identifier' => 'KEEP']);

        $this->fakeAkeneo([]);

        $this->artisan('fidelity:sync-products --prune')
            ->expectsOutput('Akeneo returned no products, so the 1 local products were not pruned. Check the Akeneo connection and permissions.')
            ->assertSuccessful();

        $this->assertDatabaseHas('products', ['identifier' => 'KEEP']);
    }

    public function test_it_reuses_the_cached_token(): void
    {
        $this->fakeAkeneo([$this->product('SKU-1')]);

        $this->artisan('fidelity:sync-products')->assertSuccessful();
        $this->artisan('fidelity:sync-products')->assertSuccessful();

        Http::assertSentCount(5);
    }

    public function test_it_syncs_product_models_with_the_variant_axes_of_root_models(): void
    {
        ProductModel::factory()->stale()->create(['code' => 'gone-model']);

        Http::fake([
            self::BASE_URL.'/api/oauth/v1/token' => Http::response(['access_token' => 'token-1', 'expires_in' => 3600]),
            self::BASE_URL.'/api/rest/v1/product-models*' => Http::response($this->page([
                ['code' => 'amor', 'parent' => null, 'family' => 'clothing', 'family_variant' => 'clothing_color_size', 'categories' => ['men'], 'values' => []],
                ['code' => 'amor_blue', 'parent' => 'amor', 'family' => 'clothing', 'family_variant' => 'clothing_color_size', 'categories' => [], 'values' => []],
            ])),
            self::BASE_URL.'/api/rest/v1/families/clothing/variants/clothing_color_size' => Http::response([
                'variant_attribute_sets' => [
                    ['level' => 2, 'axes' => ['size']],
                    ['level' => 1, 'axes' => ['color']],
                ],
            ]),
            self::BASE_URL.'/api/rest/v1/products*' => Http::response($this->page([$this->product('SKU-1')])),
        ]);

        $this->artisan('fidelity:sync-products --prune')
            ->expectsOutput('Done. 2 product models and 1 products synced.')
            ->expectsOutput('Pruned 1 product models no longer in Akeneo.')
            ->assertSuccessful();

        $root = ProductModel::query()->where('code', 'amor')->firstOrFail();
        $this->assertSame(['color', 'size'], $root->variant_axes);
        $this->assertSame(['men'], $root->categories);

        $subModel = ProductModel::query()->where('code', 'amor_blue')->firstOrFail();
        $this->assertSame('amor', $subModel->parent);
        $this->assertNull($subModel->variant_axes);

        $this->assertDatabaseMissing('product_models', ['code' => 'gone-model']);
    }

    public function test_it_records_the_run_with_its_counts_and_log(): void
    {
        Product::factory()->stale()->create(['identifier' => 'GONE']);
        $this->fakeAkeneo([$this->product('SKU-1')]);

        $this->artisan('fidelity:sync-products --prune')->assertSuccessful();

        $run = SyncRun::query()->sole();
        $this->assertSame('fidelity:sync-products', $run->command);
        $this->assertSame(SyncRunStatus::Succeeded, $run->status);
        $this->assertSame(['prune' => true], $run->options);
        $this->assertEquals(['product_models' => 0, 'products' => 1, 'pruned_product_models' => 0, 'pruned_products' => 1], $run->counts);
        $this->assertNull($run->user_id);
        $this->assertNotNull($run->finished_at);

        $log = Storage::disk('local')->get($run->log_path);
        $this->assertStringContainsString('INFO Done. 0 product models and 1 products synced.', $log);
        $this->assertStringContainsString('INFO Pruned 1 products no longer in Akeneo.', $log);
        $this->assertStringContainsString('Finished: succeeded', $log);
    }

    public function test_it_records_an_aborted_run_as_failed(): void
    {
        Http::fake([
            self::BASE_URL.'/api/oauth/v1/token' => Http::response(['expires_in' => 3600]),
        ]);

        $this->artisan('fidelity:sync-products')->assertFailed();

        $run = SyncRun::query()->sole();
        $this->assertSame(SyncRunStatus::Failed, $run->status);
        $this->assertSame('Sync aborted: Akeneo did not return an access token.', $run->message);
        $this->assertStringContainsString('ERROR Sync aborted: Akeneo did not return an access token.', Storage::disk('local')->get($run->log_path));
    }

    public function test_it_records_into_the_queued_run_given_by_the_interface(): void
    {
        $queued = SyncRun::factory()->queued()->create();
        $this->fakeAkeneo([$this->product('SKU-1')]);

        $this->artisan('fidelity:sync-products', ['--sync-run' => $queued->id])->assertSuccessful();

        $run = SyncRun::query()->sole();
        $this->assertTrue($run->is($queued));
        $this->assertSame(SyncRunStatus::Succeeded, $run->status);
        $this->assertNotNull($run->queued_at);
        $this->assertNotNull($run->started_at);
    }

    public function test_it_marks_a_run_left_running_as_interrupted(): void
    {
        $abandoned = SyncRun::factory()->running()->create();
        $push = SyncRun::factory()->push()->running()->create();
        $this->fakeAkeneo([$this->product('SKU-1')]);

        $this->artisan('fidelity:sync-products')->assertSuccessful();

        $this->assertSame(SyncRunStatus::Failed, $abandoned->fresh()->status);
        $this->assertSame('Interrupted before it finished.', $abandoned->fresh()->message);
        $this->assertSame(SyncRunStatus::Running, $push->fresh()->status);
    }

    /**
     * @param  list<array<string, mixed>>  $products
     */
    private function fakeAkeneo(array $products): void
    {
        Http::fake([
            self::BASE_URL.'/api/oauth/v1/token' => Http::response(['access_token' => 'token-1', 'expires_in' => 3600]),
            self::BASE_URL.'/api/rest/v1/product-models*' => Http::response($this->page([])),
            self::BASE_URL.'/api/rest/v1/products*' => Http::response($this->page($products)),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function page(array $items, ?string $nextUrl = null): array
    {
        $links = ['self' => ['href' => self::BASE_URL.'/api/rest/v1/products']];

        if ($nextUrl !== null) {
            $links['next'] = ['href' => $nextUrl];
        }

        return ['_links' => $links, '_embedded' => ['items' => $items]];
    }

    /**
     * @return array<string, mixed>
     */
    private function product(string $identifier, bool $enabled = true): array
    {
        return [
            'identifier' => $identifier,
            'enabled' => $enabled,
            'family' => 'shoes',
            'categories' => ['summer'],
            'values' => ['name' => [['locale' => null, 'scope' => null, 'data' => $identifier]]],
        ];
    }
}

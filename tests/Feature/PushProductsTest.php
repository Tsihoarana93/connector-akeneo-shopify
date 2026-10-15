<?php

namespace Tests\Feature;

use App\Enums\SyncRunStatus;
use App\Models\AkeneoAttribute;
use App\Models\Product;
use App\Models\ProductModel;
use App\Models\ShopifyFile;
use App\Models\ShopifyProduct;
use App\Models\SyncRun;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class PushProductsTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP_URL = 'https://test-shop.myshopify.com';

    private const GRAPHQL_URL = self::SHOP_URL.'/admin/api/2026-10/graphql.json';

    private const AKENEO_URL = 'https://akeneo.test';

    private const STAGED_UPLOAD_URL = 'https://shopify-staged-uploads.test/upload';

    /**
     * Variables of every productSet mutation sent to Shopify.
     *
     * @var list<array<string, mixed>>
     */
    private array $productSetCalls = [];

    private bool $definitionCreated = false;

    /**
     * Filenames of the images created with fileCreate, keyed by their Shopify file ID.
     *
     * @var array<string, string>
     */
    private array $createdFiles = [];

    /**
     * Filenames Shopify fails to process.
     *
     * @var list<string>
     */
    private array $failingImages = [];

    /**
     * @var array<string, mixed>|null
     */
    private ?array $createdDefinition = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.shopify.shop' => 'test-shop',
            'services.shopify.client_id' => 'shopify-client',
            'services.shopify.client_secret' => 'shopify-secret',
            'services.shopify.api_version' => '2026-10',
            'services.shopify.currency' => 'USD',
            'services.akeneo.base_url' => self::AKENEO_URL,
        ]);

        Sleep::fake();
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    public function test_it_creates_a_standalone_product_as_draft(): void
    {
        $this->standaloneProduct('SKU-1', price: '19.90', ean: '1234567890123', usdPrice: '21.50');
        $this->fakeShopify();

        $this->artisan('fidelity:push-products')->assertSuccessful();

        $this->assertTrue($this->definitionCreated);
        $this->assertSame('id', $this->createdDefinition['type']);
        $this->assertSame(['uniqueValues' => ['enabled' => true]], $this->createdDefinition['capabilities']);
        $this->assertCount(1, $this->productSetCalls);
        $this->assertArrayNotHasKey('metafields', $this->productSetCalls[0]['input']);

        $call = $this->productSetCalls[0];
        $this->assertSame(['namespace' => 'akeneo', 'key' => 'code', 'value' => 'product:SKU-1'], $call['identifier']['customId']);
        $this->assertSame('DRAFT', $call['input']['status']);
        $this->assertSame('Product SKU-1', $call['input']['title']);
        $this->assertSame('<p>English description</p>', $call['input']['descriptionHtml']);
        $this->assertSame([['name' => 'Title', 'values' => [['name' => 'Default Title']]]], $call['input']['productOptions']);
        $this->assertSame([
            'optionValues' => [['optionName' => 'Title', 'name' => 'Default Title']],
            'sku' => 'SKU-1',
            'price' => '21.50',
            'barcodes' => [['value' => '1234567890123']],
        ], $call['input']['variants'][0]);

        $mapping = ShopifyProduct::query()->where('akeneo_code', 'product:SKU-1')->firstOrFail();
        $this->assertSame('gid://shopify/Product/1', $mapping->shopify_product_id);
        $this->assertNotNull($mapping->payload_hash);
        $this->assertNull($mapping->last_error);
    }

    public function test_it_groups_variants_under_their_root_model_with_option_labels(): void
    {
        ProductModel::factory()->create([
            'code' => 'amor',
            'variant_axes' => ['color', 'size'],
            'values' => ['name' => [['locale' => null, 'scope' => null, 'data' => 'Amor jacket']]],
        ]);
        ProductModel::factory()->create(['code' => 'amor_blue', 'parent' => 'amor', 'variant_axes' => null]);
        $this->variantProduct('AMOR-M', parent: 'amor_blue', size: 'm');
        $this->variantProduct('AMOR-L', parent: 'amor_blue', size: 'l');

        $this->fakeShopify();
        $this->fakeAkeneoOptionLabels();

        $this->artisan('fidelity:push-products')->assertSuccessful();

        $this->assertCount(1, $this->productSetCalls);
        $input = $this->productSetCalls[0]['input'];

        $this->assertSame('model:amor', $this->productSetCalls[0]['identifier']['customId']['value']);
        $this->assertSame('Amor jacket', $input['title']);
        $this->assertSame([
            ['name' => 'Color', 'position' => 1, 'values' => [['name' => 'Blue']]],
            ['name' => 'Size', 'position' => 2, 'values' => [['name' => 'Large'], ['name' => 'Medium']]],
        ], $input['productOptions']);
        $this->assertSame(['AMOR-L', 'AMOR-M'], array_column($input['variants'], 'sku'));
        $this->assertSame([['optionName' => 'Color', 'name' => 'Blue'], ['optionName' => 'Size', 'name' => 'Medium']], $input['variants'][1]['optionValues']);
    }

    public function test_it_sends_enabled_attributes_with_a_definition_as_metafields_with_option_labels(): void
    {
        AkeneoAttribute::factory()->simpleSelect(['600' => '60°'])->enabled()->withDefinition()->create(['code' => 'wash_temperature']);
        AkeneoAttribute::factory()->enabled()->create(['code' => 'material']);
        AkeneoAttribute::factory()->withDefinition()->create(['code' => 'supplier']);
        $product = $this->standaloneProduct('SKU-1');
        $product->update(['values' => [
            ...$product->values,
            'wash_temperature' => [['locale' => null, 'scope' => null, 'data' => '600']],
            'material' => [['locale' => null, 'scope' => null, 'data' => 'Wool']],
            'supplier' => [['locale' => null, 'scope' => null, 'data' => 'Zaro']],
        ]]);
        $this->fakeShopify();

        $this->artisan('fidelity:push-products')->assertSuccessful();

        $this->assertSame([
            ['namespace' => 'akeneo', 'key' => 'wash_temperature', 'type' => 'single_line_text_field', 'value' => '60°'],
        ], $this->productSetCalls[0]['input']['metafields']);
    }

    public function test_it_sends_no_metafield_for_an_attribute_without_a_value(): void
    {
        AkeneoAttribute::factory()->simpleSelect(['600' => '60°'])->enabled()->withDefinition()->create(['code' => 'wash_temperature']);
        $this->standaloneProduct('SKU-1');
        $this->fakeShopify();

        $this->artisan('fidelity:push-products')->assertSuccessful();

        $this->assertArrayNotHasKey('metafields', $this->productSetCalls[0]['input']);
    }

    public function test_it_does_not_start_while_another_push_holds_the_lock(): void
    {
        $this->standaloneProduct('SKU-1');
        $running = SyncRun::factory()->push()->running()->create();
        Cache::lock(SyncRun::SHOPIFY_PUSH_LOCK, 60)->get();

        $this->artisan('fidelity:push-products')
            ->expectsOutput("Not started: another run holds the fidelity:shopify-push lock. Try again when it finishes. The latest fidelity:push-products run still marked as running is #{$running->id}.")
            ->assertFailed();

        Http::assertNothingSent();
        $this->assertSame(0, ShopifyProduct::query()->count());
    }

    public function test_it_falls_back_to_the_eur_price_when_there_is_no_price_in_the_store_currency(): void
    {
        $this->standaloneProduct('EUR-ONLY', price: '19.90');
        $this->standaloneProduct('NO-PRICE');
        Product::query()->where('identifier', 'NO-PRICE')->firstOrFail()->update(['values' => [
            'name' => [['locale' => null, 'scope' => null, 'data' => 'No price']],
        ]]);
        $this->fakeShopify();

        $this->artisan('fidelity:push-products')->assertSuccessful();

        $prices = collect($this->productSetCalls)->mapWithKeys(fn (array $call): array => [
            $call['identifier']['customId']['value'] => $call['input']['variants'][0]['price'],
        ]);
        $this->assertSame('19.90', $prices['product:EUR-ONLY']);
        $this->assertSame('0.00', $prices['product:NO-PRICE']);
    }

    public function test_it_skips_unchanged_products_and_force_pushes_them_again(): void
    {
        $this->standaloneProduct('SKU-1');
        $this->fakeShopify();

        $this->artisan('fidelity:push-products')->assertSuccessful();
        $this->artisan('fidelity:push-products')->assertSuccessful();
        $this->assertCount(1, $this->productSetCalls);

        $this->artisan('fidelity:push-products --force')->assertSuccessful();
        $this->assertCount(2, $this->productSetCalls);
    }

    public function test_updates_do_not_send_the_status_so_published_products_stay_published(): void
    {
        $product = $this->standaloneProduct('SKU-1', price: '10.00');
        $this->fakeShopify();

        $this->artisan('fidelity:push-products')->assertSuccessful();

        $values = $product->values;
        $values['price'] = [['locale' => null, 'scope' => null, 'data' => [['amount' => '12.00', 'currency' => 'EUR']]]];
        $product->update(['values' => $values]);

        $this->artisan('fidelity:push-products')->assertSuccessful();

        $this->assertCount(2, $this->productSetCalls);
        $this->assertArrayNotHasKey('status', $this->productSetCalls[1]['input']);
        $this->assertSame('12.00', $this->productSetCalls[1]['input']['variants'][0]['price']);
    }

    public function test_it_records_user_errors_and_continues_with_other_products(): void
    {
        $this->standaloneProduct('SKU-BAD');
        $this->standaloneProduct('SKU-GOOD');

        $this->fakeShopify(fn (array $variables): array => str_contains($variables['identifier']['customId']['value'], 'SKU-BAD')
            ? ['product' => null, 'userErrors' => [['field' => ['input', 'title'], 'message' => 'Title is too long', 'code' => 'INVALID']]]
            : ['product' => ['id' => 'gid://shopify/Product/2'], 'userErrors' => []]);

        $this->artisan('fidelity:push-products')->assertFailed();

        $this->assertSame('Title is too long', ShopifyProduct::query()->where('akeneo_code', 'product:SKU-BAD')->value('last_error'));
        $this->assertNull(ShopifyProduct::query()->where('akeneo_code', 'product:SKU-BAD')->value('shopify_product_id'));
        $this->assertSame('gid://shopify/Product/2', ShopifyProduct::query()->where('akeneo_code', 'product:SKU-GOOD')->value('shopify_product_id'));
    }

    public function test_it_retries_a_failed_product_on_the_next_run(): void
    {
        $this->standaloneProduct('SKU-1');
        ShopifyProduct::factory()->create(['akeneo_code' => 'product:SKU-1', 'shopify_product_id' => null, 'payload_hash' => null, 'last_error' => 'Earlier failure']);
        $this->fakeShopify();

        $this->artisan('fidelity:push-products')->assertSuccessful();

        $this->assertCount(1, $this->productSetCalls);
        $this->assertSame('DRAFT', $this->productSetCalls[0]['input']['status']);
        $this->assertNull(ShopifyProduct::query()->where('akeneo_code', 'product:SKU-1')->value('last_error'));
    }

    public function test_it_fails_a_variant_whose_product_model_was_not_synced(): void
    {
        $this->variantProduct('ORPHAN', parent: 'missing_model', size: 'm');
        $this->fakeShopify();

        $this->artisan('fidelity:push-products')->assertFailed();

        $this->assertSame([], $this->productSetCalls);
        $this->assertSame(
            'Product model missing_model has not been synced from Akeneo.',
            ShopifyProduct::query()->where('akeneo_code', 'model:missing_model')->value('last_error')
        );
    }

    public function test_it_fails_a_model_with_more_variants_than_shopify_supports(): void
    {
        ProductModel::factory()->create(['code' => 'huge', 'variant_axes' => []]);
        Product::factory()
            ->count(2049)
            ->state(fn (array $attributes): array => ['raw_payload' => ['identifier' => $attributes['identifier'], 'parent' => 'huge']])
            ->create();
        $this->fakeShopify();

        $this->artisan('fidelity:push-products')->assertFailed();

        $this->assertSame([], $this->productSetCalls);
        $this->assertSame(
            'model:huge has 2049 variants, Shopify supports at most 2048.',
            ShopifyProduct::query()->where('akeneo_code', 'model:huge')->value('last_error')
        );
    }

    public function test_it_records_the_push_run_with_its_counts(): void
    {
        $this->standaloneProduct('SKU-BAD');
        $this->standaloneProduct('SKU-GOOD');
        $this->fakeShopify(fn (array $variables): array => str_contains($variables['identifier']['customId']['value'], 'SKU-BAD')
            ? ['product' => null, 'userErrors' => [['field' => ['input', 'title'], 'message' => 'Title is too long', 'code' => 'INVALID']]]
            : ['product' => ['id' => 'gid://shopify/Product/2'], 'userErrors' => []]);

        $this->artisan('fidelity:push-products --limit=5')->assertFailed();

        $run = SyncRun::query()->sole();
        $this->assertSame('fidelity:push-products', $run->command);
        $this->assertSame(SyncRunStatus::Failed, $run->status);
        $this->assertEquals(['force' => false, 'limit' => 5], $run->options);
        $this->assertEquals(['created' => 1, 'updated' => 0, 'skipped' => 0, 'failed' => 1], $run->counts);
        $this->assertStringContainsString('WARNING   product:SKU-BAD: Title is too long', Storage::disk('local')->get($run->log_path));
    }

    public function test_it_uploads_the_main_and_variant_images_once_and_attaches_them(): void
    {
        ProductModel::factory()->create([
            'code' => 'amor',
            'variant_axes' => ['size'],
            'values' => ['name' => [['locale' => null, 'scope' => null, 'data' => 'Amor jacket']]] + $this->imageValues(['image' => 'a/1/amor.jpg']),
        ]);
        $this->variantProduct('AMOR-M', parent: 'amor', size: 'm', images: ['image' => 'a/1/amor.jpg', 'variation_image' => 'b/2/amor_blue.jpg']);
        $this->variantProduct('AMOR-L', parent: 'amor', size: 'l', images: ['image' => 'a/1/amor.jpg', 'variation_image' => 'c/3/amor_red.jpg']);
        $this->variantProduct('AMOR-S', parent: 'amor', size: 's', images: ['image' => 'a/1/amor.jpg', 'variation_image' => 'b/2/amor_blue.jpg']);
        $this->fakeShopify();
        $this->fakeAkeneoOptionLabels();
        $this->fakeAkeneoImages();
        Http::fake([self::AKENEO_URL.'/api/rest/v1/attributes/size/options/s' => Http::response(['code' => 's', 'labels' => ['en_US' => 'Small']])]);

        $this->artisan('fidelity:push-products')->assertSuccessful();

        $ids = array_flip($this->createdFiles);
        $input = $this->productSetCalls[0]['input'];
        $this->assertSame([
            ['id' => $ids['amor.jpg']],
            ['id' => $ids['amor_red.jpg']],
            ['id' => $ids['amor_blue.jpg']],
        ], $input['files']);
        $this->assertSame(['AMOR-L', 'AMOR-M', 'AMOR-S'], array_column($input['variants'], 'sku'));
        $this->assertSame([['id' => $ids['amor_red.jpg']], ['id' => $ids['amor_blue.jpg']], ['id' => $ids['amor_blue.jpg']]], array_column($input['variants'], 'file'));
        $this->assertSame(3, ShopifyFile::query()->count());
        Http::assertSent(fn (Request $request): bool => $request->url() === self::AKENEO_URL.'/api/rest/v1/media-files/b/2/amor_blue.jpg/download');

        $this->artisan('fidelity:push-products --force')->assertSuccessful();

        $this->assertCount(3, $this->createdFiles);
        $this->assertSame($input['files'], $this->productSetCalls[1]['input']['files']);
    }

    public function test_it_uses_the_picture_attribute_as_the_main_image_and_sends_no_files_without_images(): void
    {
        $this->standaloneProduct('MONITOR')->update(['values' => $this->imageValues(['picture' => 'd/4/monitor.jpg'])]);
        $this->standaloneProduct('NO-IMAGE');
        $this->fakeShopify();
        $this->fakeAkeneoImages();

        $this->artisan('fidelity:push-products')->assertSuccessful();

        $files = collect($this->productSetCalls)->mapWithKeys(fn (array $call): array => [
            $call['identifier']['customId']['value'] => $call['input']['files'],
        ]);
        $this->assertSame([['id' => array_flip($this->createdFiles)['monitor.jpg']]], $files['product:MONITOR']);
        $this->assertSame([], $files['product:NO-IMAGE']);
        $this->assertArrayNotHasKey('file', $this->productSetCalls[0]['input']['variants'][0]);
    }

    public function test_an_image_shopify_cannot_process_fails_only_its_product(): void
    {
        $this->standaloneProduct('HUGE-IMAGE')->update(['values' => $this->imageValues(['image' => 'e/5/huge.jpg'])]);
        $this->standaloneProduct('SKU-OK');
        $this->failingImages = ['huge.jpg'];
        $this->fakeShopify();
        $this->fakeAkeneoImages();

        $this->artisan('fidelity:push-products')->assertFailed();

        $this->assertSame(['product:SKU-OK'], array_map(fn (array $call): string => $call['identifier']['customId']['value'], $this->productSetCalls));
        $this->assertSame(
            'Shopify could not process image huge.jpg: Image is too large',
            ShopifyProduct::query()->where('akeneo_code', 'product:HUGE-IMAGE')->value('last_error')
        );
        $this->assertSame(0, ShopifyFile::query()->count());
    }

    public function test_an_image_missing_in_akeneo_fails_only_its_product(): void
    {
        $this->standaloneProduct('GONE-IMAGE')->update(['values' => $this->imageValues(['image' => 'f/6/gone.jpg'])]);
        $this->fakeShopify();
        Http::fake([
            self::AKENEO_URL.'/api/oauth/v1/token' => Http::response(['access_token' => 'akeneo-token', 'expires_in' => 3600]),
            self::AKENEO_URL.'/api/rest/v1/media-files/*' => Http::response(['message' => 'Not found'], 404),
        ]);

        $this->artisan('fidelity:push-products')->assertFailed();

        $this->assertSame([], $this->productSetCalls);
        $this->assertSame('Image f/6/gone.jpg was not found in Akeneo.', ShopifyProduct::query()->where('akeneo_code', 'product:GONE-IMAGE')->value('last_error'));
    }

    public function test_it_respects_the_limit_option(): void
    {
        $this->standaloneProduct('SKU-1');
        $this->standaloneProduct('SKU-2');
        $this->standaloneProduct('SKU-3');
        $this->fakeShopify();

        $this->artisan('fidelity:push-products --limit=2')->assertSuccessful();

        $this->assertCount(2, $this->productSetCalls);
    }

    public function test_it_waits_and_retries_when_shopify_throttles(): void
    {
        $this->standaloneProduct('SKU-1');
        $throttledOnce = false;

        $this->fakeShopify(function (array $variables) use (&$throttledOnce): ?array {
            if (! $throttledOnce) {
                $throttledOnce = true;

                return null;
            }

            return ['product' => ['id' => 'gid://shopify/Product/1'], 'userErrors' => []];
        });

        $this->artisan('fidelity:push-products')->assertSuccessful();

        Sleep::assertSleptTimes(1);
        Sleep::assertSequence([Sleep::for(4)->seconds()]);
        $this->assertCount(2, $this->productSetCalls);
    }

    public function test_it_does_not_recreate_an_existing_metafield_definition_and_reuses_the_token(): void
    {
        $this->standaloneProduct('SKU-1');
        $this->fakeShopify(definitionExists: true);

        $this->artisan('fidelity:push-products')->assertSuccessful();
        $this->artisan('fidelity:push-products --force')->assertSuccessful();

        $this->assertFalse($this->definitionCreated);
        Http::assertSentCount(5);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::SHOP_URL.'/admin/oauth/access_token'
            && $request['grant_type'] === 'client_credentials'
            && $request['client_id'] === 'shopify-client'
            && $request['client_secret'] === 'shopify-secret');
        Http::assertSent(fn (Request $request): bool => $request->url() === self::GRAPHQL_URL
            && $request->hasHeader('X-Shopify-Access-Token', 'shopify-token'));
    }

    public function test_it_aborts_when_the_existing_definition_has_the_wrong_type(): void
    {
        $this->standaloneProduct('SKU-1');
        $this->fakeShopify(definitionExists: true, definitionType: 'single_line_text_field');

        $this->artisan('fidelity:push-products')
            ->expectsOutputToContain('must be of type id')
            ->assertFailed();

        $this->assertSame([], $this->productSetCalls);
    }

    public function test_it_aborts_when_shopify_rejects_the_credentials(): void
    {
        $this->standaloneProduct('SKU-1');

        Http::fake([
            self::SHOP_URL.'/admin/oauth/access_token' => Http::response(['error' => 'invalid_client'], 400),
        ]);

        $this->artisan('fidelity:push-products')->assertFailed();

        $this->assertSame(0, ShopifyProduct::query()->count());
    }

    /**
     * Fake the Shopify token endpoint and GraphQL API. The responder returns the `productSet` payload, or null to throttle.
     *
     * @param  (Closure(array<string, mixed>): ?array<string, mixed>)|null  $productSetResponder
     */
    private function fakeShopify(?Closure $productSetResponder = null, bool $definitionExists = false, string $definitionType = 'id'): void
    {
        $productSetResponder ??= fn (array $variables): array => ['product' => ['id' => 'gid://shopify/Product/1'], 'userErrors' => []];

        Http::fake([
            self::SHOP_URL.'/admin/oauth/access_token' => Http::response(['access_token' => 'shopify-token', 'scope' => 'write_products', 'expires_in' => 86399]),
            self::GRAPHQL_URL => function (Request $request) use ($productSetResponder, $definitionExists, $definitionType) {
                $body = json_decode($request->body(), true);
                $query = $body['query'];
                $variables = $body['variables'];

                if (str_contains($query, 'metafieldDefinitions(')) {
                    return Http::response(['data' => ['metafieldDefinitions' => ['nodes' => $definitionExists || $this->definitionCreated ? [['id' => 'gid://shopify/MetafieldDefinition/1', 'type' => ['name' => $definitionType]]] : []]]]);
                }

                if (str_contains($query, 'stagedUploadsCreate')) {
                    return Http::response(['data' => ['stagedUploadsCreate' => [
                        'stagedTargets' => [[
                            'url' => self::STAGED_UPLOAD_URL,
                            'resourceUrl' => self::STAGED_UPLOAD_URL.'/'.$variables['input'][0]['filename'],
                            'parameters' => [['name' => 'key', 'value' => 'tmp/'.$variables['input'][0]['filename']]],
                        ]],
                        'userErrors' => [],
                    ]]]);
                }

                if (str_contains($query, 'fileCreate')) {
                    $fileId = 'gid://shopify/MediaImage/'.(count($this->createdFiles) + 1);
                    $this->createdFiles[$fileId] = $variables['files'][0]['filename'];

                    return Http::response(['data' => ['fileCreate' => ['files' => [['id' => $fileId, 'fileStatus' => 'UPLOADED']], 'userErrors' => []]]]);
                }

                if (str_contains($query, 'fileStatus')) {
                    $fails = in_array($this->createdFiles[$variables['id']], $this->failingImages, true);

                    return Http::response(['data' => ['node' => $fails
                        ? ['fileStatus' => 'FAILED', 'fileErrors' => [['message' => 'Image is too large']]]
                        : ['fileStatus' => 'READY', 'fileErrors' => []]]]);
                }

                if (str_contains($query, 'metafieldDefinitionCreate')) {
                    $this->definitionCreated = true;
                    $this->createdDefinition = $variables['definition'];

                    return Http::response(['data' => ['metafieldDefinitionCreate' => ['createdDefinition' => ['id' => 'gid://shopify/MetafieldDefinition/1'], 'userErrors' => []]]]);
                }

                $this->productSetCalls[] = $variables;
                $payload = $productSetResponder($variables);

                if ($payload === null) {
                    return Http::response([
                        'errors' => [['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']]],
                        'extensions' => ['cost' => [
                            'requestedQueryCost' => 210,
                            'actualQueryCost' => null,
                            'throttleStatus' => ['maximumAvailable' => 2000, 'currentlyAvailable' => 10, 'restoreRate' => 50],
                        ]],
                    ]);
                }

                return Http::response(['data' => ['productSet' => $payload]]);
            },
        ]);
    }

    private function fakeAkeneoImages(): void
    {
        Http::fake([
            self::AKENEO_URL.'/api/oauth/v1/token' => Http::response(['access_token' => 'akeneo-token', 'expires_in' => 3600]),
            self::AKENEO_URL.'/api/rest/v1/media-files/*' => Http::response('image-bytes', 200, ['Content-Type' => 'image/jpeg']),
            self::STAGED_UPLOAD_URL => Http::response('', 201),
        ]);
    }

    /**
     * @param  array<string, string>  $images  media codes keyed by attribute code
     * @return array<string, list<array{locale: null, scope: null, data: string}>>
     */
    private function imageValues(array $images): array
    {
        return array_map(fn (string $mediaCode): array => [['locale' => null, 'scope' => null, 'data' => $mediaCode]], $images);
    }

    private function fakeAkeneoOptionLabels(): void
    {
        Http::fake([
            self::AKENEO_URL.'/api/oauth/v1/token' => Http::response(['access_token' => 'akeneo-token', 'expires_in' => 3600]),
            self::AKENEO_URL.'/api/rest/v1/attributes/color/options/blue' => Http::response(['code' => 'blue', 'labels' => ['en_US' => 'Blue', 'fr_FR' => 'Bleu']]),
            self::AKENEO_URL.'/api/rest/v1/attributes/size/options/m' => Http::response(['code' => 'm', 'labels' => ['en_US' => 'Medium']]),
            self::AKENEO_URL.'/api/rest/v1/attributes/size/options/l' => Http::response(['code' => 'l', 'labels' => ['en_US' => 'Large']]),
        ]);
    }

    private function standaloneProduct(string $identifier, string $price = '5.00', ?string $ean = null, ?string $usdPrice = null): Product
    {
        $prices = [['amount' => $price, 'currency' => 'EUR']];

        if ($usdPrice !== null) {
            $prices[] = ['amount' => $usdPrice, 'currency' => 'USD'];
        }

        $values = [
            'name' => [['locale' => null, 'scope' => null, 'data' => "Product {$identifier}"]],
            'description' => [
                ['locale' => 'fr_FR', 'scope' => 'ecommerce', 'data' => '<p>Description française</p>'],
                ['locale' => 'en_US', 'scope' => 'print', 'data' => '<p>Print description</p>'],
                ['locale' => 'en_US', 'scope' => 'ecommerce', 'data' => '<p>English description</p>'],
            ],
            'price' => [['locale' => null, 'scope' => null, 'data' => $prices]],
        ];

        if ($ean !== null) {
            $values['ean'] = [['locale' => null, 'scope' => null, 'data' => $ean]];
        }

        return Product::factory()->create([
            'identifier' => $identifier,
            'family' => 'accessories',
            'values' => $values,
            'raw_payload' => ['identifier' => $identifier, 'parent' => null],
        ]);
    }

    /**
     * @param  array<string, string>  $images  media codes keyed by attribute code
     */
    private function variantProduct(string $identifier, string $parent, string $size, array $images = []): Product
    {
        return Product::factory()->create([
            'identifier' => $identifier,
            'family' => 'clothing',
            'values' => [
                'color' => [['locale' => null, 'scope' => null, 'data' => 'blue']],
                'size' => [['locale' => null, 'scope' => null, 'data' => $size]],
                'price' => [['locale' => null, 'scope' => null, 'data' => [['amount' => '99.00', 'currency' => 'EUR']]]],
            ] + $this->imageValues($images),
            'raw_payload' => ['identifier' => $identifier, 'parent' => $parent],
        ]);
    }
}

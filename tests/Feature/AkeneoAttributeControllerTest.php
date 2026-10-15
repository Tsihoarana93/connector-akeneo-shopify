<?php

namespace Tests\Feature;

use App\Models\AkeneoAttribute;
use App\Models\Product;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AkeneoAttributeControllerTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP_URL = 'https://test-shop.myshopify.com';

    private const GRAPHQL_URL = self::SHOP_URL.'/admin/api/2026-10/graphql.json';

    private const AKENEO_URL = 'https://akeneo.test';

    private User $user;

    /**
     * Variables of every metafieldDefinitionCreate mutation sent to Shopify.
     *
     * @var list<array<string, mixed>>
     */
    private array $createdDefinitions = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.shopify.shop' => 'test-shop',
            'services.shopify.client_id' => 'shopify-client',
            'services.shopify.client_secret' => 'shopify-secret',
            'services.shopify.api_version' => '2026-10',
            'services.akeneo.base_url' => self::AKENEO_URL,
        ]);

        $this->withoutVite();
        Http::preventStrayRequests();

        $this->user = User::factory()->create();
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('attributes.index'))->assertRedirect(route('login'));
    }

    public function test_the_index_shows_usage_and_sample_labels_and_filters_attributes_with_values(): void
    {
        AkeneoAttribute::factory()->simpleSelect(['600' => '60°', '400' => '40°'])->create(['code' => 'wash_temperature']);
        AkeneoAttribute::factory()->create(['code' => 'unused_attribute']);
        Product::factory()->count(2)->create(['values' => ['wash_temperature' => [['locale' => null, 'scope' => null, 'data' => '600']]]]);

        $this->actingAs($this->user)
            ->get(route('attributes.index', ['filter' => 'with_values']))
            ->assertOk()
            ->assertSee('wash_temperature')
            ->assertSee('60°')
            ->assertDontSee('unused_attribute')
            ->assertViewHas('usage', fn (array $usage): bool => $usage['wash_temperature']['products'] === 2);
    }

    public function test_the_search_ignores_case_in_labels_and_codes(): void
    {
        AkeneoAttribute::factory()->create(['code' => 'main_color', 'labels' => ['en_US' => 'Color']]);
        AkeneoAttribute::factory()->create(['code' => 'material', 'labels' => ['en_US' => 'Fabric']]);

        $this->actingAs($this->user)
            ->get(route('attributes.index', ['search' => 'fABRIC']))
            ->assertOk()
            ->assertViewHas('attributes', fn ($attributes): bool => $attributes->pluck('code')->all() === ['material']);

        $this->actingAs($this->user)
            ->get(route('attributes.index', ['search' => 'MAIN']))
            ->assertViewHas('attributes', fn ($attributes): bool => $attributes->pluck('code')->all() === ['main_color']);
    }

    public function test_the_search_treats_wildcards_as_plain_characters(): void
    {
        AkeneoAttribute::factory()->create(['code' => 'wash_temperature']);
        AkeneoAttribute::factory()->create(['code' => 'washxtemperature']);

        $this->actingAs($this->user)
            ->get(route('attributes.index', ['search' => 'wash_']))
            ->assertViewHas('attributes', fn ($attributes): bool => $attributes->pluck('code')->all() === ['wash_temperature']);

        $this->actingAs($this->user)
            ->get(route('attributes.index', ['search' => '%']))
            ->assertViewHas('attributes', fn ($attributes): bool => $attributes->isEmpty());
    }

    public function test_the_index_shows_when_the_attributes_were_last_retrieved(): void
    {
        AkeneoAttribute::factory()->create(['synced_at' => '2026-10-15 09:00:00']);
        AkeneoAttribute::factory()->create(['synced_at' => '2026-10-15 10:30:00']);

        $this->actingAs($this->user)
            ->get(route('attributes.index'))
            ->assertOk()
            ->assertSee('2026-10-15 10:30:00');
    }

    public function test_update_from_akeneo_retrieves_the_attributes_and_keeps_their_shopify_mapping(): void
    {
        $this->travelTo('2026-10-15 11:00:00');
        AkeneoAttribute::factory()->stale()->enabled()->withDefinition()->create([
            'code' => 'wash_temperature',
            'type' => 'pim_catalog_text',
            'shopify_key' => 'washing',
        ]);
        AkeneoAttribute::factory()->stale()->create(['code' => 'gone_attribute']);
        $this->fakeAkeneo([
            ['code' => 'wash_temperature', 'type' => 'pim_catalog_simpleselect', 'labels' => ['en_US' => 'Wash temperature']],
            ['code' => 'material', 'type' => 'pim_catalog_text', 'labels' => ['en_US' => 'Material']],
        ]);

        $this->actingAs($this->user)
            ->from(route('attributes.index'))
            ->post(route('attributes.sync'))
            ->assertRedirect(route('attributes.index'))
            ->assertSessionHas('status', '2 attributes retrieved from Akeneo. 1 local attributes are no longer in Akeneo; run a sync with Prune to delete them.');

        $washTemperature = AkeneoAttribute::query()->where('code', 'wash_temperature')->firstOrFail();
        $this->assertSame('pim_catalog_simpleselect', $washTemperature->type);
        $this->assertSame(['600' => ['en_US' => '60°']], $washTemperature->options);
        $this->assertSame('washing', $washTemperature->shopify_key);
        $this->assertNotNull($washTemperature->shopify_definition_id);
        $this->assertDatabaseHas('akeneo_attributes', ['code' => 'material']);
        $this->assertDatabaseHas('akeneo_attributes', ['code' => 'gone_attribute']);
        $this->assertSame('2026-10-15 11:00:00', AkeneoAttribute::lastSyncedAt()->format('Y-m-d H:i:s'));
    }

    public function test_update_from_akeneo_waits_for_a_running_sync(): void
    {
        $attribute = AkeneoAttribute::factory()->stale()->create();
        Cache::lock(SyncRun::AKENEO_WRITES_LOCK, 60)->get();

        $this->actingAs($this->user)
            ->post(route('attributes.sync'))
            ->assertSessionHasErrors(['sync' => 'A sync from Akeneo is running; it updates the attributes too. Try again when it finishes.']);

        Http::assertNothingSent();
        $this->assertTrue($attribute->refresh()->synced_at->isYesterday());
    }

    public function test_update_from_akeneo_releases_the_lock(): void
    {
        $this->fakeAkeneo([]);

        $this->actingAs($this->user)->post(route('attributes.sync'))->assertSessionHasNoErrors();

        $this->assertTrue(Cache::lock(SyncRun::AKENEO_WRITES_LOCK, 60)->get());
    }

    public function test_update_from_akeneo_shows_an_error_when_akeneo_fails(): void
    {
        $attribute = AkeneoAttribute::factory()->stale()->create();
        Http::fake([
            self::AKENEO_URL.'/api/oauth/v1/token' => Http::response(['access_token' => 'token-1', 'expires_in' => 3600]),
            self::AKENEO_URL.'/api/rest/v1/attributes*' => Http::response(['message' => 'Server error'], 500),
        ]);

        $this->actingAs($this->user)
            ->post(route('attributes.sync'))
            ->assertSessionHasErrors('sync');

        $this->assertTrue($attribute->refresh()->synced_at->isYesterday());
    }

    public function test_update_from_akeneo_shows_an_error_when_akeneo_returns_no_token(): void
    {
        Http::fake([self::AKENEO_URL.'/api/oauth/v1/token' => Http::response(['expires_in' => 3600])]);

        $this->actingAs($this->user)
            ->post(route('attributes.sync'))
            ->assertSessionHasErrors(['sync' => 'Akeneo could not be reached: Akeneo did not return an access token.']);

        $this->assertTrue(Cache::lock(SyncRun::AKENEO_WRITES_LOCK, 60)->get());
    }

    public function test_the_show_page_lists_options_with_their_product_counts(): void
    {
        $attribute = AkeneoAttribute::factory()->simpleSelect(['600' => '60°', 'hand' => 'Hand wash'])->create(['code' => 'wash_temperature']);
        Product::factory()->create(['values' => ['wash_temperature' => [['locale' => null, 'scope' => null, 'data' => 'hand']]]]);

        $this->actingAs($this->user)
            ->get(route('attributes.show', $attribute))
            ->assertOk()
            ->assertSeeInOrder(['hand', 'Hand wash', '1'])
            ->assertViewHas('usage', fn (array $usage): bool => $usage['options'] === ['hand' => 1]);
    }

    public function test_update_saves_the_shopify_mapping(): void
    {
        $attribute = AkeneoAttribute::factory()->simpleSelect(['600' => '60°'])->create(['code' => 'wash_temperature']);

        $this->actingAs($this->user)
            ->put(route('attributes.update', $attribute), [
                'shopify_enabled' => '1',
                'shopify_name' => 'Washing temperature',
                'shopify_key' => 'washing_temperature',
                'shopify_type' => 'single_line_text_field',
            ])
            ->assertRedirect(route('attributes.show', $attribute))
            ->assertSessionHasNoErrors();

        $attribute->refresh();
        $this->assertTrue($attribute->shopify_enabled);
        $this->assertSame('Washing temperature', $attribute->shopify_name);
        $this->assertSame('washing_temperature', $attribute->shopify_key);
        $this->assertSame('single_line_text_field', $attribute->shopify_type);
    }

    public function test_update_rejects_an_invalid_metafield_key(): void
    {
        $attribute = AkeneoAttribute::factory()->create();

        $this->actingAs($this->user)
            ->put(route('attributes.update', $attribute), [
                'shopify_enabled' => '1',
                'shopify_name' => 'Material',
                'shopify_key' => 'wash temperature',
                'shopify_type' => 'single_line_text_field',
            ])
            ->assertSessionHasErrors(['shopify_key' => 'The key must be 2 to 64 letters, digits, underscores or hyphens.']);

        $this->assertFalse($attribute->refresh()->shopify_enabled);
    }

    public function test_update_rejects_the_key_reserved_for_the_custom_id(): void
    {
        $attribute = AkeneoAttribute::factory()->create();

        $this->actingAs($this->user)
            ->put(route('attributes.update', $attribute), [
                'shopify_enabled' => '1',
                'shopify_name' => 'Code',
                'shopify_key' => 'code',
                'shopify_type' => 'single_line_text_field',
            ])
            ->assertSessionHasErrors(['shopify_key' => 'akeneo.code is reserved for the product custom ID.']);
    }

    public function test_update_rejects_a_key_used_by_another_attribute(): void
    {
        AkeneoAttribute::factory()->create(['code' => 'material']);
        $attribute = AkeneoAttribute::factory()->create();

        $this->actingAs($this->user)
            ->put(route('attributes.update', $attribute), [
                'shopify_enabled' => '1',
                'shopify_name' => 'Material',
                'shopify_key' => 'material',
                'shopify_type' => 'single_line_text_field',
            ])
            ->assertSessionHasErrors(['shopify_key' => 'Another attribute already uses this key.']);
    }

    public function test_update_rejects_a_shopify_type_the_attribute_cannot_be_sent_as(): void
    {
        $attribute = AkeneoAttribute::factory()->simpleSelect(['600' => '60°'])->create();

        $this->actingAs($this->user)
            ->put(route('attributes.update', $attribute), [
                'shopify_enabled' => '1',
                'shopify_name' => 'Wash temperature',
                'shopify_key' => 'wash_temperature',
                'shopify_type' => 'number_integer',
            ])
            ->assertSessionHasErrors('shopify_type');
    }

    public function test_update_refuses_to_enable_an_unsupported_attribute(): void
    {
        $attribute = AkeneoAttribute::factory()->create(['type' => 'pim_catalog_image']);

        $this->actingAs($this->user)
            ->put(route('attributes.update', $attribute), ['shopify_enabled' => '1'])
            ->assertSessionHasErrors(['shopify_enabled' => 'Akeneo attributes of type pim_catalog_image cannot be sent to Shopify.']);

        $this->assertFalse($attribute->refresh()->shopify_enabled);
    }

    public function test_update_keeps_the_name_key_and_type_once_the_definition_exists(): void
    {
        $attribute = AkeneoAttribute::factory()->enabled()->withDefinition()->create(['shopify_key' => 'material']);

        $this->actingAs($this->user)
            ->put(route('attributes.update', $attribute), [
                'shopify_enabled' => '0',
                'shopify_key' => 'other_key',
                'shopify_type' => 'multi_line_text_field',
            ])
            ->assertSessionHasNoErrors();

        $attribute->refresh();
        $this->assertFalse($attribute->shopify_enabled);
        $this->assertSame('material', $attribute->shopify_key);
        $this->assertNull($attribute->shopify_type);
    }

    public function test_it_creates_the_shopify_definition_of_an_enabled_attribute(): void
    {
        $attribute = AkeneoAttribute::factory()->simpleSelect(['600' => '60°'])->enabled()->create([
            'code' => 'wash_temperature',
            'labels' => ['en_US' => 'Wash temperature'],
        ]);
        $this->fakeShopify();

        $this->actingAs($this->user)
            ->from(route('attributes.show', $attribute))
            ->post(route('attributes.definition.store', $attribute))
            ->assertRedirect(route('attributes.show', $attribute))
            ->assertSessionHas('status', 'The Shopify definition akeneo.wash_temperature is ready.');

        $this->assertSame([
            'name' => 'Wash temperature',
            'namespace' => 'akeneo',
            'key' => 'wash_temperature',
            'type' => 'single_line_text_field',
            'description' => 'Akeneo attribute wash_temperature',
            'pin' => true,
            'ownerType' => 'PRODUCT',
        ], $this->createdDefinitions[0]);

        $attribute->refresh();
        $this->assertSame('gid://shopify/MetafieldDefinition/9', $attribute->shopify_definition_id);
        $this->assertSame('wash_temperature', $attribute->shopify_key);
        $this->assertNull($attribute->shopify_definition_error);
    }

    public function test_it_links_a_definition_that_already_exists_with_the_same_type(): void
    {
        $attribute = AkeneoAttribute::factory()->enabled()->create(['code' => 'material']);
        $this->fakeShopify(existingType: 'single_line_text_field');

        $this->actingAs($this->user)
            ->post(route('attributes.definition.store', $attribute))
            ->assertSessionHasNoErrors();

        $this->assertSame([], $this->createdDefinitions);
        $this->assertSame('gid://shopify/MetafieldDefinition/1', $attribute->refresh()->shopify_definition_id);
    }

    public function test_it_records_an_error_when_a_definition_exists_with_another_type(): void
    {
        $attribute = AkeneoAttribute::factory()->enabled()->create(['code' => 'material']);
        $this->fakeShopify(existingType: 'multi_line_text_field');

        $message = 'A akeneo.material definition of type multi_line_text_field already exists in Shopify. Choose that type, or delete the definition in Shopify.';

        $this->actingAs($this->user)
            ->post(route('attributes.definition.store', $attribute))
            ->assertSessionHasErrors(['definition' => $message]);

        $attribute->refresh();
        $this->assertNull($attribute->shopify_definition_id);
        $this->assertSame($message, $attribute->shopify_definition_error);
    }

    public function test_it_does_not_create_a_definition_for_a_disabled_attribute(): void
    {
        $attribute = AkeneoAttribute::factory()->create();

        $this->actingAs($this->user)
            ->post(route('attributes.definition.store', $attribute))
            ->assertSessionHasErrors(['definition' => 'Enable the attribute before creating its Shopify definition.']);

        Http::assertNothingSent();
    }

    public function test_it_creates_every_missing_definition_of_enabled_attributes(): void
    {
        AkeneoAttribute::factory()->enabled()->create(['code' => 'material']);
        AkeneoAttribute::factory()->enabled()->create(['code' => 'origin']);
        AkeneoAttribute::factory()->enabled()->withDefinition()->create(['code' => 'linked']);
        AkeneoAttribute::factory()->create(['code' => 'disabled']);
        $this->fakeShopify();

        $this->actingAs($this->user)
            ->post(route('attributes.definitions.store'))
            ->assertSessionHas('status', '2 Shopify definitions are ready.');

        $this->assertSame(['material', 'origin'], array_column($this->createdDefinitions, 'key'));
    }

    /**
     * @param  list<array<string, mixed>>  $attributes
     */
    private function fakeAkeneo(array $attributes): void
    {
        Http::fake([
            self::AKENEO_URL.'/api/oauth/v1/token' => Http::response(['access_token' => 'token-1', 'expires_in' => 3600]),
            self::AKENEO_URL.'/api/rest/v1/attributes/wash_temperature/options*' => Http::response(['_embedded' => ['items' => [
                ['code' => '600', 'attribute' => 'wash_temperature', 'labels' => ['en_US' => '60°']],
            ]]]),
            self::AKENEO_URL.'/api/rest/v1/attributes*' => Http::response(['_embedded' => ['items' => $attributes]]),
        ]);
    }

    private function fakeShopify(?string $existingType = null): void
    {
        Http::fake([
            self::SHOP_URL.'/admin/oauth/access_token' => Http::response(['access_token' => 'shopify-token', 'expires_in' => 86399]),
            self::GRAPHQL_URL => function (Request $request) use ($existingType) {
                $body = json_decode($request->body(), true);

                if (str_contains($body['query'], 'metafieldDefinitions(')) {
                    $nodes = $existingType === null ? [] : [['id' => 'gid://shopify/MetafieldDefinition/1', 'type' => ['name' => $existingType]]];

                    return Http::response(['data' => ['metafieldDefinitions' => ['nodes' => $nodes]]]);
                }

                $this->createdDefinitions[] = $body['variables']['definition'];

                return Http::response(['data' => ['metafieldDefinitionCreate' => [
                    'createdDefinition' => ['id' => 'gid://shopify/MetafieldDefinition/9'],
                    'userErrors' => [],
                ]]]);
            },
        ]);
    }
}

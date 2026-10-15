<?php

namespace Tests\Feature;

use Antonny\AkeneoMcp\Servers\AkeneoServer;
use Antonny\AkeneoMcp\Tools\GetProductsTool;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

class AkeneoMcpGetProductsToolTest extends TestCase
{
    private const BASE_URL = 'https://akeneo.test';

    private const TOKEN_URL = self::BASE_URL.'/api/oauth/v1/token';

    private const PRODUCTS_URL = self::BASE_URL.'/api/rest/v1/products*';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'akeneo-mcp.base_url' => self::BASE_URL,
            'akeneo-mcp.client_id' => 'client',
            'akeneo-mcp.client_secret' => 'secret',
            'akeneo-mcp.username' => 'user',
            'akeneo-mcp.password' => 'pass',
        ]);

        Http::preventStrayRequests();
        Sleep::fake();
    }

    public function test_it_returns_products_and_reuses_the_cached_token(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'token-1', 'expires_in' => 3600]),
            self::PRODUCTS_URL => Http::response($this->productsPayload()),
        ]);

        AkeneoServer::tool(GetProductsTool::class, ['limit' => 10])
            ->assertOk()
            ->assertSee('shirt-blue-m');

        AkeneoServer::tool(GetProductsTool::class, ['limit' => 10])->assertOk();

        Http::assertSentCount(3);
        $this->assertCount(1, Http::recorded(fn (Request $request): bool => $request->url() === self::TOKEN_URL));
    }

    public function test_it_refreshes_an_expired_token_once_and_retries(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::sequence()
                ->push(['access_token' => 'expired-token', 'expires_in' => 3600])
                ->push(['access_token' => 'fresh-token', 'expires_in' => 3600]),
            self::PRODUCTS_URL => Http::sequence()
                ->push(['message' => 'The access token provided has expired.'], 401)
                ->push($this->productsPayload()),
        ]);

        AkeneoServer::tool(GetProductsTool::class)
            ->assertOk()
            ->assertSee('shirt-blue-m');

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), self::BASE_URL.'/api/rest/v1/products')
            && $request->hasHeader('Authorization', 'Bearer fresh-token'));
    }

    public function test_it_returns_an_error_when_authentication_fails(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['message' => 'Invalid credentials'], 401),
        ]);

        AkeneoServer::tool(GetProductsTool::class)
            ->assertHasErrors(['Akeneo API error: 401']);

        Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), self::BASE_URL.'/api/rest/v1/products'));
    }

    public function test_it_sends_the_filters_to_akeneo(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'token-1', 'expires_in' => 3600]),
            self::PRODUCTS_URL => Http::response($this->productsPayload()),
        ]);

        AkeneoServer::tool(GetProductsTool::class, [
            'search' => 'shirt',
            'family' => 'shirts',
            'enabled' => true,
            'attributes' => 'name,description',
            'locale' => 'en_US',
            'limit' => 25,
            'page' => 2,
        ])->assertOk();

        Http::assertSent(function (Request $request): bool {
            if (! str_starts_with($request->url(), self::BASE_URL.'/api/rest/v1/products')) {
                return false;
            }

            return $request['limit'] === 25
                && $request['page'] === 2
                && $request['with_count'] === 'false'
                && $request['attributes'] === 'name,description'
                && $request['locales'] === 'en_US'
                && json_decode($request['search'], true) === [
                    'identifier' => [['operator' => 'CONTAINS', 'value' => 'shirt']],
                    'family' => [['operator' => 'IN', 'value' => ['shirts']]],
                    'enabled' => [['operator' => '=', 'value' => true]],
                ];
        });
    }

    public function test_it_rejects_a_limit_above_akeneo_maximum(): void
    {
        Http::fake();

        AkeneoServer::tool(GetProductsTool::class, ['limit' => 500])
            ->assertHasErrors(['limit']);

        Http::assertNothingSent();
    }

    public function test_it_returns_structured_content(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'token-1', 'expires_in' => 3600]),
            self::PRODUCTS_URL => Http::response($this->productsPayload()),
        ]);

        AkeneoServer::tool(GetProductsTool::class)
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('total', 1)
                ->where('page', 1)
                ->where('limit', 10)
                ->where('products.0.identifier', 'shirt-blue-m')
                ->etc());
    }

    /**
     * @return array<string, mixed>
     */
    private function productsPayload(): array
    {
        return [
            'items_count' => 1,
            '_embedded' => [
                'items' => [
                    [
                        'identifier' => 'shirt-blue-m',
                        'enabled' => true,
                        'family' => 'shirts',
                        'categories' => ['summer'],
                        'values' => [],
                    ],
                ],
            ],
        ];
    }
}

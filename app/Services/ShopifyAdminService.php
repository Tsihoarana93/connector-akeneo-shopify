<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ShopifyAdminService
{
    public const TOKEN_CACHE_KEY = 'shopify_access_token';

    public const AKENEO_CODE_NAMESPACE = 'akeneo';

    public const AKENEO_CODE_KEY = 'code';

    /**
     * Shopify only accepts `id` metafield definitions as productSet custom IDs.
     */
    public const AKENEO_CODE_TYPE = 'id';

    private const MAX_THROTTLE_RETRIES = 5;

    /**
     * How many times to check, one second apart, whether Shopify has finished processing an uploaded file.
     */
    private const FILE_READY_CHECKS = 10;

    /**
     * Get a cached access token, requesting a new one when none is cached.
     *
     * @throws RequestException|ConnectionException
     */
    public function getAccessToken(): string
    {
        $token = Cache::get(self::TOKEN_CACHE_KEY);

        if (is_string($token) && $token !== '') {
            return $token;
        }

        return $this->refreshAccessToken();
    }

    /**
     * Request a new access token with the client credentials grant and cache it slightly shorter than its lifetime.
     *
     * @throws RequestException|ConnectionException
     */
    public function refreshAccessToken(): string
    {
        $response = Http::asForm()
            ->timeout(30)
            ->retry(3, 500, fn (Throwable $exception): bool => $exception instanceof ConnectionException)
            ->post($this->shopUrl().'/admin/oauth/access_token', [
                'grant_type' => 'client_credentials',
                'client_id' => config('services.shopify.client_id'),
                'client_secret' => config('services.shopify.client_secret'),
            ])
            ->throw();

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Shopify did not return an access token.');
        }

        $expiresIn = (int) $response->json('expires_in', 86399);

        Cache::put(self::TOKEN_CACHE_KEY, $token, max($expiresIn - 60, 60));

        return $token;
    }

    /**
     * Run a GraphQL Admin API operation and return its `data`, waiting and retrying when Shopify throttles the request.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException|RuntimeException
     */
    public function graphql(string $query, array $variables = []): array
    {
        for ($attempt = 1; ; $attempt++) {
            $body = $this->request()
                ->post($this->graphqlUrl(), ['query' => $query, 'variables' => (object) $variables])
                ->throw()
                ->json();

            $errors = $body['errors'] ?? [];

            if ($errors === []) {
                return $body['data'] ?? [];
            }

            if ($this->isThrottled($errors) && $attempt < self::MAX_THROTTLE_RETRIES) {
                Sleep::for($this->throttleWaitSeconds($body))->seconds();

                continue;
            }

            throw new RuntimeException('Shopify GraphQL error: '.collect($errors)->pluck('message')->implode('; '));
        }
    }

    /**
     * Create the `akeneo.code` product metafield definition with unique values, so it can be used as a productSet custom ID.
     *
     * @throws RequestException|ConnectionException|RuntimeException
     */
    public function ensureAkeneoCodeDefinition(): void
    {
        $definition = $this->findProductMetafieldDefinition(self::AKENEO_CODE_NAMESPACE, self::AKENEO_CODE_KEY);

        if ($definition !== null) {
            if ($definition['type'] !== self::AKENEO_CODE_TYPE) {
                throw new RuntimeException('The akeneo.code metafield definition must be of type '.self::AKENEO_CODE_TYPE.' to be used as a custom ID; delete it in Shopify so it can be recreated.');
            }

            return;
        }

        $this->createProductMetafieldDefinition([
            'name' => 'Akeneo code',
            'namespace' => self::AKENEO_CODE_NAMESPACE,
            'key' => self::AKENEO_CODE_KEY,
            'type' => self::AKENEO_CODE_TYPE,
            'capabilities' => ['uniqueValues' => ['enabled' => true]],
        ]);
    }

    /**
     * Find a product metafield definition by namespace and key.
     *
     * @return array{id: string, type: ?string}|null
     *
     * @throws RequestException|ConnectionException|RuntimeException
     */
    public function findProductMetafieldDefinition(string $namespace, string $key): ?array
    {
        $result = $this->graphql(<<<'GRAPHQL'
            query ProductMetafieldDefinition($namespace: String!, $key: String!) {
              metafieldDefinitions(ownerType: PRODUCT, namespace: $namespace, key: $key, first: 1) {
                nodes { id type { name } }
              }
            }
            GRAPHQL, ['namespace' => $namespace, 'key' => $key]);

        $definition = $result['metafieldDefinitions']['nodes'][0] ?? null;

        return $definition === null ? null : ['id' => $definition['id'], 'type' => $definition['type']['name'] ?? null];
    }

    /**
     * Create a product metafield definition and return its ID.
     *
     * @param  array<string, mixed>  $definition  a `MetafieldDefinitionInput` without `ownerType`
     *
     * @throws RequestException|ConnectionException|RuntimeException when the request fails or Shopify rejects the definition
     */
    public function createProductMetafieldDefinition(array $definition): string
    {
        $result = $this->graphql(<<<'GRAPHQL'
            mutation CreateProductMetafieldDefinition($definition: MetafieldDefinitionInput!) {
              metafieldDefinitionCreate(definition: $definition) {
                createdDefinition { id }
                userErrors { field message code }
              }
            }
            GRAPHQL, ['definition' => [...$definition, 'ownerType' => 'PRODUCT']]);

        $userErrors = $result['metafieldDefinitionCreate']['userErrors'] ?? [];

        if ($userErrors !== []) {
            throw new RuntimeException("Could not create the {$definition['namespace']}.{$definition['key']} metafield definition: ".collect($userErrors)->pluck('message')->implode('; '));
        }

        $id = $result['metafieldDefinitionCreate']['createdDefinition']['id'] ?? null;

        if (! is_string($id)) {
            throw new RuntimeException("Shopify did not return the {$definition['namespace']}.{$definition['key']} metafield definition.");
        }

        return $id;
    }

    /**
     * Create or update a product identified by its Akeneo code.
     *
     * @param  array<string, mixed>  $input
     * @return array{productId: ?string, userErrors: list<array{field: ?list<string>, message: string, code: ?string}>}
     *
     * @throws RequestException|ConnectionException|RuntimeException
     */
    public function productSet(array $input, string $akeneoCode): array
    {
        $result = $this->graphql(<<<'GRAPHQL'
            mutation PushProduct($identifier: ProductSetIdentifiers!, $input: ProductSetInput!) {
              productSet(identifier: $identifier, input: $input, synchronous: true) {
                product { id }
                userErrors { field message code }
              }
            }
            GRAPHQL, [
            'identifier' => ['customId' => [
                'namespace' => self::AKENEO_CODE_NAMESPACE,
                'key' => self::AKENEO_CODE_KEY,
                'value' => $akeneoCode,
            ]],
            'input' => $input,
        ]);

        return [
            'productId' => $result['productSet']['product']['id'] ?? null,
            'userErrors' => $result['productSet']['userErrors'] ?? [],
        ];
    }

    /**
     * Upload an image to Shopify's Files with a staged upload, and return its file ID once Shopify has processed it.
     *
     * @throws InvalidArgumentException when Shopify rejects the image
     * @throws RequestException|ConnectionException|RuntimeException
     */
    public function uploadImage(string $filename, string $mimeType, string $contents, string $alt): string
    {
        $staged = $this->graphql(<<<'GRAPHQL'
            mutation StageImage($input: [StagedUploadInput!]!) {
              stagedUploadsCreate(input: $input) {
                stagedTargets { url resourceUrl parameters { name value } }
                userErrors { field message }
              }
            }
            GRAPHQL, ['input' => [[
            'resource' => 'IMAGE',
            'filename' => $filename,
            'mimeType' => $mimeType,
            'httpMethod' => 'POST',
            'fileSize' => (string) strlen($contents),
        ]]]);

        $this->throwOnUserErrors("Could not stage image {$filename}", $staged['stagedUploadsCreate']['userErrors'] ?? []);
        $target = $staged['stagedUploadsCreate']['stagedTargets'][0] ?? throw new RuntimeException("Shopify returned no upload target for image {$filename}.");

        try {
            Http::attach('file', $contents, $filename)
                ->timeout(120)
                ->retry(3, 500, fn (Throwable $exception): bool => $exception instanceof ConnectionException)
                ->post($target['url'], collect($target['parameters'])->pluck('value', 'name')->all())
                ->throw();
        } catch (RequestException $exception) {
            throw new InvalidArgumentException("Uploading image {$filename} failed with HTTP {$exception->response->status()}.");
        }

        $created = $this->graphql(<<<'GRAPHQL'
            mutation CreateImage($files: [FileCreateInput!]!) {
              fileCreate(files: $files) {
                files { id fileStatus }
                userErrors { field message code }
              }
            }
            GRAPHQL, ['files' => [[
            'originalSource' => $target['resourceUrl'],
            'contentType' => 'IMAGE',
            'filename' => $filename,
            'alt' => $alt,
        ]]]);

        $this->throwOnUserErrors("Could not create image {$filename}", $created['fileCreate']['userErrors'] ?? []);
        $fileId = $created['fileCreate']['files'][0]['id'] ?? throw new RuntimeException("Shopify returned no file for image {$filename}.");

        $this->waitUntilFileIsProcessed($fileId, $filename);

        return $fileId;
    }

    /**
     * Wait until Shopify has processed a file. A file still processing after the last check is used anyway.
     *
     * @throws InvalidArgumentException when Shopify could not process the file
     * @throws RequestException|ConnectionException|RuntimeException
     */
    private function waitUntilFileIsProcessed(string $fileId, string $filename): void
    {
        for ($check = 1; $check <= self::FILE_READY_CHECKS; $check++) {
            $file = $this->graphql(<<<'GRAPHQL'
                query FileStatus($id: ID!) {
                  node(id: $id) { ... on MediaImage { fileStatus fileErrors { message } } }
                }
                GRAPHQL, ['id' => $fileId])['node'] ?? [];

            $status = $file['fileStatus'] ?? null;

            if ($status === 'READY') {
                return;
            }

            if ($status === 'FAILED') {
                $reason = collect($file['fileErrors'] ?? [])->pluck('message')->implode('; ') ?: 'unknown error';

                throw new InvalidArgumentException("Shopify could not process image {$filename}: {$reason}");
            }

            Sleep::for(1)->second();
        }
    }

    /**
     * @param  list<array{message: string}>  $userErrors
     *
     * @throws InvalidArgumentException
     */
    private function throwOnUserErrors(string $context, array $userErrors): void
    {
        if ($userErrors !== []) {
            throw new InvalidArgumentException("{$context}: ".collect($userErrors)->pluck('message')->implode('; '));
        }
    }

    /**
     * Build an authenticated request that retries connection failures and refreshes an expired token once.
     */
    private function request(): PendingRequest
    {
        $hasRefreshedToken = false;

        return Http::withHeaders(['X-Shopify-Access-Token' => $this->getAccessToken()])
            ->acceptJson()
            ->timeout(60)
            ->retry(3, 500, function (Throwable $exception, PendingRequest $request) use (&$hasRefreshedToken): bool {
                if ($exception instanceof ConnectionException) {
                    return true;
                }

                if ($exception instanceof RequestException && $exception->response->status() === 401 && ! $hasRefreshedToken) {
                    $hasRefreshedToken = true;
                    Cache::forget(self::TOKEN_CACHE_KEY);
                    $request->withHeaders(['X-Shopify-Access-Token' => $this->refreshAccessToken()]);

                    return true;
                }

                return $exception instanceof RequestException && in_array($exception->response->status(), [429, 502, 503, 504], true);
            });
    }

    /**
     * @param  list<array<string, mixed>>  $errors
     */
    private function isThrottled(array $errors): bool
    {
        return collect($errors)->contains(fn (array $error): bool => ($error['extensions']['code'] ?? null) === 'THROTTLED');
    }

    /**
     * Seconds to wait until the bucket has restored enough points for the requested cost.
     *
     * @param  array<string, mixed>  $body
     */
    private function throttleWaitSeconds(array $body): int
    {
        $cost = $body['extensions']['cost'] ?? [];
        $requested = (float) ($cost['requestedQueryCost'] ?? 0);
        $available = (float) ($cost['throttleStatus']['currentlyAvailable'] ?? 0);
        $restoreRate = (float) ($cost['throttleStatus']['restoreRate'] ?? 0);

        if ($restoreRate <= 0) {
            return 1;
        }

        return max(1, (int) ceil(($requested - $available) / $restoreRate));
    }

    private function shopUrl(): string
    {
        $shop = (string) config('services.shopify.shop');

        if (blank($shop) || blank(config('services.shopify.client_id')) || blank(config('services.shopify.client_secret'))) {
            throw new RuntimeException('Shopify is not configured. Set SHOPIFY_SHOP, SHOPIFY_CLIENT_ID and SHOPIFY_CLIENT_SECRET in .env.');
        }

        if (! str_contains($shop, '.')) {
            $shop .= '.myshopify.com';
        }

        return 'https://'.preg_replace('#^https?://#', '', rtrim($shop, '/'));
    }

    private function graphqlUrl(): string
    {
        return $this->shopUrl().'/admin/api/'.config('services.shopify.api_version').'/graphql.json';
    }
}

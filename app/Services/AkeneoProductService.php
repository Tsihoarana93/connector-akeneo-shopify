<?php

namespace App\Services;

use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Mime\MimeTypes;
use Throwable;

class AkeneoProductService
{
    public const TOKEN_CACHE_KEY = 'akeneo_access_token';

    /**
     * Akeneo's maximum page size for product listing.
     */
    public const MAX_PAGE_SIZE = 100;

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
     * Request a new access token and cache it slightly shorter than its lifetime.
     *
     * @throws RequestException|ConnectionException
     */
    public function refreshAccessToken(): string
    {
        $response = Http::withBasicAuth(
            (string) config('services.akeneo.client_id'),
            (string) config('services.akeneo.client_secret')
        )
            ->timeout(30)
            ->retry(3, 500, fn (Throwable $exception): bool => $exception instanceof ConnectionException)
            ->post($this->baseUrl().'/api/oauth/v1/token', [
                'grant_type' => 'password',
                'username' => config('services.akeneo.username'),
                'password' => config('services.akeneo.password'),
            ])
            ->throw();

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Akeneo did not return an access token.');
        }

        $expiresIn = (int) $response->json('expires_in', 3600);

        Cache::put(self::TOKEN_CACHE_KEY, $token, max($expiresIn - 60, 60));

        return $token;
    }

    /**
     * Iterate over every product, following Akeneo's `next` links.
     *
     * @return Generator<int, array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    public function getAllProducts(int $limit = self::MAX_PAGE_SIZE): Generator
    {
        return $this->paginate('/api/rest/v1/products', $limit);
    }

    /**
     * Iterate over every product model (root and sub-models), following Akeneo's `next` links.
     *
     * @return Generator<int, array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    public function getAllProductModels(int $limit = self::MAX_PAGE_SIZE): Generator
    {
        return $this->paginate('/api/rest/v1/product-models', $limit);
    }

    /**
     * Get the variant axes of a family variant, ordered from the first to the last level.
     *
     * @return list<string>
     *
     * @throws RequestException|ConnectionException
     */
    public function getFamilyVariantAxes(string $family, string $familyVariant): array
    {
        return Cache::remember("akeneo_family_variant_axes:{$family}:{$familyVariant}", now()->addDay(), function () use ($family, $familyVariant): array {
            $sets = $this->request()
                ->get($this->baseUrl()."/api/rest/v1/families/{$family}/variants/{$familyVariant}")
                ->throw()
                ->json('variant_attribute_sets', []);

            return collect($sets)
                ->sortBy('level')
                ->flatMap(fn (array $set): array => $set['axes'] ?? [])
                ->values()
                ->all();
        });
    }

    /**
     * Get the label of an attribute option, falling back to the option code when it has no label or is not an option.
     *
     * @throws RequestException|ConnectionException
     */
    public function getAttributeOptionLabel(string $attribute, string $option, string $locale = 'en_US'): string
    {
        $labels = Cache::remember("akeneo_attribute_option_labels:{$attribute}:{$option}", now()->addDay(), function () use ($attribute, $option): array {
            try {
                return $this->request()
                    ->get($this->baseUrl().'/api/rest/v1/attributes/'.rawurlencode($attribute).'/options/'.rawurlencode($option))
                    ->throw()
                    ->json('labels') ?? [];
            } catch (RequestException $exception) {
                if (in_array($exception->response->status(), [404, 422], true)) {
                    return [];
                }

                throw $exception;
            }
        });

        return $labels[$locale] ?? collect($labels)->filter()->first() ?? $option;
    }

    /**
     * Download a media file, given the `data` of an image attribute value.
     *
     * @return array{contents: string, mimeType: string}
     *
     * @throws RequestException|ConnectionException
     */
    public function downloadMediaFile(string $code): array
    {
        $path = implode('/', array_map(rawurlencode(...), explode('/', $code)));

        $response = $this->request()
            ->accept('*/*')
            ->timeout(120)
            ->get($this->baseUrl()."/api/rest/v1/media-files/{$path}/download")
            ->throw();

        $mimeType = Str::before((string) $response->header('Content-Type'), ';');

        if (! str_starts_with($mimeType, 'image/')) {
            $mimeType = MimeTypes::getDefault()->getMimeTypes(pathinfo($code, PATHINFO_EXTENSION))[0] ?? 'application/octet-stream';
        }

        return ['contents' => $response->body(), 'mimeType' => $mimeType];
    }

    /**
     * @return Generator<int, array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    private function paginate(string $path, int $limit): Generator
    {
        $data = $this->request()
            ->get($this->baseUrl().$path, [
                'pagination_type' => 'search_after',
                'limit' => min($limit, self::MAX_PAGE_SIZE),
            ])
            ->throw()
            ->json() ?? [];

        while (true) {
            foreach ($data['_embedded']['items'] ?? [] as $item) {
                yield $item;
            }

            $nextUrl = $data['_links']['next']['href'] ?? null;

            if ($nextUrl === null) {
                return;
            }

            $data = $this->request()->get($nextUrl)->throw()->json() ?? [];
        }
    }

    /**
     * Build an authenticated request that retries connection failures and refreshes an expired token once.
     */
    private function request(): PendingRequest
    {
        $hasRefreshedToken = false;

        return Http::withToken($this->getAccessToken())
            ->acceptJson()
            ->timeout(30)
            ->retry(3, 500, function (Throwable $exception, PendingRequest $request) use (&$hasRefreshedToken): bool {
                if ($exception instanceof ConnectionException) {
                    return true;
                }

                if ($exception instanceof RequestException && $exception->response->status() === 401 && ! $hasRefreshedToken) {
                    $hasRefreshedToken = true;
                    Cache::forget(self::TOKEN_CACHE_KEY);
                    $request->withToken($this->refreshAccessToken());

                    return true;
                }

                return false;
            });
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.akeneo.base_url'), '/');
    }
}

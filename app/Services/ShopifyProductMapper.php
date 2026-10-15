<?php

namespace App\Services;

use App\Models\AkeneoAttribute;
use App\Models\Product;
use App\Models\ProductModel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class ShopifyProductMapper
{
    public const LOCALE = 'en_US';

    /**
     * Channels to read scopable values from, in order of preference.
     */
    public const SCOPES = ['ecommerce', 'print'];

    private const MAX_OPTIONS = 3;

    private const MAX_VARIANTS = 2048;

    /**
     * Currency used when a product has no price in the store currency.
     */
    private const FALLBACK_CURRENCY = 'EUR';

    /**
     * Image attributes holding a product's main image, in order of preference. Families use one or the other.
     */
    public const MAIN_IMAGE_ATTRIBUTES = ['image', 'picture'];

    /**
     * Image attribute holding a variant's own image, usually set on the colour-level sub-model.
     */
    public const VARIANT_IMAGE_ATTRIBUTE = 'variation_image';

    /**
     * Attributes sent as product metafields, loaded on first use.
     *
     * @var Collection<int, AkeneoAttribute>|null
     */
    private ?Collection $metafieldAttributes = null;

    public function __construct(
        private AkeneoProductService $akeneo,
        private ProductImageUploader $images,
    ) {}

    /**
     * Akeneo code used as the Shopify custom ID: the root model for variant groups, the identifier for standalone products.
     */
    public function akeneoCode(?ProductModel $rootModel, Product $product): string
    {
        return $rootModel !== null ? "model:{$rootModel->code}" : "product:{$product->identifier}";
    }

    /**
     * Build a `ProductSetInput` for a root product model and its variant products, or for a single standalone product.
     *
     * @param  Collection<int, Product>  $products
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException when the group has more variant axes or variants than Shopify supports, or an image is rejected
     * @throws RequestException|ConnectionException|RuntimeException
     */
    public function toProductSetInput(?ProductModel $rootModel, Collection $products): array
    {
        $products = $products->sortBy('identifier')->values();
        $first = $products->first();
        $values = $rootModel?->values ?? $first->values ?? [];
        $code = $this->akeneoCode($rootModel, $first);

        $axes = $rootModel?->variant_axes ?? [];

        if (count($axes) > self::MAX_OPTIONS) {
            throw new InvalidArgumentException("{$code} has ".count($axes).' variant axes, Shopify supports at most '.self::MAX_OPTIONS.'.');
        }

        if ($products->count() > self::MAX_VARIANTS) {
            throw new InvalidArgumentException("{$code} has {$products->count()} variants, Shopify supports at most ".self::MAX_VARIANTS.'.');
        }

        $title = $this->text($values, 'name') ?? $this->text($values, 'erp_name') ?? ($rootModel?->code ?? $first->identifier);
        $variants = $products->map(fn (Product $product): array => $this->variant($product, $axes, $title))->all();
        $metafields = $this->metafields($values, $first->values ?? []);

        return [
            'title' => $title,
            'descriptionHtml' => $this->text($values, 'description') ?? '',
            'productType' => (string) ($rootModel?->family ?? $first->family ?? ''),
            'tags' => $products
                ->flatMap(fn (Product $product): array => $product->categories ?? [])
                ->merge($rootModel?->categories ?? [])
                ->unique()
                ->sort()
                ->values()
                ->all(),
            'productOptions' => $this->productOptions($axes, $variants),
            'files' => $this->files($values, $products, $title),
            'variants' => $variants,
            ...($metafields === [] ? [] : ['metafields' => $metafields]),
        ];
    }

    /**
     * Product metafields for the attributes enabled in the interface whose definition exists in Shopify.
     * Values come from the root model, then from the first product (which also holds values set on sub-models).
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $productValues
     * @return list<array{namespace: string, key: string, type: string, value: string}>
     */
    private function metafields(array $values, array $productValues): array
    {
        $this->metafieldAttributes ??= AkeneoAttribute::query()->sentToShopify()->orderBy('code')->get();

        return $this->metafieldAttributes
            ->map(fn (AkeneoAttribute $attribute): ?array => ($value = $attribute->toMetafieldValue(
                self::pickValue($values, $attribute->code) ?? self::pickValue($productValues, $attribute->code)
            )) === null ? null : [
                'namespace' => AkeneoAttribute::SHOPIFY_NAMESPACE,
                'key' => $attribute->shopifyKey(),
                'type' => $attribute->shopifyType(),
                'value' => $value,
            ])
            ->filter()
            ->values()
            ->all();
    }

    /**
     * The product gallery: the main image first, then each distinct variant image.
     * Always sent, because productSet replaces the product's files and an image removed in Akeneo must be removed too.
     *
     * @param  array<string, mixed>  $values
     * @param  Collection<int, Product>  $products
     * @return list<array{id: string}>
     *
     * @throws InvalidArgumentException|RequestException|ConnectionException|RuntimeException
     */
    private function files(array $values, Collection $products, string $title): array
    {
        $mainImage = $this->mainImage($values) ?? $this->mainImage($products->first()->values ?? []);

        return $products
            ->map(fn (Product $product): ?string => $this->text($product->values ?? [], self::VARIANT_IMAGE_ATTRIBUTE))
            ->prepend($mainImage)
            ->filter()
            ->unique()
            ->map(fn (string $mediaCode): array => ['id' => $this->images->shopifyFileId($mediaCode, $title)])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function mainImage(array $values): ?string
    {
        foreach (self::MAIN_IMAGE_ATTRIBUTES as $attribute) {
            $mediaCode = $this->text($values, $attribute);

            if ($mediaCode !== null) {
                return $mediaCode;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $axes
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException|RequestException|ConnectionException|RuntimeException
     */
    private function variant(Product $product, array $axes, string $title): array
    {
        $values = $product->values ?? [];

        $optionValues = $axes === []
            ? [['optionName' => 'Title', 'name' => 'Default Title']]
            : array_map(fn (string $axis): array => [
                'optionName' => $this->optionName($axis),
                'name' => $this->axisValue($axis, self::pickValue($values, $axis)),
            ], $axes);

        $variant = [
            'optionValues' => $optionValues,
            'sku' => $product->identifier,
            'price' => $this->price($values),
        ];

        $ean = $this->text($values, 'ean');

        if ($ean !== null) {
            $variant['barcodes'] = [['value' => $ean]];
        }

        $image = $this->text($values, self::VARIANT_IMAGE_ATTRIBUTE);

        if ($image !== null) {
            $variant['file'] = ['id' => $this->images->shopifyFileId($image, $title)];
        }

        return $variant;
    }

    /**
     * @param  list<string>  $axes
     * @param  list<array<string, mixed>>  $variants
     * @return list<array<string, mixed>>
     */
    private function productOptions(array $axes, array $variants): array
    {
        if ($axes === []) {
            return [['name' => 'Title', 'values' => [['name' => 'Default Title']]]];
        }

        return array_map(fn (string $axis, int $index): array => [
            'name' => $this->optionName($axis),
            'position' => $index + 1,
            'values' => collect($variants)
                ->map(fn (array $variant): string => $variant['optionValues'][$index]['name'])
                ->unique()
                ->map(fn (string $name): array => ['name' => $name])
                ->values()
                ->all(),
        ], $axes, array_keys($axes));
    }

    private function optionName(string $axis): string
    {
        return Str::headline($axis);
    }

    /**
     * @throws RequestException|ConnectionException
     */
    private function axisValue(string $axis, mixed $data): string
    {
        return match (true) {
            $data === null, $data === '' => 'N/A',
            is_bool($data) => $data ? 'Yes' : 'No',
            is_string($data) => $this->akeneo->getAttributeOptionLabel($axis, $data, self::LOCALE),
            is_array($data) && isset($data['amount']) => trim(((float) $data['amount']).' '.Str::lower((string) ($data['unit'] ?? ''))),
            default => (string) json_encode($data),
        };
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function price(array $values): string
    {
        $prices = collect(self::pickValue($values, 'price') ?? [])
            ->filter(fn (array $price): bool => is_numeric($price['amount'] ?? null));

        $price = $prices->firstWhere('currency', config('services.shopify.currency'))
            ?? $prices->firstWhere('currency', self::FALLBACK_CURRENCY)
            ?? $prices->first();
        $amount = $price['amount'] ?? null;

        return number_format(is_numeric($amount) ? (float) $amount : 0, 2, '.', '');
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function text(array $values, string $attribute): ?string
    {
        $data = self::pickValue($values, $attribute);

        return is_scalar($data) && trim((string) $data) !== '' ? (string) $data : null;
    }

    /**
     * Pick an attribute's value for the configured locale and channel, falling back to any available value.
     *
     * @param  array<string, mixed>  $values
     */
    public static function pickValue(array $values, string $attribute): mixed
    {
        $candidates = collect($values[$attribute] ?? []);

        foreach (self::SCOPES as $scope) {
            $match = $candidates->first(fn (array $value): bool => in_array($value['locale'] ?? null, [self::LOCALE, null], true)
                && ($value['scope'] ?? null) === $scope);

            if ($match !== null) {
                return $match['data'];
            }
        }

        return ($candidates->first(fn (array $value): bool => in_array($value['locale'] ?? null, [self::LOCALE, null], true))
            ?? $candidates->first())['data'] ?? null;
    }
}

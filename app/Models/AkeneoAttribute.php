<?php

namespace App\Models;

use App\Enums\ShopifyDefinitionStatus;
use Database\Factories\AkeneoAttributeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class AkeneoAttribute extends Model
{
    /** @use HasFactory<AkeneoAttributeFactory> */
    use HasFactory;

    public const LOCALE = 'en_US';

    /**
     * Namespace of the Shopify product metafields holding attribute values (shared with the `akeneo.code` custom ID).
     */
    public const SHOPIFY_NAMESPACE = 'akeneo';

    /**
     * Shopify metafield types each supported Akeneo attribute type can be sent as, the suggested one first.
     * Attribute types missing here (identifier, images, files, prices, reference data, tables…) cannot be sent.
     */
    public const SHOPIFY_TYPES = [
        'pim_catalog_text' => ['single_line_text_field', 'multi_line_text_field'],
        'pim_catalog_textarea' => ['multi_line_text_field', 'single_line_text_field'],
        'pim_catalog_simpleselect' => ['single_line_text_field'],
        'pim_catalog_multiselect' => ['list.single_line_text_field', 'single_line_text_field'],
        'pim_catalog_boolean' => ['boolean', 'single_line_text_field'],
        'pim_catalog_number' => ['number_decimal', 'number_integer', 'single_line_text_field'],
        'pim_catalog_metric' => ['single_line_text_field'],
        'pim_catalog_date' => ['date', 'single_line_text_field'],
    ];

    /**
     * Attribute types whose values are option codes.
     */
    public const SELECT_TYPES = ['pim_catalog_simpleselect', 'pim_catalog_multiselect'];

    protected $fillable = [
        'code',
        'type',
        'labels',
        'localizable',
        'scopable',
        'options',
        'raw_payload',
        'synced_at',
        'shopify_enabled',
        'shopify_name',
        'shopify_key',
        'shopify_type',
        'shopify_definition_id',
        'shopify_definition_error',
        'shopify_definition_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'labels' => 'array',
            'localizable' => 'boolean',
            'scopable' => 'boolean',
            'options' => 'array',
            'raw_payload' => 'array',
            'synced_at' => 'datetime',
            'shopify_enabled' => 'boolean',
            'shopify_definition_checked_at' => 'datetime',
        ];
    }

    /**
     * Attributes whose values are sent to Shopify: enabled, with a metafield definition.
     *
     * @param  Builder<self>  $query
     */
    public function scopeSentToShopify(Builder $query): void
    {
        $query->where('shopify_enabled', true)->whereNotNull('shopify_definition_id');
    }

    /**
     * When the attribute list was last retrieved from Akeneo: every retrieval stamps all attributes with the same `synced_at`.
     */
    public static function lastSyncedAt(): ?Carbon
    {
        $syncedAt = static::query()->max('synced_at');

        return $syncedAt === null ? null : Carbon::parse($syncedAt);
    }

    public function label(string $locale = self::LOCALE): string
    {
        return $this->labels[$locale] ?? collect($this->labels ?? [])->filter()->first() ?? $this->code;
    }

    public function optionLabel(string $optionCode, string $locale = self::LOCALE): string
    {
        $labels = $this->options[$optionCode] ?? [];

        return $labels[$locale] ?? collect($labels)->filter()->first() ?? $optionCode;
    }

    public function isSupported(): bool
    {
        return isset(self::SHOPIFY_TYPES[$this->type]);
    }

    /**
     * @return list<string>
     */
    public function compatibleShopifyTypes(): array
    {
        return self::SHOPIFY_TYPES[$this->type] ?? [];
    }

    public function shopifyName(): string
    {
        return $this->shopify_name ?? $this->label();
    }

    public function shopifyKey(): string
    {
        return $this->shopify_key ?? $this->code;
    }

    public function shopifyType(): ?string
    {
        return $this->shopify_type ?? $this->compatibleShopifyTypes()[0] ?? null;
    }

    /**
     * Name, key and type can no longer change once the definition exists, because Shopify cannot change a definition's type.
     */
    public function hasDefinition(): bool
    {
        return $this->shopify_definition_id !== null;
    }

    public function definitionStatus(): ShopifyDefinitionStatus
    {
        return match (true) {
            $this->hasDefinition() => ShopifyDefinitionStatus::Created,
            $this->shopify_definition_error !== null => ShopifyDefinitionStatus::Error,
            $this->shopify_enabled => ShopifyDefinitionStatus::Missing,
            $this->isSupported() => ShopifyDefinitionStatus::Disabled,
            default => ShopifyDefinitionStatus::Unsupported,
        };
    }

    /**
     * A human-readable value: option labels, Yes/No, `amount unit` for metrics, the date for dates.
     */
    public function formatValue(mixed $data): ?string
    {
        $values = $this->formatValues($data);

        return $values === [] ? null : implode(', ', $values);
    }

    /**
     * The value to send in a metafield of the attribute's Shopify type, or null when there is nothing to send.
     */
    public function toMetafieldValue(mixed $data): ?string
    {
        $values = $this->formatValues($data);

        if ($values === []) {
            return null;
        }

        return match ($this->shopifyType()) {
            'list.single_line_text_field' => (string) json_encode($values, JSON_UNESCAPED_UNICODE),
            'boolean' => $data ? 'true' : 'false',
            'number_integer' => (string) (int) round((float) $data),
            'number_decimal' => self::plainDecimal($data),
            'date' => Str::substr((string) $data, 0, 10),
            'single_line_text_field' => Str::squish(implode(', ', $values)),
            default => implode(', ', $values),
        };
    }

    /**
     * A number written out in plain decimal notation, without trailing zeros: Akeneo's '12.5000' becomes '12.5'.
     * Decimal strings are kept as they are, so they never turn into scientific notation or lose precision as floats.
     */
    private static function plainDecimal(mixed $data): string
    {
        $number = ltrim(trim((string) $data), '+');

        if (preg_match('/^-?\d+(\.\d+)?$/', $number) !== 1) {
            $number = number_format((float) $data, 9, '.', '');
        }

        if (str_contains($number, '.')) {
            $number = rtrim(rtrim($number, '0'), '.');
        }

        return $number === '-0' ? '0' : $number;
    }

    /**
     * @return list<string>
     */
    private function formatValues(mixed $data): array
    {
        if ($data === null || $data === '' || $data === []) {
            return [];
        }

        return match ($this->type) {
            'pim_catalog_simpleselect' => [$this->optionLabel((string) $data)],
            'pim_catalog_multiselect' => array_values(array_map(fn (mixed $code): string => $this->optionLabel((string) $code), (array) $data)),
            'pim_catalog_boolean' => [$data ? 'Yes' : 'No'],
            'pim_catalog_metric' => [trim(((float) ($data['amount'] ?? 0)).' '.Str::lower((string) ($data['unit'] ?? '')))],
            'pim_catalog_date' => [Str::substr((string) $data, 0, 10)],
            default => [is_scalar($data) ? (string) $data : (string) json_encode($data, JSON_UNESCAPED_UNICODE)],
        };
    }
}

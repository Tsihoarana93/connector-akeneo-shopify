<?php

namespace App\Services;

use App\Models\AkeneoAttribute;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use RuntimeException;

class AttributeDefinitionManager
{
    public function __construct(private ShopifyAdminService $shopify) {}

    /**
     * Link the attribute to its Shopify product metafield definition, creating the definition when it doesn't exist.
     * A definition made by hand in Shopify is reused when its type matches. Returns whether the attribute has a definition.
     *
     * @throws RequestException|ConnectionException
     */
    public function sync(AkeneoAttribute $attribute): bool
    {
        $type = $attribute->shopifyType();

        try {
            if (! $attribute->isSupported() || $type === null) {
                throw new RuntimeException("Akeneo attributes of type {$attribute->type} cannot be sent to Shopify.");
            }

            $existing = $this->shopify->findProductMetafieldDefinition(AkeneoAttribute::SHOPIFY_NAMESPACE, $attribute->shopifyKey());

            if ($existing !== null && $existing['type'] !== $type) {
                throw new RuntimeException("A {$this->handle($attribute)} definition of type {$existing['type']} already exists in Shopify. Choose that type, or delete the definition in Shopify.");
            }

            $definitionId = $existing['id'] ?? $this->shopify->createProductMetafieldDefinition([
                'name' => $attribute->shopifyName(),
                'namespace' => AkeneoAttribute::SHOPIFY_NAMESPACE,
                'key' => $attribute->shopifyKey(),
                'type' => $type,
                'description' => "Akeneo attribute {$attribute->code}",
                'pin' => true,
            ]);
        } catch (RuntimeException $exception) {
            $attribute->fill([
                'shopify_definition_error' => $exception->getMessage(),
                'shopify_definition_checked_at' => now(),
            ])->save();

            return false;
        }

        $attribute->fill([
            'shopify_name' => $attribute->shopifyName(),
            'shopify_key' => $attribute->shopifyKey(),
            'shopify_type' => $type,
            'shopify_definition_id' => $definitionId,
            'shopify_definition_error' => null,
            'shopify_definition_checked_at' => now(),
        ])->save();

        return true;
    }

    private function handle(AkeneoAttribute $attribute): string
    {
        return AkeneoAttribute::SHOPIFY_NAMESPACE.'.'.$attribute->shopifyKey();
    }
}

<?php

namespace App\Enums;

/**
 * Where an Akeneo attribute stands on its way to becoming a Shopify product metafield.
 */
enum ShopifyDefinitionStatus: string
{
    case Unsupported = 'unsupported';
    case Disabled = 'disabled';
    case Missing = 'missing';
    case Error = 'error';
    case Created = 'created';

    public function label(): string
    {
        return match ($this) {
            self::Unsupported => 'Not supported',
            self::Disabled => 'Not enabled',
            self::Missing => 'Definition missing',
            self::Error => 'Error',
            self::Created => 'Definition created',
        };
    }
}

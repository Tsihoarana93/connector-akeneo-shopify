<?php

namespace App\Models;

use Database\Factories\ShopifyProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopifyProduct extends Model
{
    /** @use HasFactory<ShopifyProductFactory> */
    use HasFactory;

    protected $fillable = [
        'akeneo_code',
        'shopify_product_id',
        'payload_hash',
        'pushed_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'pushed_at' => 'datetime',
        ];
    }
}

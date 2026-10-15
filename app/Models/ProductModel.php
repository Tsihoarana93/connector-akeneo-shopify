<?php

namespace App\Models;

use Database\Factories\ProductModelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductModel extends Model
{
    /** @use HasFactory<ProductModelFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'parent',
        'family',
        'family_variant',
        'variant_axes',
        'categories',
        'values',
        'raw_payload',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'variant_axes' => 'array',
            'categories' => 'array',
            'values' => 'array',
            'raw_payload' => 'array',
            'synced_at' => 'datetime',
        ];
    }
}

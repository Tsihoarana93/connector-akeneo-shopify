<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $fillable = [
        'identifier',
        'enabled',
        'family',
        'categories',
        'values',
        'raw_payload',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'categories' => 'array',
            'values' => 'array',
            'raw_payload' => 'array',
            'synced_at' => 'datetime',
        ];
    }
}

<?php

namespace App\Models;

use Database\Factories\ShopifyFileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An Akeneo media file already uploaded to Shopify's Files. The media code contains a hash of the file,
 * so a changed image has a new code and is uploaded again.
 */
class ShopifyFile extends Model
{
    /** @use HasFactory<ShopifyFileFactory> */
    use HasFactory;

    protected $fillable = [
        'akeneo_media_code',
        'shopify_file_id',
    ];
}

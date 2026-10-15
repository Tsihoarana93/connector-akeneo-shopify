<?php

namespace App\Services;

use App\Models\ShopifyFile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use InvalidArgumentException;
use RuntimeException;

class ProductImageUploader
{
    public function __construct(
        private AkeneoProductService $akeneo,
        private ShopifyAdminService $shopify,
    ) {}

    /**
     * Get the Shopify file ID of an Akeneo media file, downloading and uploading it the first time only.
     *
     * @throws InvalidArgumentException when Akeneo has no such file or Shopify rejects the image
     * @throws RequestException|ConnectionException|RuntimeException
     */
    public function shopifyFileId(string $mediaCode, string $alt): string
    {
        $fileId = ShopifyFile::query()->where('akeneo_media_code', $mediaCode)->value('shopify_file_id');

        if ($fileId !== null) {
            return $fileId;
        }

        try {
            $media = $this->akeneo->downloadMediaFile($mediaCode);
        } catch (RequestException $exception) {
            if ($exception->response->notFound()) {
                throw new InvalidArgumentException("Image {$mediaCode} was not found in Akeneo.");
            }

            throw $exception;
        }

        $fileId = $this->shopify->uploadImage(basename($mediaCode), $media['mimeType'], $media['contents'], $alt);

        ShopifyFile::query()->create(['akeneo_media_code' => $mediaCode, 'shopify_file_id' => $fileId]);

        return $fileId;
    }
}

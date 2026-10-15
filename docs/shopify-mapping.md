# Shopify mapping

How `App\Services\ShopifyProductMapper` turns Akeneo data into a Shopify `ProductSetInput`.

## Grouping

| Akeneo | Shopify | `akeneo_code` |
|---|---|---|
| Root product model and all of its descendant products (through sub-models) | One product, with one variant per Akeneo product | `model:{root code}` |
| Product without a parent | One product with a single default variant (`Title` / `Default Title`) | `product:{identifier}` |

The current catalog gives 1,048 Shopify products, 49 of which have variants (up to 13 each).

## Fields

| Shopify field | Source |
|---|---|
| `title` | `name`, then `erp_name`, then the Akeneo code |
| `descriptionHtml` | `description`. The first match wins: locale `en_US` (or no locale) on channel `ecommerce`, then on `print`, then any `en_US` or unlocalized value, then any value. |
| `productType` | Akeneo family code |
| `tags` | Category codes of the model and its products, de-duplicated and sorted |
| `productOptions` | The root model's `variant_axes`, for example `Color` and `Size`. Names come from `Str::headline(axis)`, and values come from Akeneo option labels in `en_US`. |
| `variants[].optionValues` | Each product's value for each axis. Option codes are converted to labels, booleans become Yes/No, metrics become `amount unit`, and a missing value becomes `N/A`. |
| `variants[].sku` | Akeneo `identifier` |
| `variants[].price` | `price` in `SHOPIFY_CURRENCY` (default USD), then EUR, then the first currency, then `0.00` |
| `variants[].barcodes` | `ean`, when there is one |
| `files` (gallery) | The main image first (`image`, or `picture` when there's no `image`; from the root model, then the first product), then each distinct `variation_image` of the variants, in SKU order. Always sent, so it's `[]` for a product without images. |
| `variants[].file` | The variant's `variation_image` (set on the colour-level sub-model, so all sizes of a colour share it). No variant image when it's empty, and Shopify then shows the main image. |
| `status` | `DRAFT`, sent **only when the product is created** |

## Images

Shopify can't download Akeneo media URLs itself: `pim-httpd-1` is a Docker-internal host, and the download needs an Akeneo token. So each image goes through the app once (`App\Services\ProductImageUploader`):

1. `AkeneoProductService::downloadMediaFile()` downloads `/api/rest/v1/media-files/{code}/download`.
2. `ShopifyAdminService::uploadImage()` calls `stagedUploadsCreate`, POSTs the file to the staged target, calls `fileCreate` (alt text: the product title), then waits up to 10 s for the file to be `READY`.
3. The Shopify file ID is stored in `shopify_files`, keyed by the Akeneo media code. The code contains a hash of the file, so an unchanged image is never uploaded again, and a changed image has a new code and is uploaded once.

`productSet` then references the files by ID. Because `files` replaces the product's media, an image removed in Akeneo is detached on the next push, and **images added by hand in Shopify are removed**. The file IDs are part of the payload hash, so a changed image triggers a push of that product.

An image that Akeneo doesn't have (404) or that Shopify can't process fails only its product, which records the error in `last_error` and is retried on the next run.

## Upsert by custom ID

- Products are matched with `productSet(identifier: { customId: { namespace: "akeneo", key: "code", value: "<akeneo_code>" } })`. Shopify creates the product when nothing matches, and updates it otherwise.
- Shopify requires the custom-ID metafield definition to be type **`id`**, with the `uniqueValues` capability enabled. The push creates the definition if it's missing. It stops with an error if a definition with a different type exists, and in that case you must delete it in Shopify first.
- The custom-ID metafield must **not** also be sent in `input.metafields`. Shopify sets it on its own, and rejects the request if both are present.

## Updates and skipping

- `productSet` replaces the variants and options lists completely, so the input always contains the group's full current state.
- `status` is never sent on update, so products published in Shopify stay published.
- Unchanged groups are skipped by comparing the sha256 of the input with `shopify_products.payload_hash`. Use `--force` to push them anyway.
- A failed group keeps its `last_error` and is retried on the next run.

## Shopify limits to keep in mind

- At most **3 options** per product. The push fails a group whose model has more than 3 variant axes.
- At most **2,048 variants** per product. The push fails a group with more variants.
- At most **250 media** per product, and images up to 20 MB and 20 megapixels.
- Each option combination must be unique within a product.
- Prices are plain amounts in the store currency. Shopify does no currency conversion.

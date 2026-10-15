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
| `metafields` | One `akeneo.{key}` metafield per attribute enabled on the `/attributes` page whose Shopify definition exists (see [Attribute metafields](#attribute-metafields)). Left out when no such attribute has a value. |
| `status` | `DRAFT`, sent **only when the product is created** |

## Attribute metafields

Any other Akeneo attribute can be sent as a product metafield, chosen in the web interface at `/attributes`:

1. `fidelity:sync-products`, or the **Update from Akeneo** button on `/attributes`, imports every attribute into `akeneo_attributes`, with the options of select attributes. Only Akeneo columns are written, so the choices below survive every update. The page shows when the list was last retrieved.
2. On the attribute's page, tick **Send this attribute to Shopify**, check the name, key and type, and save.
3. Click **Create in Shopify** (or **Create missing definitions** on the list). `App\Services\AttributeDefinitionManager` looks up `akeneo.{key}` first, links a definition that already exists with the same type, and otherwise creates it (pinned, so it shows on the product page in the admin). A definition of another type is reported as an error. After that, the name, key and type are locked, because Shopify can't change a definition's type.
4. The next push sends the attribute in `input.metafields`, only for attributes that are enabled **and** have a definition. Every product with a value is pushed again once, because its payload hash changes.

Values come from the root model, then from the first product of the group (so values set on sub-models are found too), with the same locale and channel order as the other fields. Only product-level metafields are sent; variant metafields aren't supported.

| Akeneo type | Shopify types (suggested first) | Value sent |
|---|---|---|
| text | `single_line_text_field`, `multi_line_text_field` | the text |
| textarea | `multi_line_text_field`, `single_line_text_field` | the text (line breaks collapsed for single line) |
| simple select | `single_line_text_field` | the option's `en_US` label, e.g. `600` → `60°` |
| multi select | `list.single_line_text_field`, `single_line_text_field` | the labels, as a JSON list or comma-separated |
| boolean | `boolean`, `single_line_text_field` | `true`/`false`, or `Yes`/`No` |
| number | `number_decimal`, `number_integer`, `single_line_text_field` | the number |
| metric | `single_line_text_field` | `amount unit`, e.g. `800 gram` |
| date | `date`, `single_line_text_field` | `YYYY-MM-DD` |
| identifier, image, file, price collection, reference data… | — | can't be enabled |

An empty value sends no metafield. A metafield that was already set in Shopify isn't deleted when the value is removed in Akeneo or the attribute is disabled; delete it by hand in Shopify if needed.

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

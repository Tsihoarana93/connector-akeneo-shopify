# Architecture

```mermaid
flowchart LR
    A[Akeneo PIM<br/>REST API] -->|fidelity:sync-products| S[SyncProducts]
    S --> PM[(product_models)]
    S --> P[(products)]
    PM --> PU[PushProducts]
    P --> PU
    PU --> M[ShopifyProductMapper]
    M -->|option labels| A
    PU -->|productSet| SH[Shopify<br/>GraphQL Admin API]
    M -->|images| IU[ProductImageUploader]
    IU -->|media download| A
    IU -->|staged upload + fileCreate| SH
    IU --> SF[(shopify_files)]
    PU --> SP[(shopify_products)]
```

Akeneo is the source of truth, and the local tables mirror it. Shopify is only written to by `fidelity:push-products`.

## Sync pipeline: `fidelity:sync-products`

`app/Console/Commands/SyncProducts.php`

1. Pages through `/api/rest/v1/product-models`, then `/api/rest/v1/products`, using `search_after` pagination with 100 items per page.
2. For **root** product models (no `parent`), it resolves the family-variant axes, for example `[color, size]`, and stores them in `variant_axes`.
3. Upserts rows in batches of 100: `product_models` by `code`, and `products` by `identifier`. JSON columns are encoded by hand, because `upsert()` bypasses model casts.
4. Every row of a run gets the same `synced_at` (truncated to the second). Rows with an older `synced_at` are no longer in Akeneo: the command warns about them, and deletes them with `--prune`. Nothing is pruned if the sync fails, and a table is not pruned when Akeneo returned none of its rows (usually a configuration or permission problem).

It runs **hourly** with `withoutOverlapping()` (`routes/console.php`).

## Push pipeline: `fidelity:push-products`

`app/Console/Commands/PushProducts.php`

1. `ensureAkeneoCodeDefinition()` makes sure the `akeneo.code` product metafield definition exists. It must be type `id` with unique values, because it's the custom ID used for upserts.
2. Groups products by their **root product model**, walking up through sub-models. A product without a parent is its own group.
3. `ShopifyProductMapper` builds a `ProductSetInput` for each group (see [Shopify mapping](shopify-mapping.md)). Images not yet in Shopify are downloaded from Akeneo and uploaded by `ProductImageUploader` at this step.
4. The input is hashed. Groups whose hash matches the last successful push are skipped, unless `--force` is set.
5. `productSet` is called with `identifier: { customId: akeneo.code }`, which creates or updates the product. The Shopify ID, the hash, `pushed_at`, or `last_error` is stored in `shopify_products`.
6. Prints a Created / Updated / Skipped / Failed summary. The command fails if any product failed.

The push is not scheduled yet.

## Run history: `sync_runs`

Both commands use the `App\Console\Concerns\RecordsSyncRun` trait, so every run is recorded, whether it comes from the CLI, the scheduler or the web interface:

1. At start, the trait creates a `sync_runs` row with status `running`. A run started from the interface instead claims its `queued` row, passed with the internal `--sync-run=` option. Any older `running` row of the same command is marked `failed` ("Interrupted before it finished"), because its process was killed.
2. Every `info`/`warn`/`error` line is printed as usual and also appended to `storage/app/private/sync-runs/{id}.log`, as `[Y-m-d H:i:s] LEVEL message`. The `local` disk is private, so logs are only served through the authenticated download route.
3. At the end, the trait stores `succeeded` or `failed` (from the exit code), `counts`, `finished_at` and the abort message. An unexpected exception is recorded as `failed` with its message, then rethrown.

## Web interface

Blade + Tailwind pages behind a login (`App\Http\Controllers\Auth\LoginController`, rate-limited to 5 attempts per minute per email and IP). There is no public registration: users are created with `fidelity:create-user`.

| Route | Purpose |
|---|---|
| `GET /sync-runs` | Paginated history, filters by command and status, last success per command, "Run now" forms |
| `GET /sync-runs/{id}` | Run details and the last 200 log lines; refreshes every 5 s while the run is queued or running |
| `GET /sync-runs/{id}/log` | Downloads the full log file |
| `POST /sync-runs` | Creates a `queued` run and dispatches `App\Jobs\RunSyncCommand`. Refused while a run of the same command is queued or running. |

`RunSyncCommand` calls the command through `Artisan::call()` with the recorded options. It has 1 try and a 3,600 s timeout, so the database queue's `retry_after` (`DB_QUEUE_RETRY_AFTER=3700`) must stay above it. The `queue` service in `docker-compose.yml` runs the worker.

## Services

| Service | Responsibility |
|---|---|
| `App\Services\AkeneoProductService` | OAuth password-grant token, cached for `expires_in` − 60 s and refreshed once on 401. Retries connection errors, 30 s timeout, throws on 4xx/5xx. Provides `getAllProducts()`, `getAllProductModels()`, `getFamilyVariantAxes()` and `getAttributeOptionLabel()`, the last two cached for a day. |
| `App\Services\ShopifyAdminService` | Client-credentials token (24 h, cached). `graphql()` POSTs to `/admin/api/{version}/graphql.json`, waits and retries on `THROTTLED` (wait derived from `extensions.cost.throttleStatus`), and retries 429/5xx and connection errors. Also provides `ensureAkeneoCodeDefinition()` and `productSet()`. |
| `App\Services\ShopifyProductMapper` | Mapping from `ProductModel` and `Product` rows to a `ProductSetInput`. |
| `App\Services\ProductImageUploader` | Returns the Shopify file ID of an Akeneo media file, downloading and uploading it only the first time (`shopify_files`). |

## Data model

| Table | Key | Columns |
|---|---|---|
| `products` | `identifier` (unique) | `enabled`, `family`, `categories` (json), `values` (json, Akeneo attribute values including inherited ones), `raw_payload` (json, full API response), `synced_at` |
| `product_models` | `code` (unique) | `parent` (indexed), `family`, `family_variant`, `variant_axes` (json, root models only), `categories`, `values`, `raw_payload`, `synced_at` |
| `shopify_products` | `akeneo_code` (unique, `model:…` or `product:…`) | `shopify_product_id` (GID), `payload_hash` (sha256), `pushed_at`, `last_error` |
| `shopify_files` | `akeneo_media_code` (unique) | `shopify_file_id` (`gid://shopify/MediaImage/…`) |
| `sync_runs` | `id` | `command`, `status` (`queued`/`running`/`succeeded`/`failed`), `options` (json), `counts` (json), `message`, `log_path`, `user_id` (null for CLI and scheduler), `queued_at`, `started_at`, `finished_at` |

### Why attribute values are stored as JSON

Akeneo attributes are dynamic, and the catalog differs a lot between products:
- 13 families use 41 attributes in total, and each product only has 2–19 of them.
- Each value is a list of `{data, locale, scope}` entries.
- `data` can be text, a number, a metric, a price collection or an option code.

Storing the payload as-is keeps it lossless, and new Akeneo attributes need no migration. If an attribute ever needs filtering or indexing, promote it to a real or generated column.

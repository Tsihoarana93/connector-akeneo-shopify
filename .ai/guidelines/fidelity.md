## Fidelity: Akeneo → Shopify sync

### Domain
- Akeneo PIM is the source of truth. `products` and `product_models` mirror it via `fidelity:sync-products`.
- Shopify is only written by `fidelity:push-products`. `shopify_products` tracks each pushed group (`model:{root code}` or `product:{identifier}`).
- Read `docs/architecture.md` and `docs/shopify-mapping.md` before changing the sync, the push or the mapping.

### External APIs
- Call Akeneo only through `App\Services\AkeneoProductService`, and Shopify only through `App\Services\ShopifyAdminService`. Do not use `Http::` against them anywhere else.
- Follow the existing HTTP pattern for new calls:
  - a cached token, refreshed once on a 401
  - `retry()` on connection errors
  - an explicit `timeout()`
  - `throw()` on 4xx and 5xx responses
- Read configuration from `config('services.akeneo.*')` and `config('services.shopify.*')`. Add new settings to `config/services.php` and `.env.example`.

### Shopify
- Use the GraphQL Admin API only. The REST Admin API is legacy.
- Upsert products with `productSet` and `identifier.customId` (`akeneo.code`, a metafield definition of type `id` with unique values).
- Never send the custom-ID metafield in `input.metafields`.
- Send `status` only when creating a product. Updates must not change the publishing state.
- `productSet` replaces variants and options entirely, so always send a group's full state.
- Respect the limits: at most 3 options and 2,048 variants per product.
- Change `SHOPIFY_API_VERSION` deliberately, and re-run the tests after doing so.

### Data
- Write synced rows in batches with `Model::query()->upsert()`. JSON columns must be `json_encode`d by hand, because upsert bypasses casts.
- Stale-row detection relies on one `synced_at` per run, set with `now()->startOfSecond()`. Keep it that way, or `--prune` deletes rows that were just synced.
- Keep the full Akeneo response in `raw_payload`. Add real columns only for attributes that need filtering or indexing.

### Commands
- Use the `fidelity:` namespace, and return `self::SUCCESS` or `self::FAILURE`.
- Catch `RequestException|ConnectionException` (plus `RuntimeException` for Shopify) and print a clear message. Do not let raw exceptions escape.
- Failures of a single product are recorded and the run continues. The command fails at the end if any product failed.

### Tests
- Write feature tests in `tests/Feature`.
- Fake every external call: use `Http::fake()` together with `Http::preventStrayRequests()`, and `Sleep::fake()` when retries or throttling are involved. Never hit the real Akeneo or Shopify.
- Use the factories (`Product`, `ProductModel`, `ShopifyProduct`) and their `stale()` states.

### Documentation
- When behaviour, commands, options or env vars change, update the matching page in `docs/` and the Architecture section at the top of `CLAUDE.md`.

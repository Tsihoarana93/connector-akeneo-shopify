# Operations

## Runbook

```bash
# Full process (the push only runs if the sync succeeded)
vendor/bin/sail artisan fidelity:sync-products && vendor/bin/sail artisan fidelity:push-products

# Push a few changed products only
vendor/bin/sail artisan fidelity:push-products --limit=5

# Re-send everything (safe: updates never change the product status)
vendor/bin/sail artisan fidelity:push-products --force

# Remove local rows deleted in Akeneo
vendor/bin/sail artisan fidelity:sync-products --prune
```

Every run is recorded in `sync_runs`, with its log in `storage/app/private/sync-runs/{id}.log`. You can also browse the history, download logs and start a sync or push from the web interface at `/sync-runs`.

```bash
# Create a user for the interface, or reset a password
vendor/bin/sail artisan fidelity:create-user you@example.com --name="You"

# Runs started from the interface need the queue worker (the `queue` service). Check it with:
vendor/bin/sail logs queue
```

Inspect the push state:

```sql
SELECT akeneo_code, last_error FROM shopify_products WHERE last_error IS NOT NULL;
SELECT COUNT(*), SUM(shopify_product_id IS NOT NULL) FROM shopify_products;
```

For a MySQL console, run `vendor/bin/sail mysql` in a real terminal (it's interactive).

## Benchmark (2026-10-10, first full push)

| Metric | Value |
|---|---|
| Products pushed | 1,039 created, plus 9 already present and skipped, 0 failed |
| Total time | 1,067 s (about 17 min 47 s) |
| Speed | 0.98 products/s (56–61 per minute, flat for the whole run) |
| Time between products | average 1.03 s, median 1 s, p95 2 s, max 2 s |
| Products with variants and standalone products | 1.08 s and 1.02 s on average |
| Throttling | none. The bucket is 2,000 points, refills at 100/s, and stayed almost full. |

The cost is one synchronous `productSet` round trip per product, about 1 s. Shopify's rate limit was not the constraint. Later runs only push products that changed in Akeneo.

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| `Push aborted: Shopify is not configured…` | `SHOPIFY_SHOP`, `SHOPIFY_CLIENT_ID` or `SHOPIFY_CLIENT_SECRET` is empty. Fill them in and save `.env`. |
| `accessScopes: []`, or `Access denied … Required access: read_products` | The installed app version has no scopes. Add `write_products` and `read_products`, release the version, and approve it on the store. |
| `shop_not_permitted` or `application_cannot_be_found` on the token request | The store is not in the app's organization, or the client ID is wrong. |
| `Metafield definition of type 'id' is required when using custom ids` | The `akeneo.code` definition has the wrong type. Delete it in Shopify, and the next push recreates it. |
| `The input argument metafields … must contain the customId value` | The custom-ID metafield was sent in `input.metafields`. It must only be in `identifier`. |
| `… has N variant axes, Shopify supports at most 3` | The Akeneo family variant has more than 3 axes. Reduce the axes or map them differently. |
| `… has N variants, Shopify supports at most 2048` | The root product model has more than 2,048 products. Split the model in Akeneo. |
| `Akeneo returned no products, so the N local products were not pruned…` | `--prune` refused to empty the table. Check `AKENEO_*` and the API user's permissions (channels, locales, categories). |
| `Shopify could not process image X: …` | Shopify rejected the image (for example over 20 MB or 20 megapixels). Replace it in Akeneo. The product is retried on the next push. |
| `Image X was not found in Akeneo.` | The product references a media file Akeneo no longer has. Re-upload the image in Akeneo, then sync. |
| A run started from the interface stays **Queued** | The queue worker isn't running. Start it with `vendor/bin/sail up -d queue`. |
| A run shows **Interrupted before it finished** | Its process was killed (container restart, timeout). The next run of the same command marks it this way. Start it again. |
| `Product model X has not been synced from Akeneo` | A product references a model that is missing locally. Run `fidelity:sync-products` first. |
| `Sync aborted: …` | Akeneo is unreachable, or rejected the credentials. Check `AKENEO_*` and that `AKENEO_BASE_URL` is reachable from the Sail container. |

## Known limitations and roadmap

- **Parallel push.** Sending several products at once with `Http::pool(…, concurrency: N)` should make a full push about N times faster, within the 100 points/s limit.
- **Deletions.** Products removed from Akeneo are not archived or deleted in Shopify.
- **Scheduling.** Only the sync is scheduled. The push could run right after a successful sync.
- **Bulk operations.** For catalogs of 10k+ products, switch to staged JSONL uploads with `bulkOperationRunMutation`, using the same `productSet` input.
- Only the main fields are mapped. Other Akeneo attributes could become Shopify metafields.

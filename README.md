# Fidelity — Akeneo → Shopify product sync

A Laravel 12 tool that pulls the product catalog from an **Akeneo PIM** into a local MySQL database and pushes it to a **Shopify** store.

- **Sync** (`fidelity:sync-products`) mirrors Akeneo product models and products into `product_models` and `products`.
- **Push** (`fidelity:push-products`) groups variants under their Akeneo root model and upserts each group into Shopify with the GraphQL `productSet` mutation. New products are created as **DRAFT**, and unchanged products are skipped.

## Quick start

```bash
vendor/bin/sail up -d
cp .env.example .env            # then fill in AKENEO_* and SHOPIFY_* (see docs/setup.md)
vendor/bin/sail artisan key:generate
vendor/bin/sail artisan migrate

# Full process: pull from Akeneo, then push to Shopify (push only runs if the sync succeeded)
vendor/bin/sail artisan fidelity:sync-products && vendor/bin/sail artisan fidelity:push-products
```

## Commands

| Command | Options | What it does |
|---|---|---|
| `fidelity:sync-products` | `--prune` deletes local rows no longer in Akeneo | Syncs product models (with variant axes) and products from Akeneo. Scheduled hourly. |
| `fidelity:push-products` | `--force` pushes unchanged products too<br>`--limit=N` pushes at most N changed products | Creates or updates Shopify products, and records the result in `shopify_products`. |

## Documentation

- [Setup](docs/setup.md): environment variables, the Akeneo connection, and creating the Shopify app
- [Architecture](docs/architecture.md): pipelines, services and data model
- [Shopify mapping](docs/shopify-mapping.md): how Akeneo data becomes Shopify products
- [Operations](docs/operations.md): runbook, benchmark, troubleshooting and roadmap

## Tests

```bash
vendor/bin/sail artisan test
```

All Akeneo and Shopify calls are faked with `Http::fake()`. Tests never reach the real services.

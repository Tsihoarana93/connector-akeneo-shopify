# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# Start all services (server, queue, logs, Vite) via Laravel Sail
composer dev

# Run tests
composer test

# Run a single test file
php artisan test tests/Feature/ExampleTest.php

# Run migrations
php artisan migrate

# Lint with Laravel Pint
./vendor/bin/pint

# Start Sail (Docker)
./vendor/bin/sail up -d
./vendor/bin/sail down
```

## Artisan Commands

```bash
# Sync product models and products from Akeneo PIM into the database (scheduled hourly in routes/console.php)
php artisan fidelity:sync-products [--prune]

# Push synced products to Shopify (only changed products unless --force)
php artisan fidelity:push-products [--force] [--limit=N]

# Create a user for the web interface, or reset their password
php artisan fidelity:create-user {email} [--name=]
```

## Architecture

This is a **Laravel 12 tool** that syncs products from an Akeneo PIM into MySQL and pushes them to a Shopify store.

**Sync pipeline** (`fidelity:sync-products`):
1. `App\Services\AkeneoProductService` authenticates against Akeneo (OAuth password grant, token cached) using the `services.akeneo.*` config (`config/services.php`, `AKENEO_*` env vars)
2. Pages (`search_after`) through `/api/rest/v1/product-models` then `/api/rest/v1/products`
3. Upserts into `product_models` (by `code`; root models also store their family-variant `variant_axes`) and `products` (by `identifier`), keeping `values` and the full `raw_payload` as JSON. `--prune` deletes rows not seen in the run, unless Akeneo returned no rows for that table

**Push pipeline** (`fidelity:push-products`):
1. `App\Services\ShopifyAdminService` talks to the Shopify GraphQL Admin API (`services.shopify.*`, `SHOPIFY_*` env vars) using a Dev Dashboard app's client credentials grant (24h token, cached), retrying on `THROTTLED`
2. Ensures a unique `akeneo.code` product metafield definition exists; it is the `customId` used to upsert with `productSet`
3. Groups products by root product model (`model:{code}` → one Shopify product with variants, options from `variant_axes` with Akeneo option labels) or standalone (`product:{identifier}`); `App\Services\ShopifyProductMapper` builds the `ProductSetInput` (en_US, `ecommerce` then `print` channel, EUR price; gallery = `image`/`picture` then each `variation_image`, variant image = `variation_image`). `App\Services\ProductImageUploader` downloads each Akeneo media file once, uploads it with `stagedUploadsCreate` + `fileCreate`, and remembers the file ID in `shopify_files`
4. New products are created as `DRAFT`; updates never send `status`. `shopify_products` stores the Shopify ID, a payload hash (unchanged groups are skipped) and the last error

**Run history**: both commands use `App\Console\Concerns\RecordsSyncRun`, which records each run in `sync_runs` (status, options, counts, message) and copies every output line to `storage/app/private/sync-runs/{id}.log`. A run started from the interface claims its queued row with the internal `--sync-run=` option.

**Web interface**: a login-protected Blade + Tailwind UI (`/sync-runs`, `SyncRunController`) lists runs, shows details, downloads logs, and starts a sync or push through the queued `App\Jobs\RunSyncCommand` (the `queue` service in `docker-compose.yml`; `DB_QUEUE_RETRY_AFTER` must exceed its 3,600 s timeout). Users are created with `fidelity:create-user`; there is no registration.

**Database**: MySQL via Laravel Sail. Tables: `products`, `product_models`, `shopify_products`, `shopify_files`, `sync_runs`, plus `users` and `sessions` for the interface (migrations in `database/migrations/`).
**Local dev uses Docker/Sail** — the `.env` points `DB_HOST=mysql` (the Sail container name). To run artisan commands outside Sail, update `DB_HOST` to `127.0.0.1`.

===

<laravel-boost-guidelines>
=== .ai/fidelity rules ===

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

=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.4. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `vendor/bin/sail composer show --direct` to list direct dependencies with versions, or `vendor/bin/sail composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `vendor/bin/sail npm run build` or ask the user to run `vendor/bin/sail npm run dev` or `vendor/bin/sail composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `vendor/bin/sail artisan route:list`). Use `vendor/bin/sail artisan list` to discover available commands and `vendor/bin/sail artisan [command] --help` to check parameters.
- Inspect routes with `vendor/bin/sail artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `vendor/bin/sail artisan config:show app.name`, `vendor/bin/sail artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `vendor/bin/sail artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `vendor/bin/sail artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== sail rules ===

# Laravel Sail

- This project runs inside Laravel Sail's Docker containers. You MUST execute all commands through Sail.
- Start services using `vendor/bin/sail up -d` and stop them with `vendor/bin/sail stop`.
- Open the application in the browser by running `vendor/bin/sail open`.
- Always prefix PHP, Artisan, Composer, and Node commands with `vendor/bin/sail`. Examples:
    - Run Artisan Commands: `vendor/bin/sail artisan migrate`
    - Install Composer packages: `vendor/bin/sail composer install`
    - Execute Node commands: `vendor/bin/sail npm run dev`
    - Execute PHP scripts: `vendor/bin/sail php [script]`
- View all available Sail commands by running `vendor/bin/sail` without arguments.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `vendor/bin/sail artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `vendor/bin/sail artisan list` and check their parameters with `vendor/bin/sail artisan [command] --help`.
- If you're creating a generic PHP class, use `vendor/bin/sail artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `vendor/bin/sail artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `vendor/bin/sail artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== laravel/v12 rules ===

# Laravel 12

- CRITICAL: ALWAYS use `search-docs` tool for version-specific Laravel documentation and updated code examples.
- This project uses the streamlined Laravel 11+ structure: register middleware, exceptions, and routing in `bootstrap/app.php` and service providers in `bootstrap/providers.php`. There is no `app/Http/Kernel.php` or `app/Console/Kernel.php`, and commands in `app/Console/Commands/` auto-register.

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.

- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/sail bin pint --dirty` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/sail bin pint --test`, simply run `vendor/bin/sail bin pint` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit. Create tests with `vendor/bin/sail artisan make:test --phpunit {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `vendor/bin/sail artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/sail bin phpunit` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

</laravel-boost-guidelines>

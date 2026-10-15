# Setup

## Prerequisites

- Docker with **Laravel Sail**. Every command runs through `vendor/bin/sail`.
- An **Akeneo PIM** reachable from the Sail container. Locally this is `http://pim-httpd-1:80` (the Akeneo Docker web container).
- A **Shopify store** and a **Dev Dashboard app** installed on it (see below).

```bash
vendor/bin/sail up -d
vendor/bin/sail artisan migrate
vendor/bin/sail npm install && vendor/bin/sail npm run build   # assets for the web interface
vendor/bin/sail artisan fidelity:create-user you@example.com   # asks for a password (12+ characters)
```

The web interface is at `/sync-runs` (`APP_URL`). `sail up` also starts the `queue` service, which runs the syncs and pushes started from the interface.

## Environment variables

All of these are read through `config/services.php` (`services.akeneo.*` and `services.shopify.*`).

| Variable | Example | Notes |
|---|---|---|
| `AKENEO_BASE_URL` | `http://pim-httpd-1:80` | Base URL of the Akeneo instance, as seen from the Sail container |
| `AKENEO_CLIENT_ID` / `AKENEO_CLIENT_SECRET` | | From an Akeneo API connection |
| `AKENEO_USERNAME` / `AKENEO_PASSWORD` | | API user of that connection (OAuth password grant) |
| `SHOPIFY_SHOP` | `my-store` or `my-store.myshopify.com` | The subdomain on its own also works |
| `SHOPIFY_CLIENT_ID` / `SHOPIFY_CLIENT_SECRET` | | Dev Dashboard → app → **Settings** (the secret starts with `shpss_`) |
| `SHOPIFY_API_VERSION` | `2026-10` | GraphQL Admin API version. Bump it deliberately, and re-run the tests when you do. |
| `SHOPIFY_CURRENCY` | `USD` | Akeneo price currency to use. It should match the store currency. Falls back to EUR. |
| `DB_QUEUE_RETRY_AFTER` | `3700` | Must stay above the 3,600 s timeout of the `RunSyncCommand` job, or a long push is started twice. |

## Creating the Shopify app

Since **1 January 2026**, Shopify no longer lets you create new custom apps from the store admin. Apps are created in the **Dev Dashboard**, and they authenticate with the **client credentials grant**: the app exchanges its client ID and secret for a token that lasts 24 hours. The app caches the token and renews it automatically.

1. In the [Dev Dashboard](https://dev.shopify.com/dashboard/), create a store from the **Dev stores** page, or use a store that belongs to the **same organization** as the app. A store created from the Shopify admin is not in your organization, and the token request fails with `shop_not_permitted`.
2. Create an app.
3. Create a version with the access scopes **`write_products`** and **`read_products`**, then **release** it.
4. Install the app on the store, and approve the scope change if Shopify asks for it.
5. Copy the client ID and secret into `.env`.

### Check the credentials (read-only)

```bash
vendor/bin/sail artisan tinker --execute='
$s = app(App\Services\ShopifyAdminService::class);
$s->refreshAccessToken();
echo json_encode($s->graphql("{ shop { name currencyCode } currentAppInstallation { accessScopes { handle } } productsCount { count } }"), JSON_PRETTY_PRINT);'
```

Expect the shop name, its currency, and both scopes. `accessScopes: []` means a version with scopes was never released or approved on the store.

## First run

```bash
vendor/bin/sail artisan fidelity:sync-products
# Done. 81 product models and 1239 products synced.

vendor/bin/sail artisan fidelity:push-products --limit=5   # try a few products first
vendor/bin/sail artisan fidelity:push-products             # then the rest
```

The first push also creates the `akeneo.code` product metafield definition in Shopify (see [Shopify mapping](shopify-mapping.md)).

## Production

The app must be served over **HTTPS** in production. With `APP_ENV=production`:

- **Debug mode is always off.** `AppServiceProvider` turns it off even if `APP_DEBUG=true` was left in `.env`, and logs a warning (at most once an hour) until you fix the variable. Debug pages would otherwise show stack traces, queries and environment values.
- **Generated URLs use `https://`** (`URL::forceHttps()`).
- **The session cookie is `Secure`.** `SESSION_SECURE_COOKIE` defaults to `true`, so the browser only sends it over HTTPS. Set it to `false` only if you really serve production over plain HTTP, which you shouldn't.

Checklist:

| Setting | Value |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | the public `https://` URL |
| `APP_KEY` | generated once with `php artisan key:generate`, and kept secret |
| `LOG_LEVEL` | `warning` (or `info`) instead of `debug` |
| TLS | terminated by the web server or a reverse proxy. Behind a proxy, configure trusted proxies (`$middleware->trustProxies(...)` in `bootstrap/app.php`) so Laravel sees HTTPS and the client IP, which the login rate limit uses. |
| Deploy | `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build`, `php artisan migrate --force`, `php artisan optimize` |
| Workers | a queue worker (`php artisan queue:work`) and the scheduler (`php artisan schedule:work`, or a cron running `schedule:run` every minute) |

Every response also carries browser security headers (`App\Http\Middleware\SecurityHeaders`, registered globally): a Content Security Policy that only allows the app's own scripts plus the Bunny Fonts stylesheet and fonts, `X-Frame-Options: DENY` and `frame-ancestors 'none'` against clickjacking, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, a restrictive `Permissions-Policy`, and HSTS (one year) on HTTPS requests. If you add an external script, font or image source, add it to the policy in that middleware, or the browser blocks it.

# Staging Smoke Test

Run these checks after `deploy/staging/bootstrap.sh`.

## Containers

```bash
docker compose ps
docker compose logs --tail=100 app
docker compose logs --tail=100 nginx
docker compose logs --tail=100 mysql
docker compose logs --tail=100 redis
docker compose logs --tail=100 meilisearch
docker compose logs --tail=100 queue
docker compose logs --tail=100 scheduler
```

Expected:

- `mysql`, `redis`, `meilisearch`, and `app` are healthy.
- `nginx`, `queue`, and `scheduler` are running.

## Application Health

```bash
curl -fsS http://127.0.0.1:8080/api/v1/health
docker compose exec app php artisan about --only=environment,cache,drivers
docker compose exec app php artisan route:list --path=api/v1
```

## Redis

```bash
docker compose exec app php -m | grep redis
docker compose exec app php artisan redis:health
docker compose exec app php artisan redis:health --dispatch-test-job
docker compose exec queue php artisan queue:work redis --queue=default,emails,loyalty --once --timeout=60
docker compose exec app php artisan queue:failed
```

Expected:

- `redis:health` returns PASS for client, connectivity, cache, sessions, rate limiter, and queue readiness.
- The one-off queue worker processes the health-check job.

## Meilisearch

```bash
docker compose exec meilisearch curl -fsS http://127.0.0.1:7700/health
docker compose exec app php artisan search:flush
docker compose exec app php artisan search:reindex
curl -fsS "http://127.0.0.1:8080/api/v1/search?q=lenovo"
curl -fsS "http://127.0.0.1:8080/api/v1/search/suggestions?q=len"
```

## Database And Migrations

```bash
docker compose exec app php artisan migrate:status
docker compose exec app php artisan db:show --counts
```

## Feeds

```bash
docker compose exec app php artisan tinker --execute="dispatch(new App\\Jobs\\GenerateFeedJob('google_merchant'));"
docker compose exec queue php artisan queue:work redis --queue=exports --once --timeout=900
curl -fsS http://127.0.0.1:8080/feeds/google-merchant.xml | head
curl -fsS http://127.0.0.1:8080/feeds/facebook-catalog.xml | head
```

## Admin

Open:

```text
http://SERVER_IP:8080/admin
```

Expected:

- Filament login page loads.
- Admin user can log in.
- Catalog, Orders, Imports, Feeds, Marketing, Search, and Users resources are visible according to permissions.

## Checkout Smoke Test

```bash
curl -fsS http://127.0.0.1:8080/api/v1/products
curl -fsS http://127.0.0.1:8080/api/v1/payments/methods
curl -fsS http://127.0.0.1:8080/api/v1/shipping/providers
```

Then perform one browser checkout with mock payment/shipping.

## Frontend Smoke Test

Run from the frontend directory on a host with Node/npm:

```bash
cp .env.example .env
npm ci
npm run build
npm run preview -- --host 0.0.0.0 --port 3000
```

Set these variables before building for staging:

```env
NUXT_PUBLIC_API_BASE_URL=http://SERVER_IP:8080/api/v1
NUXT_PUBLIC_SITE_URL=http://SERVER_IP:3000
NUXT_PUBLIC_GA4_ID=
NUXT_PUBLIC_META_PIXEL_ID=
```

Open:

```text
http://SERVER_IP:3000
http://SERVER_IP:3000/c/SLUG
http://SERVER_IP:3000/p/SLUG
http://SERVER_IP:3000/search?q=lenovo
http://SERVER_IP:3000/cart
http://SERVER_IP:3000/checkout
http://SERVER_IP:3000/login
http://SERVER_IP:3000/account
http://SERVER_IP:3000/b2b
http://SERVER_IP:3000/bundles
http://SERVER_IP:3000/pc-builder
http://SERVER_IP:3000/assistant
```

Expected:

- SSR pages render without hydration errors.
- API calls use the staging backend URL.
- Bulgarian labels render as valid UTF-8.
- Add-to-cart, login, wishlist, B2B quote request, bundle detail and PC Builder routes do not throw client errors.

## Rollback

```bash
docker compose logs --tail=200 app queue scheduler
docker compose down
docker compose up -d mysql redis meilisearch
docker compose up -d app nginx
```

If a migration caused the issue, restore the latest database backup before restarting queue and scheduler workers.

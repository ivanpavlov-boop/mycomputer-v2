# Deployment

## Purpose

Document the current safe VPS deployment process and common container startup issues.

Related docs: [Testing](TESTING.md), [Sync Safety](SYNC_SAFETY.md), [Rollback Plan](ROLLBACK_PLAN.md).

## Current Status

Deployment is Docker-based. Deploy only from `origin/main` after PR merge and passing CI. The stack serves Laravel/Filament through `app` and selected read-only Nuxt storefront routes through `frontend`.

## Allowed

- Deploy merged `origin/main`.
- Build app, queue, and scheduler containers.
- Build the frontend container.
- Start app before nginx.
- Start frontend before nginx.
- Run migrations with `--force`.
- Rebuild Laravel caches after deploy.

## Forbidden

- Do not deploy from feature branches for normal release.
- Do not deploy before merge into `main`.
- Do not skip health checks.
- Do not enable Sync All or automatic sync during deployment.
- Do not enable UPDATE sync during deployment.

## Catalog Sync Feature Flags

Keep catalog sync kill switches explicit in staging/production:

```dotenv
CATALOG_SYNC_CREATE_ENABLED=true
CATALOG_SYNC_UPDATE_ENABLED=false
CATALOG_SYNC_SYNC_ALL_ENABLED=false
CATALOG_SYNC_AUTO_ENABLED=false
```

Set `CATALOG_SYNC_CREATE_ENABLED=false` for an emergency stop of manual selected CREATE sync without disabling read-only preview access.

After deploy, admins can confirm the effective values in the read-only Catalog Sync feature flag panel on Catalog Sync Preview. Do not change real `.env` values through the admin panel; the panel is visibility only. Catalog Sync Batches and Catalog Sync Logs are also visible in Filament as read-only audit history.

## Password Reset Mail

Admin password recovery uses Laravel's password broker and queued mail/notification delivery. Configure VPS mail settings before relying on reset links:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME="${APP_NAME}"
```

Do not hard-code mail credentials in source control. If mail delivery fails, the admin action reports the failure without exposing tokens, passwords or mail credentials.

## Frontend Storefront Routing

The Docker nginx config intentionally exposes only the safe Phase 9A storefront routes through Nuxt:

- `/`
- `/catalog`
- `/categories`
- `/c/*`
- `/p/*`
- `/_nuxt/*`
- `/_ipx/*`

Laravel remains authoritative for:

- `/admin`
- `/api/*`
- `/livewire/*`
- Filament/Laravel assets under `/vendor/*` and `/build/*`
- `/storage/*`

Customer cart, checkout, account, wishlist, compare and auth storefront routes are not enabled through nginx in this phase. Keep them blocked until those flows are explicitly approved.

Frontend runtime configuration:

```dotenv
NUXT_PUBLIC_API_BASE_URL=/api/v1
NUXT_API_SERVER_BASE_URL=http://nginx/api/v1
NUXT_PUBLIC_SITE_URL=https://computer2u.eu
```

`NUXT_PUBLIC_API_BASE_URL` is browser-visible and should usually stay same-origin. `NUXT_API_SERVER_BASE_URL` is private server-side Nuxt configuration used by SSR inside Docker.

## Safe VPS Deploy Command

```bash
cd /var/www/mycomputer-v2

git fetch origin
git reset --hard origin/main

docker compose build app frontend queue scheduler
docker compose up -d app frontend queue scheduler

sleep 10

docker compose up -d nginx

docker compose exec app php artisan optimize:clear

docker compose exec app php artisan migrate --force

docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
docker compose exec app php artisan view:cache

docker compose restart queue scheduler
docker compose restart nginx

sleep 10

curl -I http://localhost:8080
```

## Common Issue: nginx Upstream App Or Frontend Not Found

Symptom:

```text
host not found in upstream "app"
host not found in upstream "frontend"
```

Fix:

1. Start `app` first.
2. Start `frontend`.
3. Wait for PHP-FPM to be ready.
4. Start or restart `nginx`.

Useful checks:

```bash
docker compose ps
docker compose logs app --tail=100
docker compose logs frontend --tail=100
docker compose logs nginx --tail=100
curl -I http://localhost:8080
```

If `curl` returns connection refused immediately after restart, wait and retry.

## Future Work / Open Questions

- Add scripted deployment guard that confirms branch is `origin/main`.
- Add post-deploy smoke tests for `/admin/catalog-sync-preview`.
- Add rollback command/admin action before Phase 8 UPDATE sync.

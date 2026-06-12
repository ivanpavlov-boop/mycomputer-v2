# Monitoring Strategy

Production monitoring should cover application health, queue health, search health, storage and business-critical flows.

Recommended tools:

- Sentry for exceptions and performance traces.
- Laravel Horizon for queue visibility on Linux.
- Laravel Telescope only in local and staging environments.
- External uptime checks for `/api/v1/health`, public feeds and checkout availability.
- MySQL slow query log for catalog, search fallback, orders and imports.
- Meilisearch health and index size monitoring.

Docker staging smoke checks:

```bash
docker compose ps
curl -fsS http://127.0.0.1:8080/api/v1/health
docker compose exec app php artisan redis:health
docker compose exec meilisearch curl -fsS http://127.0.0.1:7700/health
docker compose exec app php artisan queue:failed
```

Critical alerts:

- HTTP 5xx rate above baseline.
- Failed queue jobs in `imports`, `sync`, `exports` or `analytics`.
- Checkout failures.
- Payment webhook signature failures above baseline.
- Feed generation failures.
- Low disk space on storage and database volumes.
- Redis memory evictions.

Rollback monitoring:

- Stop `queue` and `scheduler` before restoring database or storage.
- Watch `app`, `queue`, and `scheduler` logs during restart.
- Confirm `/api/v1/health`, Redis health, Meilisearch health, and feed URLs before reopening staging traffic.

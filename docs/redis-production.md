# Redis Production Guide

Redis should be used in production for cache, queues, rate limiting and optional sessions.

## Implementation

Production Docker uses `phpredis`.

Root cause fixed:

- Laravel was configured with `REDIS_CLIENT=phpredis`.
- The previous Dockerfile did not install the PHP `redis` extension.
- No `predis/predis` fallback package was installed.

The Dockerfile now installs and enables `phpredis` through PECL. Predis is not required for the production Docker path.

Recommended environment:

```dotenv
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
SESSION_CONNECTION=default
SESSION_STORE=redis
REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_DB=0
REDIS_CACHE_DB=1
REDIS_CACHE_CONNECTION=cache
REDIS_QUEUE_CONNECTION=default
REDIS_QUEUE_RETRY_AFTER=1300
```

Queue names:

- `default`: small transactional jobs.
- `emails`: transactional and marketing email automation.
- `loyalty`: loyalty points, tier and reward automation.
- `imports`: XML and CSV import processing.
- `exports`: CSV and merchant feed exports.
- `sync`: supplier product to product sync.
- `analytics`: marketing and conversion events.
- `search`: future Scout indexing isolation if needed.

Use separate Redis databases or prefixes per environment. Enable persistence for self-hosted Redis and monitor memory, evictions, blocked clients and latency.

## Runtime Health Check

Run inside the app container:

```bash
php artisan redis:health
php artisan redis:health --dispatch-test-job
```

The command checks:

- Redis PHP client compatibility.
- Redis connectivity.
- Cache read/write.
- Redis session configuration.
- Rate limiter cache operations.
- Redis queue readiness.
- Optional dispatch of `RedisHealthCheckJob`.

Expected production result:

```text
PASS Redis PHP client
PASS Redis connectivity
PASS Cache read/write
PASS Session driver
PASS Rate limiter
PASS Queue readiness
```

## Docker Smoke Test

Use the Docker environment template:

```bash
cp .env.staging.example .env
# Replace all change-this-* values.
deploy/staging/bootstrap.sh
```

Manual Redis-focused smoke test:

```bash
docker compose build app queue scheduler
docker compose up -d mysql redis meilisearch
docker compose up -d app nginx queue scheduler
docker compose exec app php artisan migrate --force
docker compose exec app php artisan redis:health
docker compose exec app php artisan redis:health --dispatch-test-job
docker compose exec queue php artisan queue:work redis --queue=default --once --timeout=60
```

Check containers:

```bash
docker compose ps
docker compose logs redis app queue scheduler
```

## Troubleshooting

`Class "Redis" not found` or `Redis extension is not loaded`:

- Rebuild the PHP image: `docker compose build --no-cache app queue scheduler`.
- Confirm `php -m | grep redis` inside the app container.

`php_network_getaddresses: getaddrinfo for redis failed`:

- Confirm the service name is `redis`.
- Confirm `REDIS_HOST=redis` inside the app/queue/scheduler containers.
- Run `docker compose ps redis`.

Queue jobs are not processed:

- Confirm `QUEUE_CONNECTION=redis`.
- Confirm the queue worker command includes the expected queue names.
- Run `php artisan queue:failed`.
- Run `php artisan redis:health --dispatch-test-job`, then process one job with a worker.

Sessions do not persist:

- Confirm `SESSION_DRIVER=redis`.
- Confirm `SESSION_CONNECTION=default`.
- Confirm browser cookie domain and HTTPS settings are correct for the environment.

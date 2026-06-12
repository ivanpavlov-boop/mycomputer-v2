# Horizon Guide

Laravel Horizon is the recommended production queue dashboard for Linux deployments.

It is not installed in this Windows portable PHP workspace because the local PHP runtime does not provide `ext-pcntl` and `ext-posix`, which Horizon requires.

Production installation on Linux:

```bash
composer require laravel/horizon
php artisan horizon:install
php artisan migrate
php artisan optimize
```

Required PHP extensions:

- `redis`
- `pcntl`
- `posix`

The Dockerfile installs `phpredis` and `pcntl`. Horizon should be installed and validated on a Linux-based staging/production runtime.

Recommended supervisors:

- `default`: 2-5 processes, timeout 300.
- `emails`: 2-5 processes, timeout 180.
- `loyalty`: 1-3 processes, timeout 180.
- `imports`: 1-2 processes, timeout 1200.
- `exports`: 1-2 processes, timeout 900.
- `sync`: 2-4 processes, timeout 300.
- `analytics`: 2-5 processes, timeout 180.

Protect Horizon with admin-only access and run it behind HTTPS. If Horizon is not installed, use Supervisor workers from `deploy/supervisor/laravel-workers.conf`.

Before enabling Horizon, verify Redis:

```bash
php artisan redis:health
php artisan queue:failed
```

Recommended queue list:

```text
default,emails,loyalty,imports,exports,sync,analytics,search
```

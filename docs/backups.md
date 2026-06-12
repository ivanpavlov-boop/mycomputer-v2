# Backup And Disaster Recovery

Minimum targets:

- RPO: 15 minutes for database on launch, 5 minutes once order volume grows.
- RTO: 2 hours for launch, 30-60 minutes for growth phase.

Back up:

- MySQL database with point-in-time recovery where possible.
- `storage/app/public` product and content images.
- `storage/app/imports` and `storage/app/exports` for auditability.
- Feed snapshots under `storage/app/feeds`.
- `.env` secrets in a secure password manager, never in git.

Restore procedure:

1. Provision clean infrastructure.
2. Restore MySQL backup and run pending migrations.
3. Restore storage bucket or volume.
4. Rebuild cache with `php artisan optimize`.
5. Reindex search with `php artisan search:reindex`.
6. Restart PHP-FPM, queue workers and scheduler.
7. Validate `/api/v1/health`, admin login, product pages, checkout and feeds.

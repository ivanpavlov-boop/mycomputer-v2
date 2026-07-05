# Release Checklist

## Purpose

Define the standard release and deploy guardrails for MyComputer.bg /
COMPUTER2U. This checklist is process guidance only and does not add automated
deploy behavior.

Related docs: [AGENTS](../AGENTS.md), [Deployment](DEPLOYMENT.md), [Testing](TESTING.md),
[Catalog Sync Safety](CATALOG_SYNC_SAFETY.md), [Sync Safety](SYNC_SAFETY.md).

## Standard Workflow

1. Implementation prompt.
2. Read `AGENTS.md` and relevant docs.
3. Implement only the requested scope.
4. Run local validation.
5. Commit scoped changes.
6. Push branch.
7. Open or update PR against `main`.
8. Wait for GitHub Actions CI.
9. Merge only if all required checks pass.
10. Deploy to VPS only after merge into `main` and only when explicitly
    requested.
11. Run post-deploy smoke tests.
12. Report validation, CI, merge/deploy status, and safety confirmations.

## Hard Release Rules

- Never deploy to VPS before PR merge into `main`.
- Never deploy from a feature branch for normal release.
- Never merge with failing CI.
- Do not run VPS commands unless explicitly requested.
- Do not include secrets in PR descriptions, docs, logs, or screenshots.
- Do not enable Sync All during release.
- Do not enable automatic sync during release.
- Do not enable UPDATE sync in production unless the user explicitly requests a
  controlled test and confirms the flag change.

## Local Validation

Run the checks relevant to the phase. For broad or risky phases, run the full
set:

```bash
cd frontend
npm run test -- --run
npm run build
cd ..

composer test
php artisan test
vendor/bin/pint --test
git diff --check
```

Catalog Sync, supplier import, and product attribute phases should also run
targeted checks such as:

```bash
php artisan test --filter=CatalogSync
php artisan test --filter=SupplierImportScheduling
php artisan test --filter=ProductAttributeValues
php artisan test --filter=ProductAttributes
php artisan test --filter=CategoryAttributeSets
php artisan test --filter=Storefront
php artisan test --filter=Catalog
php artisan test --filter=Product
```

Documentation-only changes still require `git diff --check` and should run
Pint/tests when requested by the prompt.

## PR Checklist

- Branch name matches the requested phase.
- Changed files match the expected scope.
- Docs-only changes contain no runtime code changes.
- UI-only changes contain no backend behavior changes.
- No secrets are committed.
- No unintended migrations are added.
- No queue jobs, scheduled jobs, observers, commands, or services are added
  unless explicitly requested.
- Catalog Sync behavior is unchanged unless explicitly requested.
- Sync All is absent.
- Automatic sync is disabled.
- UPDATE sync remains feature-flagged and disabled by default.

## CI Checklist

- Wait for all required checks.
- If checks fail, do not merge.
- Fix only issues related to the current phase.
- Re-run relevant local checks.
- Push the fix to the same branch.
- Wait for CI again.
- Merge only when CI is green.

## VPS Deploy Reference

Use only after the PR is merged into `main` and deployment is explicitly
requested. Do not include secrets in commands or docs.

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

## Post-Deploy Smoke Tests

Run the checks relevant to the phase:

```bash
curl -I http://localhost:8080
curl -I http://localhost:8080/admin
curl -I http://localhost:8080/api/v1/products
curl -I http://localhost:8080/catalog
curl -I http://localhost:8080/categories
curl -I http://localhost:8080/c/iphone
curl -I http://localhost:8080/cart
```

For product detail checks, use a known published product slug:

```bash
curl -I http://localhost:8080/p/{known-slug}
```

Expected current storefront safety:

- `/catalog` works.
- `/categories` works.
- `/c/{slug}` works or returns the expected safe category response.
- `/p/{known-slug}` works for a published product.
- `/api/v1/products` works.
- `/admin` remains Laravel/Filament.
- `/cart` remains disabled or returns the expected safe response until the cart
  phase is explicitly enabled.

## Post-Deploy Operational Checks

```bash
docker compose ps
docker compose logs app --tail=100
docker compose logs frontend --tail=100
docker compose logs nginx --tail=100
```

When relevant:

- Confirm at least one active Super Admin remains.
- Confirm Catalog Sync effective flags:
  - `CATALOG_SYNC_CREATE_ENABLED=true` may be allowed.
  - `CATALOG_SYNC_UPDATE_ENABLED=false` by default.
  - `CATALOG_SYNC_SYNC_ALL_ENABLED=false`.
  - `CATALOG_SYNC_AUTO_ENABLED=false`.
- Confirm scheduled supplier imports stage data only.
- Confirm no unexpected product mutations occurred.

## Rollback Notes

- Prefer reverting the merged PR and redeploying `origin/main`.
- For database migrations, follow the phase-specific rollback plan.
- Do not manually delete catalog products unless there is a dedicated,
  documented recovery step.
- For Catalog Sync incidents, preserve audit batches/logs and staging data for
  review.

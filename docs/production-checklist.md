# Production Checklist

## Before Launch

- Set `APP_ENV=production`, `APP_DEBUG=false` and a strong `APP_KEY`.
- For staging, copy `.env.staging.example` to `.env` and replace every `change-this-*` secret.
- Use MySQL 8, Redis, Meilisearch and HTTPS.
- Set `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis` and `SESSION_DRIVER=redis`.
- Verify `phpredis` with `php -m | grep redis` in the app container.
- Run `php artisan redis:health`.
- Run `php artisan migrate --force`.
- Run `php artisan optimize`.
- Run `php artisan search:reindex`.
- Configure Supervisor or Horizon for `default,emails,loyalty,imports,exports,sync,analytics,search`.
- Verify abandoned cart scheduler commands: `php artisan carts:detect-abandoned` and `php artisan carts:process-abandoned`.
- Set `ABANDONED_CART_THRESHOLD_MINUTES`, `ABANDONED_CART_RECOVERY_TOKEN_DAYS`, `FRONTEND_URL` and `SUPPORT_CONTACT_EMAIL`.
- Configure daily feed generation and verify `/feeds/google-merchant.xml`.
- Configure backups and perform a restore drill.
- Configure Sentry or equivalent exception monitoring.
- Verify Filament users, roles and permissions.
- Verify canonical attribute aliases before onboarding multiple suppliers.
- Configure supplier import schedules for ASBIS, ALSO, PolyComp, APCOM, Most and Decada.
- Verify `php artisan suppliers:run-scheduled-imports` dispatches due suppliers every five minutes.
- Verify import queue workers include `imports,sync,search`.
- Review Supplier Import Runs for empty-feed, minimum-product-count and mass-drop safety warnings before enabling destructive supplier behavior.
- Review Attribute Normalization > Unmapped Attributes and Duplicate Attribute Report after CSV/XML imports.
- Confirm important filter attributes such as RAM, storage, display, CPU socket and PSU wattage map to canonical attributes.
- Verify content CMS permissions for content pages, templates and reusable blocks.
- Browser-check responsive CMS pages in desktop, tablet and mobile viewports before publishing campaigns.
- Verify CMS rich text/custom HTML sanitization and restrict custom HTML blocks to admin users.
- Verify CMS FAQ blocks emit valid FAQPage JSON-LD on `/content/{slug}` pages.
- Verify service ticket permissions for support, managers and administrators.
- Verify service uploads reject invalid file types and keep private file paths hidden.
- Verify warranty calculations against completed orders and product warranty months.
- Verify payment webhook secrets and disable mock credentials for real providers.
- Build the Nuxt frontend with `npm ci` and `npm run build` on a machine with Node/npm.
- Configure `NUXT_PUBLIC_API_BASE_URL`, `NUXT_PUBLIC_SITE_URL`, `NUXT_PUBLIC_GA4_ID` and `NUXT_PUBLIC_META_PIXEL_ID`.
- Browser-check SSR routes for homepage, catalog, product detail, cart, checkout, account, B2B, bundles, PC Builder, assistant and blog.

## Deployment Commands

Staging Docker bootstrap:

```bash
chmod +x deploy/staging/bootstrap.sh
deploy/staging/bootstrap.sh
```

Manual staging sequence:

```bash
cp .env.staging.example .env
docker compose build
docker compose up -d mysql redis meilisearch
docker compose up -d app
docker compose exec app php artisan migrate --force
docker compose exec app php artisan storage:link
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
docker compose exec app php artisan view:cache
docker compose exec app php artisan redis:health
docker compose exec app php artisan search:reindex
docker compose up -d nginx queue scheduler
```

Traditional deployment commands:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
php artisan migrate --force
php artisan redis:health
php artisan optimize
php artisan storage:link
php artisan search:reindex
php artisan queue:restart
```

## Release Validation

- `php artisan test`
- `vendor/bin/pint --test`
- `php artisan route:list --path=api`
- `php artisan redis:health`
- `php artisan suppliers:run-scheduled-imports`
- `/api/v1/health`
- Admin login
- Product listing and detail
- Bundle listing, detail and add-to-cart
- Bundle search results and suggestions
- Cart add/update/remove
- Checkout success
- Feed XML validity
- Abandoned cart detection command
- Abandoned cart recovery token restore
- Abandoned cart email queue processing
- B2B company application and admin approval
- Quote request creation from account, product and cart
- Quote offer acceptance creates an order and ERP sync job
- Quote file upload accepts only allowed document/image types
- Filament B2B permissions restrict company and quote resources
- Nuxt production build passes
- Nuxt preview can reach the staging Laravel API
- Customer auth, cart, checkout, wishlist, B2B quote, bundle and PC Builder flows work in browser
- CMS homepage and campaign pages render responsive blocks and hidden-device blocks do not appear
- Service portal list, create, detail, message, upload and close flows work in browser
- Availability statuses and mappings can be managed in Filament
- Attribute normalization mappings can be managed in Filament and `needs_review` rows do not appear in public filters.
- Product list/detail API responses include `availability`
- Meilisearch has been reindexed after availability changes: `php artisan search:reindex`
- Meilisearch has been reindexed after canonical attribute mapping changes: `php artisan search:reindex`
- CSV/XML supplier imports preserve external availability statuses in staging tables
- Cart and checkout respect `allow_purchase` and `show_stock_quantity`
- Nuxt product cards and product detail pages render dynamic availability badges

See `docs/staging-smoke-test.md` for the full Docker smoke-test list.

## Rollback Notes

- Stop queue and scheduler first: `docker compose stop queue scheduler`.
- Restore the latest database backup if migrations changed data.
- Restore storage volume or object storage snapshot if media/import/export files changed.
- Rebuild and restart the previous image tag.
- Run `php artisan queue:restart` after restoring the app.

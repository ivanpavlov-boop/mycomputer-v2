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

## Public Commerce Gate

For Commerce Phase 1E.1 and later:

- deploy merged code first with public, confirmation and recovery flags false;
- force-recreate `app`, `queue`, `scheduler`, `frontend`, and `nginx` after
  `.env` changes;
- validate the closed route matrix before requesting activation;
- run `php artisan commerce:release-preflight --json`;
- treat missing real Terms or Privacy routes as blockers;
- require explicit human approval before setting public and confirmation true;
- keep English commerce, account/auth, recovery, card and leasing disabled
  unless a separate approved phase changes them;
- use confirmation-only mode for emergency rollback so new checkout stops
  without disabling existing confirmation and authorized payment retry.

The gate implementation or a successful local test does not mean public
commerce is activated. See
[Controlled Public Commerce Release Gate](COMMERCE_PUBLIC_RELEASE_GATE.md).

### CART-023 Controlled Verification Closure

The following controlled-release evidence is complete:

- [x] Legal manifest valid and runtime legal approval confirmed.
- [x] Release preflight ready with no blockers.
- [x] Controlled open route matrix passed for Cart, Checkout and confirmation.
- [x] One controlled cash-on-delivery Checkout passed.
- [x] Exactly one canonical Order and one dedicated Customer snapshot were
  verified.
- [x] Checkout idempotency and individual billing normalization were verified.
- [x] Legal acceptance timestamp, versions and Bulgarian locale were verified.
- [x] Cash on delivery created no payment attempt.
- [x] Localized confirmation returned HTTP 200 with required Bulgarian labels
  and without raw machine values.
- [x] Temporary verification capability was deleted.
- [x] Final verification created no additional Order or Customer.
- [x] Rollback to `confirmation_only` was verified: `/cart` and `/checkout`
  return 404 and `/checkout/success` returns 200.
- [x] Catalog Sync safety and one active Super Admin were verified.

The following future launch decisions remain incomplete and separately gated:

- [ ] Permanent public-commerce activation.
- [ ] Production-domain cutover.
- [ ] Card-provider activation.
- [ ] Leasing-provider activation.
- [ ] Abandoned-Cart recovery activation.
- [ ] Public launch announcement.
- [ ] Production traffic approval.

`CART-023` remediation records controlled technical verification and safe
rollback. It does not authorize permanent commerce activation.

### Cart and Checkout SSR API Base

Before any renewed `open` activation:

- keep the public Nuxt API base browser-relative, normally `/api/v1`;
- keep the private Nuxt server API base separately configured and reachable
  from the frontend container;
- verify production-build SSR returns HTTP 200 for `/cart`, `/checkout` and
  `/checkout/success` in the deterministic `open` fixture;
- scan rendered HTML and public Nuxt runtime configuration for internal API
  hosts;
- verify empty Cart and Checkout states render without hydration or console
  errors and without Order, payment or Cart mutation requests;
- if either Cart or Checkout fails, immediately restore
  `confirmation_only`, where new Cart and Checkout entry remains blocked while
  confirmation stays available.

The staging incident and local fix are recorded in
[Cart and Checkout SSR API Base Fix](COMMERCE_CART_CHECKOUT_SSR_API_BASE_FIX.md).
That local fix alone did not close CART-023 or activate public commerce. The
finding was subsequently remediated only after controlled activation, COD
Checkout, confirmation localization and safe rollback verification.

## Pre-Launch Storefront Navigation

Before releasing storefront navigation changes:

- compare every rendered header, mobile-menu and footer destination with the
  Nginx edge contract;
- keep login, registration, account, wishlist and compare links absent while
  those public routes return 404;
- keep Cart absent in closed and confirmation-only states;
- never add a direct global Checkout or checkout-success link;
- keep both approved Bulgarian legal links available;
- omit the EN locale link on Bulgarian legal pages until equivalent English
  content is approved and published;
- preserve BG/EN switching on the published home, catalog, category and Product
  routes;
- verify the server-rendered HTML already has the correct links, without
  runtime probing or hydration-dependent hiding;
- run the deterministic unit and browser link audits documented in
  [Pre-Launch Storefront Navigation](PRE_LAUNCH_STOREFRONT_NAVIGATION.md).

Navigation cleanup does not activate runtime legal approval or public commerce.
It did not close CART-023 by itself; the finding was subsequently remediated by
the controlled verification recorded above. Permanent activation still
requires a separate explicit release decision.

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

## Public Commerce Legal Gate

Before any public-commerce activation:

- confirm the manifest status is `approved`;
- confirm both legal versions and effective dates are present;
- verify both source SHA-256 values against the exact committed legal files;
- verify the manifest matches the machine-readable project-owner approval
  record;
- confirm `legal_counsel_review=not_claimed` unless a separately documented
  review changes that fact;
- set `LEGAL_CONTENT_APPROVED=true` only in the approved environment;
- run `php artisan commerce:release-preflight --json`;
- verify the Order legal-acceptance migration is applied;
- verify exact Terms and Privacy routes and trailing-slash redirects;
- verify English legal routes remain blocked;
- verify checkout consent links and required checkbox;
- verify confirmation-only rollback still works.

Committed approval metadata is not runtime activation. Never activate public
commerce while `legal_content_approved`, `legal_manifest_valid` or
`legal_effective_dates_present` is blocked. CART-023 was remediated only after
runtime approval, a blocker-free preflight, controlled Checkout verification
and safe rollback. A future permanent launch remains separately gated.

## Checkout Billing Incident Gate

Before any future controlled Order attempt:

- confirm the individual Checkout default does not render company name, VAT or
  a separate billing-address field;
- confirm individual requests send `is_company=false` and derive the billing
  snapshot from the validated shipping address;
- confirm explicit company mode requires company name and billing address;
- confirm switching back to individual mode clears stale company fields and
  the Checkout idempotency key;
- confirm server-side normalization ignores stale company data for individual
  requests;
- confirm address and office delivery retain their existing shipping snapshot
  format;
- confirm COD, legal acceptance and duplicate-submit behavior remain intact;
- confirm card, leasing and abandoned-Cart recovery remain disabled;
- run the closed, `confirmation_only`, open-fixture and invalid route matrices;
- keep public commerce disabled until merge, deployment and staging
  verification are explicitly approved.

The incident attempt submitted no Order. Staging was returned to
`confirmation_only`; that repository change alone did not claim staging
verification or close `CART-023`. The later controlled COD Checkout verified
the correction and contributed to the documented closure.

## Checkout Confirmation Localization Gate

Before final confirmation-page verification:

- confirm the summary shows `Статус на поръчката: Очаква обработка` for a
  pending Order and never displays the raw `pending` value;
- confirm the summary shows a localized payment method without a duplicate
  payment-status line or raw payment code;
- confirm the existing COD, bank-transfer, card and leasing action-panel
  presentations remain unchanged;
- confirm unknown Order and payment-method values fail closed to the documented
  Bulgarian fallbacks;
- confirm the Order number, total, currency and masked email remain present;
- confirm confirmation capabilities, no-store/private responses, URL cleanup,
  analytics and unavailable-confirmation handling remain intact;
- run the closed, `confirmation_only`, open-fixture and invalid release-state
  matrices;
- confirm no Order, Customer, Product, Supplier or `supplier_products` mutation
  occurs from viewing the confirmation;
- keep public commerce, card, leasing and abandoned-Cart recovery disabled;
- keep Catalog Sync UPDATE, Sync All and automatic sync disabled.

The controlled staging COD Order and its snapshots, legal acceptance,
idempotency and confirmation capability were verified before this local fix;
COD created no payment attempt. Staging is documented as `confirmation_only`.
The presentation-only change is now merged, CI verified, deployed and verified
over HTTP 200. Required Bulgarian labels were present, raw machine values were
absent, the temporary capability was deleted, and `CART-023` is remediated.
Public commerce remains disabled.

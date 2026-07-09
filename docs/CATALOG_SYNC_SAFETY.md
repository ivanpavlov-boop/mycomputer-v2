# Catalog Sync Safety Playbook

## Purpose

This playbook records the non-negotiable safety rules for supplier import,
Catalog Sync, product attributes, and future AI-assisted development. It is a
documentation/process guide only and does not change runtime behavior.

Related docs: [Catalog Sync](CATALOG_SYNC.md), [Sync Safety](SYNC_SAFETY.md),
[Supplier Import](SUPPLIER_IMPORT.md), [Data Ownership](DATA_OWNERSHIP.md),
[Product Attributes](PRODUCT_ATTRIBUTES.md), [Release Checklist](RELEASE_CHECKLIST.md).

## Current Safe Position

- Supplier import writes to `supplier_products` staging only.
- Manual selected CREATE sync is enabled through Catalog Sync Preview.
- Manual selected UPDATE price/stock sync exists but is disabled by default.
- Sync All is not enabled.
- Automatic sync is not enabled.
- Scheduled sync is not enabled.
- Supplier image import is not enabled.
- Supplier XML attribute mapping to catalog values is not enabled.
- Frontend attribute filters are not enabled.

Safe feature flag defaults:

```dotenv
CATALOG_SYNC_CREATE_ENABLED=true
CATALOG_SYNC_UPDATE_ENABLED=false
CATALOG_SYNC_SYNC_ALL_ENABLED=false
CATALOG_SYNC_AUTO_ENABLED=false
```

## Supplier Import Staging-Only Rule

Supplier import may:

- download supplier XML/CSV through approved feed services
- parse and validate feed data
- upsert `supplier_products` staging rows
- preserve raw supplier payloads
- record import history and failures

Supplier import must not:

- create catalog `products`
- update catalog `products`
- delete catalog `products`
- soft-delete catalog `products`
- update product prices, stock, availability, names, slugs, SEO, descriptions,
  images, categories, or attributes directly
- use empty or failed feeds to destructively change catalog state

Catalog changes must go through preview-first Catalog Sync paths.

## Manual CREATE Sync Rule

CREATE sync may write only when:

- the admin explicitly selects eligible rows
- the server revalidates those selected supplier product IDs
- the row is still `CREATE`
- the product is not excluded
- no matched catalog product exists
- required minimum data is present
- `CATALOG_SYNC_CREATE_ENABLED=true`

CREATE sync must:

- use per-row try/catch
- continue processing other rows when one fails
- create audit batch/log records
- preserve staging data
- avoid supplier image import unless a future phase explicitly enables it

## UPDATE Sync Rule

UPDATE sync may update only:

- price / calculated price
- supplier cost
- quantity / stock
- availability
- selected supplier offer metadata

UPDATE sync must not update:

- product name
- slug
- SEO title or description
- short or full description
- localized manual content
- images or media
- categories
- attributes/specifications
- product workflow status

UPDATE sync must remain guarded by `CATALOG_SYNC_UPDATE_ENABLED`, which must be
false by default.

## Sync All And Automatic Sync

Forbidden until a dedicated future phase with design, auditability, rollback,
tests, and explicit approval:

- Sync All
- automatic catalog sync
- scheduled catalog writes
- automatic image sync
- automatic supplier category overwrite
- automatic supplier attribute overwrite
- automatic supplier SEO/content overwrite

Scheduled supplier imports may stage supplier rows. They must not create or
update catalog products.

## Supplier Content Ownership

Supplier data may own:

- supplier cost
- calculated price inputs
- stock / quantity
- availability
- supplier offer metadata
- source metadata

Catalog/admin users own:

- product names
- slugs
- SEO
- descriptions
- images
- categories
- attributes/specifications
- localized BG/EN content

Supplier data must not overwrite catalog-owned fields without a future explicit
controlled phase.

## Product Attributes Safety

The correct model is:

- `product_attributes`: internal global definitions
- `category_product_attributes`: category specification templates
- `product_attribute_values`: product-specific values

Current rules:

- Category-assigned attributes may appear as ready fields in Product edit.
- Empty fields must not create rows.
- Manual product values are stored only when explicitly saved.
- Supplier XML must not directly create or overwrite product values.
- Future supplier XML mapping must be preview-first and manually approved.
- Frontend filters require a future phase after data quality is controlled.

## Supplier Category Mapping Safety

Supplier categories are staging/import inputs. They are not allowed to overwrite
catalog categories directly.

Phase 9C.5.5 adds `canonical_product_families` and
`supplier_category_mappings` as a safe taxonomy planning layer:

```text
supplier category
-> pending supplier category mapping
-> canonical product family
-> future internal category/template plan
```

Safety rules:

- Supplier category mapping candidates default to `pending_review`.
- Mapping candidates are not auto-approved.
- Supplier category mappings must not create catalog categories.
- Supplier category mappings must not move products.
- Supplier category mappings must not update `products.category_id`.
- Supplier category mappings must not rename or restructure existing categories.
- Supplier category mappings must not overwrite category SEO, descriptions, or
  images.
- Supplier category mappings must not create or update
  `category_product_attributes`, `product_attribute_values`,
  `product_attributes`, or `attribute_values`.
- Supplier category mappings must not trigger Catalog Sync, Sync All,
  automatic sync, or supplier XML attribute mapping.
- The supplier category mapping review workflow may mark mapping records as
  approved, rejected, ignored, or pending review again. These status changes are
  review metadata only and must not apply mappings to products or categories.
- `target_category_id` is optional future-use metadata. An approved mapping with
  no target category is allowed and still must not mutate catalog categories.

Any future phase that applies mappings to products or internal category
templates must be preview-first, manually approved, audited, tested, and
explicitly requested.

## Multi-Supplier Discovery Safety

Phase 9C.6 adds `suppliers:audit-discovery` as a read-only multi-supplier
staging audit before more supplier category mappings are reviewed.

Discovery commands may inspect:

- suppliers
- `supplier_products`
- supplier category mapping status
- identifier completeness
- duplicate identifiers inside one supplier
- possible cross-supplier EAN/GTIN, MPN, brand + MPN, or low-confidence name
  overlaps

Discovery commands must not:

- run supplier imports
- call Catalog Sync
- create, update, delete, or soft-delete catalog products
- mutate `supplier_products`
- create, approve, reject, ignore, or apply supplier category mappings
- create categories or change category hierarchy
- create canonical product families
- create or update `category_product_attributes`
- create or update `product_attributes`, `attribute_values`, or
  `product_attribute_values`
- link supplier staging rows to catalog products
- create offer groups or supplier offer metadata
- expose supplier staging data publicly

The only valid output of this phase is reporting: table or JSON summaries,
issue lists, overlap candidates, and explicit zero-change counters for
protected tables.

## Supplier Import Capability Audit Safety

Phase 9C.6.1 adds `suppliers:audit-import-capabilities` as a read-only supplier
import capability audit before any additional supplier staging import is added.

The command may inspect:

- suppliers and safe supplier scheduling metadata
- supplier feed metadata
- active XML mapping template presence
- latest supplier import run status
- staged `supplier_products` counts and identifier completeness
- static importer/driver class availability

The command must not:

- run supplier imports
- fetch remote feeds
- call supplier APIs
- dispatch queue jobs
- call Catalog Sync
- mutate products, suppliers, `supplier_products`, categories, supplier category
  mappings, canonical families, product attributes, attribute values,
  `product_attribute_values`, or `category_product_attributes`
- expose supplier feed secrets

Feed URLs are reported with hosts visible but sensitive path segments and query
values redacted. Authentication output is limited to boolean presence markers
such as username/password/token/header configured, never raw credential values.

The only valid output of this phase is reporting: table or JSON supplier
capability rows, optional static driver/schedule/config/checklist sections, and
explicit zero-change counters for protected tables.

## Supplier Schedule Safety Cleanup

Phase 9C.6.2 adds `suppliers:cleanup-unsafe-schedules` as a dry-run-first
supplier configuration safety cleanup before the next supplier staging import.

Suppliers that are active and import-enabled should not keep scheduled imports
enabled when they have no usable feed/import configuration. Missing feed URL,
missing import driver, and no staging data together create operational noise and
risk without producing safe staged data.

The command may inspect the same safe supplier capability data as
`suppliers:audit-import-capabilities`. By default it is read-only and reports
which supplier schedules would be disabled.

Apply mode is explicit:

```bash
php artisan suppliers:cleanup-unsafe-schedules --apply
```

Apply mode may only set the supplier schedule flag off for suppliers that are:

- active
- `import_enabled=true`
- `schedule_enabled=true`
- missing an active feed URL or missing a configured import driver
- holding zero staged `supplier_products`
- classified as `missing_feed_url`, `missing_import_driver`, or
  `no_staging_data`

The cleanup must not disable the supplier itself, delete feeds, change
`import_enabled`, run imports, fetch remote feeds, call supplier APIs, dispatch
queue jobs, call Catalog Sync, expose secrets, or mutate products,
`supplier_products`, categories, supplier category mappings, canonical families,
product attributes, attribute values, `product_attribute_values`, or
`category_product_attributes`.

The only write allowed by explicit apply is the supplier schedule flag and the
normal model timestamp update for the affected supplier rows. Dry runs and JSON
output must include protected-table zero-change counters.

## Next Supplier Staging Import Preview Safety

Phase 9C.6.3 adds `suppliers:preview-staging-import` as a preview-only parser
for local XML, CSV, and JSON supplier feed samples before any next-supplier
staging import is allowed.

The command may inspect only local source files or explicit test fixtures. It
reports detected raw fields, normalized field coverage, identifier coverage,
category coverage, price/stock coverage, possible overlaps with existing
`supplier_products`, row issues, and future staging action labels such as
`would_create_supplier_product`, `would_update_supplier_product`, or
`would_skip_row`.

Safety rules:

- It has no `--apply` option.
- It refuses HTTP and HTTPS sources.
- It does not fetch remote feeds or call supplier APIs.
- It does not run supplier imports.
- It does not dispatch queue jobs.
- It does not call Catalog Sync.
- It does not mutate products, suppliers, `supplier_products`, categories,
  supplier category mappings, canonical families, product attributes, attribute
  values, `product_attribute_values`, or `category_product_attributes`.
- It does not expose feed secrets, raw long descriptions, or full image URLs.
  Image output is limited to presence and host diagnostics.
- It does not add admin UI, frontend filters, Sync All, automatic sync,
  supplier image import, or supplier attribute mapping.

The only valid output of this phase is reporting: table or JSON summaries,
preview rows, overlap candidates, issue lists, and explicit zero-change
counters for protected tables. Real staging writes remain a future phase.

## ASBIS Dual-Feed Local Preview Safety

Phase 9C.6.4.1 adds `suppliers:preview-asbis-dual-feed` as a local-only,
preview-only join diagnostic for ASBIS ProductList and PriceAvail files.

Example:

```bash
php artisan suppliers:preview-asbis-dual-feed --supplier=asbis --product-list=/path/ProductList.xml --price-avail=/path/PriceAvail.xml
```

Safety rules:

- `--supplier` is required and must resolve to ASBIS.
- Both ProductList and PriceAvail inputs must be local files or test fixtures.
- HTTP and HTTPS sources are refused with a redacted source label. Remote feed
  fetching, supplier APIs, queues, jobs and scheduled imports are not used.
- There is no `--apply` option.
- Join keys are detected in memory from safe candidate identifiers, or can be
  supplied explicitly with `--product-key` and `--price-key`.
- Ambiguous or missing join keys are diagnostic issues only and do not crash the
  command.
- Output may show field maps, normalized fields, identifiers, categories,
  unmatched rows, row issues, cross-supplier overlap candidates and future
  staging action labels.
- Full supplier feed URLs, secrets, raw long descriptions and full image URLs
  must not be printed. Image diagnostics are limited to presence and host.
- Cross-supplier EAN, MPN and brand+MPN overlaps are report-only.
- ProductList-only and PriceAvail-only rows remain manual-review diagnostics.

The command must not mutate products, suppliers, `supplier_products`,
categories, supplier category mappings, canonical families, product attributes,
attribute values, product attribute values, category attribute assignments,
Catalog Sync batches/logs or schedules. It does not create categories, apply
supplier mappings, import images, dispatch jobs, call Catalog Sync, add Sync All
or enable automatic sync.

Protected-table counters must always be zero, including `supplier_products`.
Controlled ASBIS staging writes remain a later explicit phase.

## Controlled Supplier Staging Import Apply Safety

Phase 9C.6.4 adds `suppliers:controlled-staging-import` as the first
dry-run-first, explicit-apply path for one new supplier after APCOM: ASBIS.

Default behavior is dry-run. Dry-run parses a local XML, CSV, or JSON file,
reports what would be created or updated in `supplier_products`, reports
invalid/skipped rows, duplicate supplier SKUs, cross-supplier identifier
overlaps, and prints protected-table zero-change counters.

Apply mode is intentionally narrow:

```bash
php artisan suppliers:controlled-staging-import --supplier=asbis --fixture=tests/Fixtures/Suppliers/asbis_staging_import.xml
php artisan suppliers:controlled-staging-import --supplier=asbis --fixture=tests/Fixtures/Suppliers/asbis_staging_import.xml --apply --confirm-supplier=asbis
```

Safety rules:

- `--supplier` and a local `--source` or `--fixture` are required.
- HTTP and HTTPS sources are refused. Remote feed fetching remains disabled.
- `--apply` requires the exact confirmation `--confirm-supplier=asbis`.
- Apply is ASBIS-only in this phase.
- Apply may create or update only ASBIS `supplier_products` rows matched by
  `supplier_id` plus `supplier_sku`.
- Apply must not delete supplier products, mark absent rows discontinued, or
  mark absent rows out of stock.
- Apply must not change supplier feed URLs, supplier credentials,
  `schedule_enabled`, `import_enabled`, supplier active state, or supplier
  schedule configuration.
- Apply must run in a database transaction and roll back staging writes if the
  apply fails.
- Cross-supplier EAN, MPN, or brand+MPN overlaps are report-only and must not
  link rows or mutate other suppliers.
- Supplier category names remain staging metadata only.
- Supplier image URLs may be preserved only as raw staging metadata; images are
  not downloaded or imported.
- The command must not dispatch jobs, call Catalog Sync, create catalog
  products, update catalog products, create categories, apply supplier category
  mappings, create canonical families, create attributes, create attribute
  values, or create product attribute values.

Protected-table counters must always show zero changes for products,
categories, suppliers, supplier category mappings, canonical families, category
product attributes, product attributes, attribute values, product attribute
values, and Catalog Sync. In dry-run, `supplier_products` changes must also be
zero. In explicit apply, `supplier_products` is the only counter allowed to be
greater than zero.

## Phase 9C.4.2 Incident Summary

Before Phase 9C.4.2, an old scheduled supplier import path created three catalog
products automatically from supplier data. This violated the staging-only rule.

Phase 9C.4.2 fixed the scheduled import path so supplier imports stage data only
and no longer create catalog products automatically.

Safety lesson:

- A disabled automatic sync flag is not enough if another import path can write
  products.
- Supplier import jobs must be reviewed as potential catalog write paths.
- Tests must prove scheduled imports do not mutate catalog products.

## Phase 9C.4.3 Cleanup Summary

Phase 9C.4.3 added a dry-run-first allowlisted command for the three known
products created before the Phase 9C.4.2 hotfix:

- `VMA3600-10000S`
- `VMC4460P-100EUS`
- `VMC4260P-100EUS`

The command is limited to review/status fields and must not delete products,
mutate `supplier_products`, mutate `product_attribute_values`, mutate
`category_product_attributes`, or change content, SEO, images, categories,
attributes, price, stock, or supplier offer metadata.

## Required Tests For Catalog Sync PRs

Any PR touching supplier imports, Catalog Sync, product sync, product staging,
scheduled jobs, queue jobs, or product write paths must include relevant tests
for:

- supplier imports remain staging-only
- no unexpected catalog product creation
- no unexpected catalog product update
- no `supplier_products` mutation outside import/staging scope
- no `product_attribute_values` mutation unless explicitly requested
- no `category_product_attributes` mutation unless explicitly requested
- CREATE sync revalidates selected rows server-side
- UPDATE sync remains feature-flag guarded and commercial-field-only
- Sync All is absent
- automatic sync is disabled
- protected content fields are not overwritten

Recommended targeted checks:

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

## PR Review Questions

- Does this PR add any new write path?
- Does this PR touch a scheduler, command, job, observer, import service, or
  product sync service?
- Could this PR create catalog products outside manual selected CREATE sync?
- Could this PR update catalog products outside guarded UPDATE sync?
- Could this PR mutate `supplier_products`, `product_attribute_values`, or
  `category_product_attributes` unexpectedly?
- Does the PR broaden supplier ownership of catalog content?
- Does the PR expose supplier staging data publicly?
- Does the PR add Sync All or automatic sync?
- Are tests proving the safety boundary?

If any answer is unclear, do not merge until the behavior is documented and
tested.

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

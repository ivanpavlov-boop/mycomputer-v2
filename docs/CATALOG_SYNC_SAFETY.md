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

### Supplier Onboarding Contract Boundary

Phase 9C.6.5A adds local, immutable onboarding contracts and pure
normalization/fingerprint services only. They do not register a generic driver,
fetch feeds, dispatch jobs, write staging or catalog tables, call Catalog Sync,
import images, or enable schedules. `StagingPlan` is a create-only
`supplier_products-only` planning structure with updates fixed at zero; it has
no apply method. The phase does not select supplier #2 and does not add a new
supplier.

Phase 9C.6.5B adds only `suppliers:audit-onboarding-readiness-matrix`, a
read-only local matrix over existing supplier configuration, capability audit
facts, staging provenance/counts, mapping counts, and effective Catalog Sync
flags. It cannot fetch a feed, invoke an import/preview/apply/verifier command,
write any protected table, call Catalog Sync, dispatch work, enable schedules,
or select a supplier. Unsafe UPDATE, Sync All, or automatic-sync flags produce
an unsafe audit verdict; the command never changes those flags. The next
supplier-selection phase remains blocked pending a production read-only matrix
review and explicit human approval.

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

## ASBIS Full-File Streaming Readiness Audit

Phase 9C.6.4.1c extends the local dual-feed preview with `--full-file` and adds
`suppliers:audit-asbis-apply-readiness`. Both paths use `XMLReader` to stream
repeated `ProductCatalog > Product` and `CONTENT > PRICE` rows. Full-file mode
does not apply the bounded preview's 5,000-row cap.

The readiness audit is advisory and read-only. It reports exact full-file join,
identifier, availability, pricing, category/content coverage and readiness
counts. It also records the local ProductList and PriceAvail file sizes and
SHA-256 fingerprints so a future controlled apply can be reviewed against the
exact audited inputs.

Safety rules:

- Local files or repository fixtures are required; remote feed fetching is
  refused and source query values are never printed.
- XML parsing disables DTD loading, entity substitution and network access.
- There is no `--apply` option and the audit must not write approval records.
- Apply-readiness states and the final verdict are advisory only.
- Duplicate ProductCode or WIC keys are blockers and are never resolved by
  silently selecting a first or last row.
- Row samples are bounded independently from complete aggregate counts.
- No supplier schedule is enabled, no job is dispatched, no Catalog Sync path
  is called and no images are downloaded or imported.
- Products, `supplier_products`, categories, mappings, canonical families and
  attribute tables must all retain zero-change counters.

Phase 9C.6.4.2 adds the guarded implementation of
`suppliers:controlled-asbis-dual-feed-staging-import`. The command remains
dry-run-first and the apply feature flag is false by default. A real apply is
still blocked until the audit has been run against reviewed local files, the
source and candidate fingerprints/counts are approved, the ASBIS staging count
is confirmed, and a controlled approval window is explicitly opened.

## ASBIS Audit Consistency and Missing-Key Safety

Phase 9C.6.4.1d keeps the full-file ASBIS audit read-only while making its
reports internally consistent. ProductCode, WIC, EAN, MPN and brand+MPN values
are normalized canonically: whitespace and empty values become null, while
EAN leading zeroes are preserved. Null or empty identifiers are never indexed,
compared, or reported as overlap groups.

Cross-supplier overlap sections report both distinct overlap groups and the
number of affected ASBIS rows. The summary, identifier audit, overlap audit,
readiness warnings and issue counts use the same canonical intersections; a
large set of identifiers belonging only to another supplier is not reported as
an ASBIS overlap.

ProductList rows without ProductCode and PriceAvail rows without WIC are not
joined or classified as product-only/price-only rows. They are blocked with
`missing_product_code` or `missing_wic` plus `missing_supplier_sku` as
appropriate. Missing names are also blockers, and one row may report both a
missing join key and `missing_name`.

The audit exposes reconciliation details for physical rows, indexed rows,
unique join keys, valid product-only/price-only keys, missing keys, duplicate
affected rows and malformed rows. `reconciliation_valid` must be true before a
completed audit can receive a clean readiness verdict. Primary readiness
counters remain mutually exclusive: candidates are ready-to-create,
ready-to-update or ready-with-warning; exclusions are hard blockers, manual
review, valid unmatched product-only and valid unmatched price-only buckets.
`apply_excluded_count` is the sum of those exclusion buckets and is an audit
counter only.

The controlled apply consumes the same canonical classification and admits
only `ready_to_create` rows. `ready_with_warning`, `ready_to_update`, manual
review, product-only and price-only rows are excluded; warnings do not become
write candidates. The candidate payload is normalized and sorted by supplier
SKU before a SHA-256 candidate-set fingerprint is calculated. Timestamps,
database IDs, file paths and sample order are excluded from that fingerprint;
EAN values retain leading zeroes.

Apply requires `--apply`, the false-by-default
`ASBIS_DUAL_FEED_STAGING_APPLY_ENABLED` flag,
`--confirm-supplier=asbis`, `--confirm-mode=create-only`,
`--confirm-write-scope=supplier_products-only`, expected ProductList and
PriceAvail SHA-256 values, the expected ready-to-create count, the expected
candidate-set fingerprint, and the expected current ASBIS staging count. The
source files are hashed again immediately before the transaction; any change
aborts with `source_changed_during_preflight`.

The transaction locks the ASBIS supplier row, takes the existing supplier
import lock, rechecks every candidate for same-supplier SKU conflicts and
inserts only new `supplier_products` rows in bounded batches. It never updates,
upserts, silently skips or deletes an existing ASBIS row. Any conflict,
verification failure or exception rolls the entire transaction back. The only
allowed non-zero change counter is `supplier_products`; products, categories,
suppliers, mappings, canonical families, attributes, Catalog Sync tables and
other protected tables must remain unchanged.

The command does not fetch remote URLs, dispatch jobs, call Catalog Sync,
enable schedules, download images, expose secrets, or write products. After a
real controlled apply, the feature flag must be disabled again. A second run
must report an existing staging conflict rather than duplicate or update rows.

The next operational follow-up is the separately approved 9C.6.4.2.1
verification window. Real production verification remains outside this
implementation and requires local source fingerprints, expected counts and
explicit approval after deployment; this verifier does not perform that step.

## ASBIS MySQL Apply Compatibility and Transaction Diagnostics

Phase 9C.6.4.2a hardens the controlled ASBIS staging path for the production
MySQL schema after safely rolled-back apply attempts. The command now builds a
canonical database-bound payload before the transaction and validates that
payload against the existing `supplier_products` write contract.

The canonical candidate schema is `asbis-dual-feed-staging-candidate-v2`:

- DB-bound names are truncated deterministically with Unicode-safe character
  slicing at 255 characters.
- When a name is truncated, the complete original name and bounded length
  metadata remain in `raw_data`; untruncated names retain the same length
  metadata without duplicating the full value.
- Supplier SKU, ProductCode/WIC, EAN, MPN, currency, payload hash and other
  identifiers are never silently truncated. An overflow blocks the apply.
- Descriptive fields are validated against the existing schema contract rather
  than silently shortened.
- New rows use the existing canonical staging status `new`, remain unlinked,
  and keep `product_id` null.
- The candidate fingerprint includes the final staged name, status, and raw
  truncation metadata. It remains independent of row order, timestamps, IDs,
  and source paths; the v1 candidate hash is not valid for v2.

The read-only compatibility report includes candidate count, truncation count,
maximum original/staged name lengths, field-length violations, bounded samples,
unknown fields, JSON failures, availability-status validity, nullability,
decimal and unsigned-integer validation. The apply refuses with
`payload_schema_incompatible` when the canonical contract is not valid or the
runtime schema is missing a required column. No migration is required.

Transaction failures report only safe diagnostics: transaction stage, batch
number, total batches, batch size, exception class, SQLSTATE, driver error
code, diagnostic code, attempted rows and committed rows/batches. SQL text,
bindings, product data, source paths, credentials and raw database messages are
not exposed. Query failures are classified into safe codes such as
`database_string_length_violation`, `database_numeric_range_violation`,
`database_foreign_key_violation`, `database_duplicate_key_violation`,
`database_json_encoding_failure`, and `database_write_failed`.

If a batch fails, attempted rows may be reported separately, but committed rows
and `records_changed.supplier_products` remain zero after rollback. The feature
flag remains false by default. A later controlled v2 apply completed
successfully with 4,844 ASBIS staging-only rows, all unlinked and with Catalog
Sync disabled afterward. The historical apply and the later production
verification are documented separately below.

## ASBIS Post-Apply Verification and Reconciliation Audit

Phase 9C.6.4.2.1 adds
`suppliers:audit-asbis-post-apply-verification` as a read-only verification
command for a completed controlled v2 staging apply. It reconstructs the
source-derived candidate set from local ProductList and PriceAvail files and
compares it with ASBIS `supplier_products` rows without repairing differences.

The audit verifies:

- expected and actual local source SHA-256 fingerprints;
- the v2 candidate schema, candidate count and candidate-set fingerprint;
- normalized SKU coverage, missing/extra/duplicate SKU groups and blank staged
  identifiers;
- canonical staged row fields, including price, supplier cost, availability,
  currency, status, payload hash and raw-data equality;
- ASBIS dual-feed raw-data provenance, Unicode-safe name truncation metadata,
  availability status validity and pricing invariants;
- ASBIS and total staging counts, protected-table count invariants, feature flags
  and supplier schedule state.

The command accepts only local files or fixtures. It has no apply, repair, sync,
delete, rebuild, schedule, image, or write option; it does not call Catalog
Sync, dispatch jobs, fetch URLs, expose source paths/secrets, or mutate any
table. `records_changed` is always zero. Verification exits successfully only
when all required expectations and reconciliation checks pass. Issue counts and
samples are bounded and do not include raw payloads or product data.

The verifier implementation and its controlled local tests are read-only. The
production verification closeout record below documents the separately approved
verification run completed on 2026-07-11. It did not repair differences or run
Catalog Sync. The apply flag, Catalog Sync UPDATE/Sync All/automatic flags and
ASBIS schedule remain disabled unless explicitly approved.

## ASBIS Production Apply and Verification Closeout

This is a historical operational record for the controlled ASBIS v2 staging
apply and its production read-only verification. It does not authorize another
apply, Catalog Sync, automatic import, or supplier onboarding step.

### Historical controlled staging apply

- 4,844 ASBIS rows were inserted into `supplier_products`.
- All rows remain staging-only with `product_id` null and `status=new`.
- No ASBIS row has been synced; no Catalog Sync operation was run.
- No catalog product was created or updated.
- Categories, attributes, supplier category mappings, and internal taxonomy
  were unchanged.
- The ASBIS apply feature flag was disabled after the controlled apply.

### Production post-apply verification

The production report completed on 2026-07-11 with:

- `success=true`
- `mode=post_apply_verification`
- `read_only=true`
- `verification_passed=true`
- `verdict=verified`
- exit code `0`
- strict validator result `POST_APPLY_VERIFICATION_PASSED`

Locked source and candidate contract:

- ProductList SHA-256:
  `f23bdcbaeaf1a17dc72a6da3ec21e6e63a5de17851107c65e111484c136173e2`
- PriceAvail SHA-256:
  `9b6b82bcf190b3dc76b404d7097393644ea04814329153289b49d90d48da6558`
- candidate schema: `asbis-dual-feed-staging-candidate-v2`
- candidate SHA-256:
  `79771f6d63f7f1f376dafb2a0fe0fa4460de081334134ebe69c6f2602a730c53`
- expected and calculated candidate count: `4844`
- candidate count matches: `true`
- candidate set matches: `true`
- source fingerprints match: `true`

Database and reconciliation result:

- products: `1865`
- total `supplier_products`: `6717`
- ASBIS `supplier_products`: `4844`
- linked ASBIS rows: `0`
- ASBIS rows with `status=new`: `4844`
- ASBIS rows with `pending_review`: `0`
- source candidate SKUs and staged unique SKUs: `4844`
- missing, extra, duplicate-group, and blank SKU counts: `0`
- canonical rows compared: `4844`
- field, payload hash, name, price, availability, status, and raw-data
  mismatches: `0`
- linked product rows, synced rows, and pending-review rows: `0`

Provenance, truncation, availability, and pricing checks all passed:

- 4,844 provenance rows checked with zero mismatches across source, supplier,
  ProductCode, WIC, source-hash, and candidate-schema metadata.
- 16 names were truncated safely; all 16 retained `original_name`, with zero
  missing originals, over-limit staged names, or invalid metadata. Maximum
  original/staged lengths were 325/255 Unicode characters.
- Availability normalized to `in_stock=1032`, `limited_stock=1183`, and
  `on_request=2629`, with zero invalid IDs, unknown statuses, or mismatches.
- Invalid, negative, non-EUR, price, supplier-cost, currency, and pricing
  mismatch counts were all `0`.

Safety state during verification:

- ASBIS apply: `false`
- Catalog Sync CREATE: `true`
- Catalog Sync UPDATE: `false`
- Sync All: `false`
- automatic sync: `false`
- ASBIS schedule: `false`

All protected-table before/after counts were identical. Every
`records_changed` counter was `0`, including `products`, `supplier_products`,
`suppliers`, `categories`, `supplier_category_mappings`,
`canonical_product_families`, `category_product_attributes`,
`product_attributes`, `attribute_values`, `product_attribute_values`,
`catalog_sync_batches`, `catalog_sync_logs`, and `catalog_sync`. Existing
`catalog_sync_batches` and `catalog_sync_logs` counts were both `1` before and
after verification; the verifier created no new batch or log.

Production smoke checks returned `/catalog=200`, `/categories=200`,
`/api/v1/products=200`, `/admin=302` to login, and `/cart=404` as expected;
Docker, app, MySQL, and Meilisearch remained healthy.

The production JSON report and its `latest` alias under
`storage/app/imports/asbis/reports/` are runtime artifacts. They must not be
committed. No second staging apply is permitted for the same approved
candidate set.

Future supplier onboarding and future manual CREATE sync remain separate,
explicitly approved phases. These facts do not claim that ASBIS catalog
products were created, published, or synced.

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

## Phase 9C.6.5C APCOM Legacy Discovery

APCOM is Supplier #1 and remains an existing legacy integration. The local
legacy audit and local XML profiler are discovery-only commands. They do not
re-import APCOM, fetch its configured feed, call supplier APIs, modify its
schedule, write `supplier_products`, repair links, change catalog content,
run Catalog Sync, dispatch jobs, or download images.

The legacy audit returns `supplier-legacy-staging-audit-v1` with safe supplier
configuration facts, staging inventory, identifier diagnostics, linked-state
analysis, catalog comparison and content-isolation indicators, mapping state,
import history, schedule safety, before/after counts, and zero mutation
counters. Exact or normalized equality is diagnostic only and does not prove
that supplier content overwrote catalog content. Enabled schedules with
unverified linked staging produce `schedule_must_be_frozen`; the command never
changes the schedule.

The local XML profiler returns `supplier-source-profile-v1` and a non-persisted
`supplier-feed-profile-draft-v1`. It accepts only an explicitly provided local
XML file, streams it with XMLReader, rejects remote URLs and stream wrappers,
redacts values and image URLs, and requires human review. It never uses a
configured feed URL or changes supplier configuration.

Both commands verify the safe flag state: `CATALOG_SYNC_CREATE_ENABLED=true`,
`CATALOG_SYNC_UPDATE_ENABLED=false`, `CATALOG_SYNC_SYNC_ALL_ENABLED=false`,
and `CATALOG_SYNC_AUTO_ENABLED=false`. Unsafe flags return
`unsafe_configuration` and are not changed.

## Phase 9C.6.5C.1 Controlled Supplier Schedule Freeze

The separate `suppliers:controlled-schedule-freeze` command provides a
dry-run-first stability guard for one explicitly selected supplier before a
deterministic read-only audit. It does not change the meaning of
`suppliers:cleanup-unsafe-schedules`; APCOM may remain safe for staging-only
catalog classification while its schedule is temporarily frozen for audit
stability through a separately approved operation.

The command reads supplier state, staging/link counts, available active import
state, protected-table counts, and effective Catalog Sync flags. Dry-run is the
default and reports the planned `schedule_enabled=true -> false` change with
zero mutation counters. Apply requires all explicit confirmations and locked
expected state, revalidates inside a transaction with a supplier row lock, and
may change only `suppliers.schedule_enabled`. It verifies import settings,
schedule type, staging/link counts, protected counts, and safe flags before
commit; a postcondition mismatch rolls back.

Operational coordination remains external: run a fresh dry-run, stop the
scheduler container, confirm no active import, apply, verify the flag, restart
the scheduler, then run the separate read-only audit. The command never stops
or starts containers, never fetches feeds, runs imports, dispatches jobs, calls
Catalog Sync, writes staging/catalog/taxonomy data, or automatically unfreezes
a schedule. The completed APCOM operation changed only
`suppliers.schedule_enabled: true -> false`; `import_enabled=true` and the
twice-daily schedule type remain unchanged. The scheduler was coordinated
externally and restarted after the operation.

## Phase 9C.6.5C.2 Deterministic Audit Closeout

The post-freeze APCOM deterministic audit completed as a read-only comparison
with `FINAL_AUDIT_EXIT=0`, `COMPARE_EXIT=0`, and
`APCOM_DETERMINISTIC_AUDIT_COMPARISON_PASSED`. The final verdict is
`legacy_state_requires_review` with no blockers. The remaining warnings are
`staging_present_without_verification` and `historical_causation_unknown`.
They describe evidence limits; they do not authorize a re-import, link repair,
mapping approval, Catalog Sync, or catalog-content change.

No Catalog Sync, import, queue dispatch, image operation, or protected-table
write occurred during the audit. The controlled freeze changed only the
supplier schedule flag; supplier staging, products, taxonomy, mappings,
attributes, and audit-log counters remained unchanged. Current effective
safety flags remain CREATE enabled, UPDATE disabled, Sync All disabled, and
automatic sync disabled. APCOM remains frozen until a separately approved
operational decision.

The closeout evidence and interpretation limits are recorded in
`docs/APCOM_DETERMINISTIC_AUDIT_CLOSEOUT.md`.

## Phase 9C.6.5C.3 Local Source Normalization Planning

`suppliers:plan-local-source-normalization` is a strictly read-only planning
surface. It accepts only an explicitly supplied, SHA-256-pinned local XML
file; it rejects remote URLs, stream wrappers, malformed sources, stale
baseline locks, changed schedules, active/unknown imports, and unsafe Catalog
Sync flag combinations. It emits coverage, normalization, collision, and
policy diagnostics without raw source values or image URLs.

The command cannot import or write `supplier_products`, catalog products,
categories, mappings, attributes, images, audit records, or feature flags. It
has no apply mode and does not call Catalog Sync, run CREATE or UPDATE, add
Sync All, enable automatic sync, queue a job, or alter a schedule. An
authorized C.3 profile has run without writes; the source and report remain
outside Git. See `docs/APCOM_LOCAL_SOURCE_NORMALIZATION_PLAN.md`.

## Phase 9C.6.5C.3A Official Semantics Reconciliation

`suppliers:reconcile-local-source-staging` is strictly read-only local tooling.
It uses `apcom-official-v1` only as an internal review contract, validates the
same frozen baseline and safe Catalog Sync flags, and reuses the active-import
guard. It never persists a semantics profile or changes a supplier, staging
row, product, mapping, attribute, image, queue, schedule, or Catalog Sync.

The tool accepts no apply/persist/import/sync/fetch/download controls. Its
only authoritative match is exact normalized-safe `partno` to staged
`supplier_sku`; EAN and normalized comparisons are diagnostic only. It must
not infer quantity from `stock`, MPN from `partno`, currency/VAT, a DAC/FD
price choice, or greentax. The first strict C.3A run safely failed closed on
non-binary observed stock values with no mutations. C.3A.1 adds an unresolved
numeric-stock review profile only; it cannot approve stock or availability,
persist a profile, import, or alter Catalog Sync. See
[APCOM Observed Stock Semantics Discrepancy](APCOM_OBSERVED_STOCK_SEMANTICS_DISCREPANCY.md).

The observed-profile reconciliation completed read-only under C.3A.2. Its
review outcome did not authorize any Catalog Sync action, stock mapping,
staging mutation, link change, or catalog mutation.

## Phase 9C.6.5C.3A.2 APCOM Reconciliation Review Closeout

The authorized observed-profile APCOM reconciliation completed read-only. No
Catalog Sync occurred during reconciliation. No `supplier_products` mutation,
product mutation, product-link mutation, deletion, import, queue dispatch,
mapping approval, category change, attribute change, or image operation
occurred.

The exact source/staging review recorded `1803` source rows, `1872` staging
rows, `1786` exact matches, `17` source-only rows, `86` staging-only rows,
`38` staging-only linked rows, `48` staging-only unlinked rows, and zero EAN
conflicts. These are review aggregates only.

CREATE being enabled does not authorize creation of the `17` source-only
records. No staging-only record is approved for deletion, and no linked
staging-only record is approved for unlinking. Absence from the source is not
an availability or lifecycle decision.

The observed stock values remain unresolved and do not authorize quantity or
availability mapping. UPDATE remains disabled, Sync All remains disabled, and
automatic sync remains disabled. The closeout is documented in
[APCOM Reconciliation Review and Operational Closeout](APCOM_RECONCILIATION_REVIEW_CLOSEOUT.md).

## Phase 9C.6.5C.3B APCOM Human Decision And Preview-only Profile Design

`suppliers:design-preview-feed-profile` is a local, read-only, non-persistent
reporting command. It reuses the existing protected-state reconciler directly,
requires its frozen supplier/import/schedule/source baseline checks, and adds a
validated human decision register. It has no apply, persist, import, sync,
network, queue, image, schedule, or destructive source-absence control.

The profile can only emit aggregate preview classes and bounded hashes. It
cannot write suppliers, `supplier_products`, products, categories, mappings,
families, attributes, import history, or Catalog Sync tables. It cannot create
an executable feed configuration or approve source-only CREATE, UPDATE,
DELETE, LINK, UNLINK, lifecycle, stock, availability, or commercial actions.
UPDATE remains disabled, Sync All remains disabled, automatic sync remains
disabled, and image/content overwrite remain prohibited.

## APCOM C3B.1 Operational Preview Closeout

CREATE=true did not create the 17 source-only APCOM records. UPDATE remained
false, Sync All remained false, and automatic sync remained false. No Catalog
Sync action occurred and no batch or log was created by the preview. Source-
only and staging-only classes remain review classifications only; none
authorizes mutation. See APCOM_PREVIEW_ONLY_FEED_PROFILE_OPERATIONAL_CLOSEOUT.md.

## APCOM C3C Authoritative Decisions

The C3C v2 availability and price semantics are immutable preview evidence,
not Catalog Sync integration. `apcom-availability-policy-v1` has no catalog
quantity, price, lifecycle, workflow, content, or availability write path.
Its profile approval gate is blocked, and it does not authorize import,
schedule enablement, profile persistence, CREATE, UPDATE, Sync All, or
automatic sync. Exact supplier quantity remains hidden publicly; images and
content overwrite remain prohibited.

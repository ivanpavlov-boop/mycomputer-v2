# Product Attributes

## Purpose

Product Attributes define internal, structured catalog characteristics such as RAM, SSD capacity, processor, screen size, warranty, operating system, GPU, refresh rate, cable length and compatibility.

This foundation is for catalog-owned product specifications. It does not enable supplier XML attribute sync, frontend attribute filters, Sync All, automatic sync, image import, or supplier-driven product mutations.

See [Project AI Agents](AI_AGENTS.md) for the Product Attributes Architecture
Agent role. That role protects the model where `product_attributes` are global
internal definitions, `category_product_attributes` are category specification
templates, and `product_attribute_values` are product-specific values.

## Current Phase 9C.4.1 Category-Driven Product Specifications Editor

Phase 9C.4.1 keeps the manual product attribute workflow, but makes the Product edit `Характеристики` area category-driven first.

When a product has a primary category with rows in `category_product_attributes`, those assigned attributes are shown as ready specification fields in the product characteristics editor. Admins fill the values that are known. Empty category fields are allowed and do not create `product_attribute_values` rows.

Saving category specifications is explicit. A filled field creates or updates exactly one `product_attribute_values` row for the current product and attribute. Clearing a previously filled category field removes only that one product-specific value row. It does not delete the product, category, attribute definition, controlled option, supplier staging row, or any other product value.

Existing product values remain visible even if their attribute is no longer assigned to the product category. Admins may still add extra/manual characteristics outside the category assignment set.

The category assignment `is_required` flag is a visual data-quality hint only. It does not block saving, publishing, sync preview, or storefront visibility in this phase.

Phase 9C.4.1 still does not parse supplier XML attributes, does not sync supplier attributes, does not auto-fill product values, does not mutate `supplier_products`, and does not expose frontend attribute filters.

## Phase 9C.4.5 Admin UX Verification

Phase 9C.4.5 verifies and lightly polishes the Product edit `Characteristics`
admin workflow for category-driven specifications.

Manual verification checklist:

- Open an existing product with a category that has `category_product_attributes`.
- Confirm category-defined specifications appear as ready-to-fill rows/fields.
- Confirm parent category assignments are included when a product category has a
  parent, and duplicate attributes are shown once with the more specific child
  category assignment taking precedence.
- Leave several category specification fields empty and save. Empty fields must
  not create `product_attribute_values` rows.
- Fill one category specification field and save. Exactly one row should be
  created or updated for that product and product attribute.
- Clear an existing category specification field and save. Only that one
  product-specific value row should be removed.
- Confirm existing out-of-category values remain visible as extra/manual values
  and are not deleted by category specification saves.
- Confirm products without category templates still allow manual extra
  characteristics from the active internal attribute picker.
- Confirm required/important category assignments are visual data-quality hints
  only and do not block saving.

This phase remains admin-only and read-only until an admin explicitly saves
product-specific specification values. It does not parse supplier XML
attributes, does not sync supplier attributes, does not mutate
`supplier_products`, does not expose frontend filters, does not add Sync All,
and does not enable automatic sync.

## Phase 9C.5 Product Specification Data Quality

Phase 9C.5 adds a read-only Product Specification Data Quality layer for
category-driven product specifications.

The quality checker uses existing `category_product_attributes` rows as the
source of expected product characteristics. Category assignments marked
`is_required` are treated as important first. If a category has no required
assignments, visible, filterable, or comparable category assignments are treated
as recommended important fields. Child category assignments are evaluated before
parent category assignments, and duplicate product attributes are shown once
using the more specific assignment.

Quality statuses are computed dynamically and are not stored on products:

- `good` / `Добро`
- `needs_data` / `Нуждае се от попълване`
- `missing_required` / `Липсват важни характеристики`
- `no_category_template` / `Няма зададен шаблон за категория`

The score is computed dynamically as filled important fields over total expected
important fields, for example `2/4 (50%)`.

Filled values are type-aware:

- text values need non-empty text
- number and decimal values need a numeric value
- boolean values count as filled when true or false is explicitly present
- select values must reference an existing option for the same attribute
- multiselect values must contain at least one valid option for the same
  attribute
- JSON values must contain non-empty JSON data

Empty strings, nulls, empty arrays, and invalid option references count as
missing. Existing manually entered product values are preserved; the checker
does not rewrite or normalize historical rows.

Admin visibility is warning-only:

- the Products table shows a `Характеристики` quality badge
- the Product edit `Характеристики` area shows a compact
  `Качество на характеристиките` summary with expected, filled, and missing
  counts
- missing important attributes are shown as warnings and do not block saving
- admins still fill values manually through Product edit -> `Характеристики`

The optional read-only audit command:

```bash
php artisan products:audit-specification-quality
```

prints total products checked, products with missing important specs, products
without category templates, top missing attributes, and explicit `changed: 0`
lines for products, `supplier_products`, `product_attribute_values`, and
`category_product_attributes`.

This phase does not auto-fill `product_attribute_values`, does not create
attributes or options, does not create category assignments, does not mutate
products or `supplier_products`, does not parse supplier XML attributes, does
not sync supplier attributes, does not expose frontend filters, does not add
Sync All, and does not enable automatic sync.

## Phase 9C.5.1 Legacy Product Attribute Value Reconciliation

Phase 9C.5.1 adds a dry-run-first maintenance command for legacy
`product_attribute_values` that sit outside the current category-driven
specification template.

The command is:

```bash
php artisan product-attributes:reconcile-legacy-values
```

Default behavior is read-only. A dry run scans products, finds legacy
out-of-category values, and reports copy proposals into existing category
assigned target attributes. It prints scanned products, legacy values found,
proposal counts, target-already-filled counts, manual-review counts, and
explicit `changed: 0` safety lines for products, `supplier_products`,
`category_product_attributes`, `product_attributes`, and `attribute_values`.

Apply mode is intentionally restricted:

```bash
php artisan product-attributes:reconcile-legacy-values --apply --sku=SKU
php artisan product-attributes:reconcile-legacy-values --apply --product-id=ID
```

Unrestricted `--apply` is refused. Apply mode must name exactly one product by
SKU or product ID. There is no bulk apply across all products in this phase.

Optional discovery filters are available for dry runs:

- `--sku=SKU`
- `--product-id=ID`
- `--limit=COUNT` capped by the command
- `--only-missing-quality`
- `--category=category-slug`
- `--attribute=legacy-attribute-code-or-name`

The reconciliation is copy-safe:

- it creates a new target `product_attribute_values` row only when the target
  attribute already exists and is assigned to the product category
- it preserves the legacy source row
- it does not delete, overwrite, deactivate, or mutate legacy source values
- it skips when the target already has a value and reports
  `target_already_filled`
- it skips ambiguous or unparseable values and reports manual review actions
- it skips missing target attributes and missing select/multiselect options
- it never creates `product_attributes`, `attribute_values`, or
  `category_product_attributes`

The first reconciliation rules cover common legacy labels:

- storage / pamet values such as `512 GB SSD`, `1 TB SSD`, `256GB NVMe`, and
  `2 TB HDD` can propose `storage_capacity` and `storage_type`
- display / screen values can propose `screen_size`
- RAM / memory can propose `ram`
- processor / CPU can propose `processor`
- GPU / graphics / video can propose `gpu`
- resolution can propose `resolution`
- refresh rate / `refresh-rate` / Hz values can propose `refresh_rate`, with
  code-first then slug fallback for existing slug/code mismatches
- operating system / OS can propose `operating_system`
- color, warranty, weight, power, interface, and connectors can propose their
  corresponding existing target attributes

For text targets the command copies text. For number/decimal targets it writes
only confidently parsed numbers. For select and multiselect targets it reuses
existing `attribute_values` only; missing options are reported and skipped.

This phase does not parse supplier XML attributes, does not sync supplier
attributes, does not expose frontend filters, does not mutate products or
`supplier_products`, does not add Sync All, and does not enable automatic sync.

## Phase 9C.5.2 Reconciled Legacy Values Visibility Cleanup

Phase 9C.5.2 improves Product edit -> `Характеристики` visibility after legacy
values have been copied safely into category-driven specifications.

Legacy values are still preserved. They are not deleted, soft-deleted, hidden
completely, overwritten, or automatically cleaned up. The admin table now keeps
category-driven values visually primary and marks old/out-of-category values
with clearer Bulgarian badges:

- `Категорийна` for active category-template values
- `Допълнителна` for values outside the current category template
- `Вече прехвърлена` when every safe reconciliation target already has a filled
  product value
- `Частично прехвърлена` when only some safe targets are filled
- `Нуждае се от преглед` when the legacy value is ambiguous, unknown, missing a
  target, or otherwise not fully reconciled

Detection is read-only. A legacy/out-of-category value is marked as already
reconciled only when the Phase 9C.5.1 reconciliation service can generate
non-ambiguous target proposals and all target attributes already have filled
values for the same product according to the existing specification quality
filled-value rules. Partial and ambiguous values remain visible for manual
review.

Admins should treat old values as original/audit reference data. Do not delete
legacy rows just because they are marked `Вече прехвърлена`; a future controlled
cleanup phase would need its own design, audit trail, tests, and explicit
approval.

This phase is UI/read-only classification only. It does not run
`product-attributes:reconcile-legacy-values`, does not create
`product_attribute_values`, does not create attributes, options, category
assignments, categories, products, or supplier staging rows, does not parse
supplier XML attributes, does not sync supplier attributes, does not expose
frontend filters, does not add Sync All, and does not enable automatic sync.

## Phase 9C.5.3 CPU Category Attribute Template

Phase 9C.5.3 adds a controlled CPU / Processor category specification template
for existing CPU categories. The template is supplier-independent catalog
structure. It prepares the internal attribute model for later product-by-product
legacy reconciliation, but it does not reconcile values itself.

The command is:

```bash
php artisan product-attributes:seed-cpu-template
```

Default behavior is a dry run. It reports CPU attributes, safe options, and
category assignments that would be created, plus explicit safety counters for
products, `supplier_products`, `product_attribute_values`, categories, and
product category assignments.

Apply mode is explicit:

```bash
php artisan product-attributes:seed-cpu-template --apply
```

Apply mode creates only missing internal CPU `product_attributes`, a small safe
set of controlled options, and missing `category_product_attributes` for
existing CPU categories. It does not create categories and it does not alter
category names, slugs, descriptions, SEO, images, or product category
assignments. Existing admin-edited attribute labels, descriptions, flags, and
assignments are preserved.

CPU category aliases are matched by existing category slug only:

- `procesori`
- `processors`
- `processor`
- `cpu`
- `cpus`
- `protsesori`
- `central-processors`
- `desktop-processors`

If no matching CPU category exists, category assignments are skipped and the
command reports that clearly. Attributes/options may still be prepared by
explicit apply, but no categories are created.

The CPU template includes:

- `processor`
- `cpu_socket`
- `cpu_cores`
- `cpu_threads`
- `cpu_base_clock`
- `cpu_boost_clock`
- `cpu_tdp`
- `cpu_cache`
- `cpu_architecture`
- `cpu_integrated_graphics`
- `cpu_memory_support`
- `warranty_months`

The only controlled options created in this phase are intentionally small:

- `cpu_socket`: `AM4`, `AM5`, `LGA1700`, `LGA1851`
- `cpu_memory_support`: `DDR4`, `DDR5`

The legacy reconciliation proposal logic can now recognize CPU source labels
such as `Socket`, `Cores`, `Base clock`, `Boost clock`, `TDP`, and related CPU
fields once the target attributes are present and assigned. That remains a
dry-run-first, explicit product-by-product reconciliation workflow. This phase
does not run `product-attributes:reconcile-legacy-values --apply`, does not
create `product_attribute_values`, and does not auto-fill CPU product specs.

This phase does not parse supplier XML attributes, does not sync supplier
attributes, does not mutate products or `supplier_products`, does not expose
frontend filters, does not add Sync All, and does not enable automatic sync.

## Phase 9C.5.4 Category Specification Template Coverage Plan

Phase 9C.5.4 adds a read-only planning audit for category specification
template coverage. It helps identify which categories already have direct
`category_product_attributes`, which categories inherit a parent template, and
which product categories still have no effective specification template.

Run the audit:

```bash
php artisan product-attributes:audit-category-template-coverage
php artisan product-attributes:audit-category-template-coverage --only-missing
php artisan product-attributes:audit-category-template-coverage --limit=100
php artisan product-attributes:audit-category-template-coverage --format=json
```

The command reports:

- category id, name, slug and parent category
- product count for each category
- direct category assignment count
- inherited parent assignment count
- total effective expected attributes count
- coverage status: `direct_template`, `inherited_template`, or `no_template`
- conservative suggested product family
- suggested next action for planning future templates

`direct_template` means the category has its own active category-to-attribute
assignments. `inherited_template` means the category has no direct template but
inherits active assignments from a parent category. `no_template` means the
category has products but no direct or inherited active category specification
template.

This audit is planning-only. It has no `--apply` mode and does not create or
change `product_attributes`, `attribute_values`, `category_product_attributes`,
`product_attribute_values`, categories, products, supplier staging rows, or
product category assignments. It does not parse supplier XML attributes, sync
supplier attributes, expose frontend filters, or add Catalog Sync write paths.

The suggested families are conservative keyword/slug hints only. Unknown
categories remain `unknown` and require manual classification before a template
is created. The output is intended to guide future product-family template
phases such as RAM/memory, storage/SSD/HDD, and GPU/video cards.

## Phase 9C.5.5 Internal Taxonomy And Supplier Category Mapping Foundation

Phase 9C.5.5 adds an internal taxonomy foundation so future specification
templates are based on COMPUTER2U canonical product families instead of one
supplier's category names.

The intended long-term flow is:

```text
supplier category
-> supplier category mapping
-> internal/canonical product family or category
-> internal product specification template
```

This prevents category templates from being tied to APCOM or any other single
supplier feed. Different suppliers may use different category names and
structures for the same product family, so supplier categories must first be
reviewed and mapped into internal taxonomy records.

New tables:

- `canonical_product_families`: internal product-family buckets such as
  `computers_laptops`, `components`, `peripherals`, `cables_adapters`,
  `cases_protection`, `apple_devices`, `storage`, and `unknown`.
- `supplier_category_mappings`: supplier category candidates and reviewed
  mappings into a canonical product family and, optionally, a future target
  catalog category.

Mapping statuses:

- `pending_review`: default for discovered candidates; requires manual review.
- `approved`: reviewed and approved for future controlled phases.
- `rejected`: reviewed and rejected as an incorrect candidate.
- `ignored`: intentionally ignored.

Commands:

```bash
php artisan taxonomy:seed-canonical-families
php artisan taxonomy:seed-canonical-families --apply
php artisan supplier-categories:audit --limit=50
php artisan supplier-categories:audit --only-unmapped --limit=50
php artisan supplier-categories:audit --format=json --limit=10
php artisan supplier-categories:discover-mappings --limit=50
php artisan supplier-categories:discover-mappings --only-unmapped --limit=50
php artisan supplier-categories:discover-mappings --format=json --limit=10
php artisan supplier-categories:discover-mappings --apply --limit=50
```

Safety rules:

- `taxonomy:seed-canonical-families` is dry-run by default and may create or
  update only `canonical_product_families` when `--apply` is explicitly used.
- `supplier-categories:audit` is read-only and has no `--apply` option.
- `supplier-categories:discover-mappings` is dry-run by default and may create
  only `supplier_category_mappings` records with `pending_review` status when
  `--apply` is explicitly used.
- Mapping candidates are never auto-approved.
- Supplier category mappings do not apply to products.
- Supplier category mappings do not create catalog categories.
- Supplier category mappings do not move products or update
  `products.category_id`.
- Supplier category mappings do not change category names, slugs, hierarchy,
  SEO, descriptions, images, product attributes, category assignments, or
  product attribute values.

The Filament admin resources for canonical families and supplier category
mappings are visibility and maintenance tools for the new taxonomy tables only.
Super Admin can create/edit/delete the new records; Viewer/Auditor is
read-only. There is no bulk apply-to-products action.

## Phase 9C.5.6 Supplier Category Mapping Review Workflow

Phase 9C.5.6 makes the supplier category mapping queue easier to review in
Filament while keeping the workflow as planning metadata only.

Review actions:

- `approve`: marks a mapping as reviewed and approved only when a known
  canonical product family is selected. A future target category is optional and
  is not required for approval.
- `reject`: marks an incorrect mapping as rejected and may store a review note.
- `ignore`: marks a supplier category as intentionally ignored and may store a
  review note.
- `reset_pending`: returns a reviewed mapping to `pending_review`.

The review table now includes supplier/category identity, staging product count,
canonical family, optional future target category, status/confidence badges,
match reason, notes indicator, reviewer and reviewed timestamp. Filters cover
status, supplier, canonical family, confidence, no family, `unknown` family,
missing future category, and approved mappings without a future category.

Safety rules:

- Review actions update only `supplier_category_mappings`.
- Approval does not create catalog categories.
- Approval does not move products or update `products.category_id`.
- Approval does not update category hierarchy, SEO, descriptions, images,
  category attribute assignments, product attributes, attribute values, product
  attribute values, `supplier_products`, or Catalog Sync state.
- The optional `target_category_id` is a future-use reference only.
- Super Admin can mutate review records; Viewer/Auditor remains read-only.

## Phase 9C.6 Multi-Supplier Import Discovery Foundation

Phase 9C.5.8 manual supplier category review is intentionally paused after the
first APCOM review batch. Current production context has 6 approved supplier
category mappings and 67 `pending_review` mappings. The pause is deliberate:
internal category templates should be designed from the full supplier landscape,
not from one supplier category taxonomy alone.

Phase 9C.6 adds a read-only discovery command:

```bash
php artisan suppliers:audit-discovery
php artisan suppliers:audit-discovery --format=json --limit=50
php artisan suppliers:audit-discovery --show-categories --limit=50
php artisan suppliers:audit-discovery --show-identifiers --limit=50
php artisan suppliers:audit-discovery --show-overlaps --limit=50
```

The command reports existing suppliers, staged `supplier_products` counts,
identifier completeness, supplier category coverage, mapping status counts, and
possible cross-supplier overlaps. It is meant to prepare future batches for
manual supplier category mapping review after all planned suppliers are staged.

Safety rules:

- It has no `--apply` option.
- It does not run supplier imports.
- It does not call Catalog Sync.
- It does not create or update catalog products.
- It does not create or update `supplier_products`.
- It does not create, approve, reject, ignore, or apply
  `supplier_category_mappings`.
- It does not create categories, canonical product families, product
  attributes, attribute values, category assignments, or product attribute
  values.

Correct planning flow:

```text
supplier import
-> supplier_products staging only
-> read-only discovery/audit
-> mapping candidates
-> manual review
-> approved mapping records
-> future controlled preview phase
```

The future controlled preview/application phase is not implemented here.

## Phase 9C.6.1 Supplier Import Capability Audit

Phase 9C.6.1 adds a read-only supplier import capability audit command:

```bash
php artisan suppliers:audit-import-capabilities
php artisan suppliers:audit-import-capabilities --format=json
php artisan suppliers:audit-import-capabilities --supplier=apcom
php artisan suppliers:audit-import-capabilities --include-disabled --only-with-issues
php artisan suppliers:audit-import-capabilities --show-drivers --show-schedules --show-config --show-checklist
```

The command reports supplier feed readiness before the next supplier staging
phase. It shows supplier status, import/schedule state, feed type, supported
driver/parser, redacted feed host/URL, authentication presence markers, XML
mapping template presence, staged row counts, identifier completeness, latest
import run status, schedule readiness, and capability issues.

Secret handling is display-only and conservative: hosts may be shown, but
sensitive path segments and all query values are redacted. Authentication output
uses boolean presence markers only and must not print usernames, passwords,
tokens, API keys, bearer headers, or feed secrets.

Safety rules:

- It has no `--apply` option.
- It does not run supplier imports.
- It does not fetch remote feeds or call supplier APIs.
- It does not dispatch queue jobs.
- It does not call Catalog Sync.
- It does not mutate products, suppliers, `supplier_products`, categories,
  supplier category mappings, canonical families, product attributes, attribute
  values, category assignments, or product attribute values.
- It does not expose supplier feed secrets.

Phase 9C.6.1 does not stage another supplier. That remains a future
preview-only phase after capability gaps are reviewed.

## Phase 9C.6.2 Supplier Configuration Safety Cleanup

Phase 9C.6.2 adds a dry-run-first supplier schedule cleanup command:

```bash
php artisan suppliers:cleanup-unsafe-schedules
php artisan suppliers:cleanup-unsafe-schedules --format=json --only-unsafe
php artisan suppliers:cleanup-unsafe-schedules --apply --only-unsafe
```

The command exists because supplier category and attribute planning should wait
until all suppliers are safely staged and visible. Suppliers that are active,
import-enabled, scheduled, missing feed/driver configuration, and have no staged
rows should not continue to produce scheduled import noise while the next
supplier staging phase is still being prepared.

Dry-run mode reports affected suppliers and protected-table zero-change
counters. Explicit apply mode may only disable the unsafe supplier schedule flag.
It does not disable suppliers, delete feeds, change `import_enabled`, run
imports, fetch feeds, call supplier APIs, dispatch queue jobs, call Catalog
Sync, mutate products, mutate `supplier_products`, approve or apply supplier
category mappings, create category templates, or create product attribute
values.

The Phase 9C.5.8 mapping/template work remains intentionally paused after 6
approved mappings and 67 `pending_review` mappings until multi-supplier staging
coverage is safe enough to review broader supplier category and attribute
patterns.

## Phase 9C.6.3 Next Supplier Staging Import Preview

Phase 9C.6.3 adds a read-only next-supplier feed preview command:

```bash
php artisan suppliers:preview-staging-import
php artisan suppliers:preview-staging-import --fixture=tests/Fixtures/Suppliers/next_supplier_preview.xml --source-type=xml --format=json
php artisan suppliers:preview-staging-import --fixture=tests/Fixtures/Suppliers/next_supplier_preview.csv --source-type=csv --show-identifiers --show-categories --show-issues
php artisan suppliers:preview-staging-import --fixture=tests/Fixtures/Suppliers/next_supplier_preview.json --source-type=json --format=json
```

The command parses local XML, CSV, and JSON source samples only. It reports raw
field names, normalized field coverage, identifier coverage, category coverage,
price/stock coverage, duplicate/missing-data issues, safe overlap diagnostics
against existing `supplier_products`, and future staging action labels. Those
labels are planning output only; no staging row is created or updated.

Safety rules:

- It has no `--apply` option.
- It refuses HTTP and HTTPS sources.
- It does not fetch remote feeds or call supplier APIs.
- It does not run supplier imports or dispatch jobs.
- It does not call Catalog Sync.
- It does not mutate products, `supplier_products`, suppliers, categories,
  supplier category mappings, canonical families, product attributes, attribute
  values, product attribute values, or category attribute assignments.
- It does not expose supplier feed secrets, full image URLs, or long raw
  descriptions.
- It does not add admin UI, frontend filters, supplier image import, supplier
  XML attribute mapping, Sync All, automatic sync, or UPDATE enablement.

The Phase 9C.5.8 mapping/template work remains intentionally paused after 6
approved mappings and 67 `pending_review` mappings. Future category templates
and supplier attribute mapping should continue to wait for broader supplier
coverage and review.

The next planned supplier phase is a controlled one-supplier staging apply into
`supplier_products` only. It must be separately requested, preview-first, safe,
tested, and must not write catalog products directly.

## Phase 9C.4 Manual Product Attribute Values

Phase 9C.4 adds a manual Filament workflow for product-specific attribute values.

Admins with product-management permission can open a product in the Products resource and use the `Характеристики` relation manager to add, edit, view, or remove rows in `product_attribute_values` for that product.

The workflow is intentionally manual:

- it writes only the selected product's `product_attribute_values`
- it defaults `source` to `manual`
- it may mark manually entered values as verified
- it uses existing `product_attributes` and `attribute_values`
- it does not create attributes, options, categories, or category assignments
- it does not auto-fill existing products
- it does not parse or sync supplier XML attributes
- it does not mutate `supplier_products`
- it does not expose frontend filters

Attribute selection is guided by `category_product_attributes` where possible. Attributes assigned to the product's primary category are listed first and, in Phase 9C.4.1, are also available as ready category specification fields. If a product has no category assignment rules, admins can still choose from all active Product Attributes. The workflow never changes the product's category assignment.

Value validation is type-aware:

- `text` uses `value_text`
- `number` and `decimal` require a numeric `value_number`
- `boolean` uses `value_boolean`
- `select` requires an existing active `attribute_values` row that belongs to the selected attribute
- `multiselect` stores selected option IDs in `value_json` on one row for product + attribute
- `json` requires valid JSON

The admin workflow prevents duplicate product + attribute rows unless a later explicit design allows multiple rows. Deleting a row removes only the product-specific value; it does not delete the product, attribute, option, category, or supplier staging data.

## Phase 9C.3 Category Attribute Sets

Phase 9C.3 adds controlled Category Attribute Sets. These sets assign existing internal product attributes to existing categories through an explicit dry-run/apply command.

Category Attribute Sets are preparation data for future product data quality, manual product specifications, controlled supplier attribute mapping, and later frontend filters. They do not populate product values and they do not make storefront filters visible.

Preview category assignments without writing anything:

```bash
php artisan product-attributes:assign-category-sets
php artisan product-attributes:assign-category-sets --set=laptops
php artisan product-attributes:assign-category-sets --category=iphone
php artisan product-attributes:assign-category-sets --list
```

Apply missing assignments manually:

```bash
php artisan product-attributes:assign-category-sets --apply
```

The command is idempotent and safe to run repeatedly. It creates only missing `category_product_attributes` rows for existing categories and existing product attributes. It does not delete assignments and does not overwrite existing admin-edited assignment flags.

Category matching uses existing category slugs and a small alias list. Missing categories are skipped and reported. Attribute matching uses code first and then slug, so legacy slug/code mismatches such as `refresh-rate` versus `refresh_rate` are reused safely. Missing attributes are skipped and reported.

Starter category sets include:

- `laptops`
- `monitors`
- `phones`
- `tablets`
- `keyboards`
- `mice`
- `cables`
- `printers`

The command does not:

- create categories
- rename categories
- overwrite category descriptions, SEO, images or slugs
- change product category assignments
- mutate products
- mutate `supplier_products`
- create `product_attribute_values`
- create `product_attributes`
- create `attribute_values`
- parse or sync supplier XML attributes
- expose frontend attribute filters

## Phase 9C.2 Admin Usability And Starter Structure

Phase 9C.2 improves the Filament admin experience for the internal attribute library and adds a safe starter structure command.

Admin areas:

- `Характеристики`: internal attribute definitions such as RAM, storage capacity, screen size, warranty and compatibility.
- `Опции на характеристики`: controlled options for `select` and `multiselect` attributes.
- `Категорийни характеристики`: category-to-attribute assignment rules.

The admin UI is intentionally manual and controlled. Attribute definitions, options and category assignments do not automatically create product attribute values.

Useful admin conventions:

- `Код` is a stable internal key such as `ram` or `storage_capacity`. It should use lowercase letters, numbers and `_`, and should not be changed after use.
- `Филтър` means the attribute may be used later in catalog filters.
- `Видима` means the attribute may be shown later on product pages.
- `Сравнима` means the attribute may be used later in product comparison.
- `Задължителна` is a category/quality hint and does not block products in this phase.

### Starter Command

Preview the starter attribute library without writing anything:

```bash
php artisan product-attributes:seed-starter
php artisan product-attributes:seed-starter --dry-run
```

Apply the starter attribute library manually:

```bash
php artisan product-attributes:seed-starter --apply
```

The command is idempotent and safe to run repeatedly. It creates missing internal attribute definitions and missing controlled options only. It does not delete records and does not overwrite existing admin-edited labels.

If an existing legacy/admin-created attribute has the same starter slug but a different code, the command reuses the existing attribute and reports the code mismatch instead of inserting a duplicate. Existing codes, labels and descriptions are preserved; only clearly missing technical metadata may be filled during `--apply`.

Starter attributes include:

- `ram`
- `storage_capacity`
- `storage_type`
- `processor`
- `gpu`
- `screen_size`
- `resolution`
- `refresh_rate`
- `panel_type`
- `color`
- `operating_system`
- `warranty_months`
- `interface`
- `connectors`
- `cable_length`
- `compatibility`
- `power_watts`
- `weight`

Starter options are included where useful, such as RAM sizes, storage type/capacity, screen size, refresh rate, panel type, color and operating system.

Category assignments are handled separately by `product-attributes:assign-category-sets`. The starter attribute command still does not create category assignments, create categories, rename categories, overwrite categories, mutate product category assignments, or populate product attribute values automatically.

Safety guarantees:

- existing products are not mutated
- `supplier_products` are not mutated
- `product_attribute_values` are not auto-filled
- categories are not created or renamed
- category content, SEO and images are not overwritten
- supplier XML attributes are not parsed or synced
- frontend attribute filters are not exposed
- Sync All is not added
- automatic sync is not enabled
- supplier image import is not added

## Phase 9C.1 Foundation

Phase 9C.1 keeps the existing compatibility tables and extends them with a clearer internal product attribute foundation.

Core tables:

- `product_attributes`: internal attribute definitions.
- `attribute_values`: controlled options for `select` and `multiselect` attributes.
- `category_product_attributes`: category-to-attribute assignment rules.
- `product_attribute_values`: product-specific values for manual or future controlled assignments.

Existing normalization tables remain separate:

- `canonical_attributes`
- `canonical_attribute_values`
- `supplier_product_attributes`
- `attribute_mapping_logs`
- `category_attribute_templates`

Those tables support supplier/raw normalization workflows, but supplier attribute writes are not expanded in this phase.

## Attribute Definitions

`product_attributes` stores the internal definition:

- `code`: stable internal key, for example `ram`, `ssd_capacity`, `screen_size`.
- `name_bg`: Bulgarian label and primary admin language.
- `name_en`: optional English label.
- `description_bg` and `description_en`: optional admin descriptions.
- `type`: one of `text`, `number`, `boolean`, `select`, `multiselect`, `decimal`, `json`.
- `unit`: optional display unit such as `GB`, `TB`, `inch`, `Hz`, `W`.
- `is_filterable`: marks future filter eligibility, but does not expose storefront filters yet.
- `is_visible_on_product`: marks future public product-page display eligibility.
- `is_comparable`: marks future comparison eligibility.
- `is_required_by_default`: default requirement hint.
- `is_active`: active admin definition flag.

`attribute_values` is the existing controlled-option table for select/multiselect values. It is intentionally reused instead of adding a duplicate `product_attribute_options` table.

## Category Assignments

`category_product_attributes` defines which internal attributes belong to a category.

Each row stores:

- category
- product attribute
- required flag
- future filterable flag
- visible-on-product flag
- comparable flag
- sort order

The assignment is an internal admin rule. It does not mutate existing products and does not create product attribute values automatically.

## Product Attribute Values

`product_attribute_values` stores actual catalog product values.

The table keeps existing compatibility columns:

- `attribute_value_id`
- `custom_value`
- canonical attribute links where present

Phase 9C.1 adds typed storage columns for future controlled workflows:

- `value_text`
- `value_number`
- `value_boolean`
- `value_json`
- `unit`
- `source`
- `is_verified`
- `sort_order`

No existing products are backfilled in this phase. Product values must be added manually or through a future explicitly approved controlled workflow.

## Admin

Filament exposes Bulgarian admin management for:

- Product Attributes
- Attribute Values
- Category Product Attributes
- manual Product Attribute Values from the Product edit page `Характеристики` relation manager

These screens are protected by existing product-management authorization. Super Admin retains access through the existing bypass. Viewer/Auditor roles are not given mutation permissions.

## Supplier Safety

The correct future supplier flow remains:

```text
Supplier XML
-> supplier_products staging
-> raw supplier attributes / supplier metadata
-> mapping to internal product attributes
-> preview
-> manual/controlled approval
-> catalog product attributes
```

Phase 9C.1 does not implement that full supplier flow.

Forbidden in this phase:

- parsing new supplier XML attributes
- importing supplier attributes into catalog products
- automatic supplier attribute updates
- supplier category/attribute overwrite
- supplier image import
- frontend attribute filters
- Sync All
- automatic sync
- enabling UPDATE sync

Price, stock and availability updates remain separate from content and attribute management. Supplier import continues to write only to staging unless a future phase explicitly designs and tests controlled attribute sync.

## Future Phases

Planned follow-up work:

1. Internal Category Template Assignment Plan.
2. Power/Cables Template Based on Internal Taxonomy.
3. Cases/Protection Template Based on Internal Taxonomy.
4. Peripherals Template Based on Internal Taxonomy.
5. Supplier Attribute Mapping Foundation.
6. Product Specification Data Quality polish.
7. Storefront product specification display.
8. Frontend attribute filters and facets.

Each future write phase must include server-side validation, preview, auditability and tests proving products and `supplier_products` are not mutated unexpectedly.

Future supplier XML mapping remains preview-only until a dedicated phase
explicitly approves writes from raw supplier attributes into internal product
attribute values.

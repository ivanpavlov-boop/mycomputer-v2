# APCOM Deterministic Audit Closeout

## Status

Phase 9C.6.5C.2 is completed by this documentation closeout.

- APCOM is Supplier #1 and remains the historically integrated supplier.
- ASBIS is Supplier #2 and its controlled staging verification is complete.
- Supplier #3 remains unselected.
- The APCOM schedule is disabled.
- APCOM `import_enabled` remains `true`.
- No automatic unfreeze exists.
- No decision to re-enable the APCOM schedule has been approved.

The closeout records supplied production evidence. It does not authorize a
new import, feed fetch, link repair, Catalog Sync operation, or schedule
change.

## Purpose

APCOM was historically integrated before the reusable supplier onboarding
framework existed. Its schedule had to be frozen because scheduled staging
imports could change `supplier_products` while the audit was running. The
freeze established deterministic audit stability; it was not an approval of
the legacy APCOM integration for future automatic operation.

## Evidence

The following runtime reports are operational evidence supplied for this
closeout:

- `storage/app/imports/apcom-audit/reports/apcom_legacy_audit_pre_freeze_20260713T082947Z.json`
- `storage/app/imports/apcom-audit/reports/apcom_schedule_freeze_dry_run_20260713T142012Z.json`
- `storage/app/imports/apcom-audit/reports/apcom_schedule_freeze_apply_20260713T142523Z.json`
- `storage/app/imports/apcom-audit/reports/apcom_schedule_freeze_postcheck_20260713T142523Z.json`
- `storage/app/imports/apcom-audit/reports/apcom_legacy_audit_post_freeze_20260714T052718Z.json`
- `storage/app/imports/apcom-audit/reports/apcom_legacy_audit_post_freeze_latest.json`

These reports are runtime evidence and are not committed to Git. No XML,
raw supplier data, credentials, or feed URLs are included in the repository.
Report SHA-256 fingerprints are intentionally not recorded because none were
supplied for this closeout.

## Controlled Freeze Result

The controlled operation targeted exactly one supplier:

- supplier id: `5`
- supplier key: `apcom`
- supplier name: `APCOM`
- status: `active`
- `import_enabled` before and after: `true`
- `schedule_type`: `twice_daily` before and after
- schedule before: `schedule_enabled=true`
- schedule after: `schedule_enabled=false`
- staged rows: `1872` before and after
- linked rows: `989` before and after
- unlinked rows: `883` before and after
- `last_import_at`: `2026-07-13T04:00:56.000000Z` before and after

The apply committed one supplier row change. The only semantic change was
`suppliers.schedule_enabled: true -> false`. All other `records_changed`
counters were zero, including `supplier_products`, `products`, categories,
mappings, attributes, and Catalog Sync batches/logs.

The scheduler was stopped externally before the guarded operation and
restarted afterward. No import was run and no Catalog Sync was run. The
post-freeze check confirmed `schedule_enabled=false` and refused another
apply because the schedule was already disabled.

## Deterministic Audit Result

The final legacy audit was read-only and completed successfully:

- `read_only=true`
- `FINAL_AUDIT_EXIT=0`
- `COMPARE_EXIT=0`
- comparison: `APCOM_DETERMINISTIC_AUDIT_COMPARISON_PASSED`
- verdict: `legacy_state_requires_review`
- blockers: none
- warnings: `staging_present_without_verification`, `historical_causation_unknown`
- audit `records_changed`: all zero
- `schedule_enabled=false`
- `schedule_must_be_frozen=false`
- schedule blocker: false
- schedule can change supplier products during audit: false
- required recommendation: null

The deterministic comparison matched exactly before and after the freeze for
supplier id `5`, supplier key `apcom`, staging total `1872`, linked rows
`989`, unlinked rows `883`, and `last_import_at`.

## Legacy Staging Inventory

Safe aggregate findings from the final audit:

- total rows: `1872`
- linked: `989`
- unlinked: `883`
- status `new`: `1795`
- status `skipped`: `45`
- status `synced`: `32`
- `synced_at` present: `77`
- EUR rows: `1872`
- positive prices: `1800`
- zero prices: `72`
- positive quantities: `860`
- zero quantities: `1012`
- rows with `raw_data`: `1872`
- rows with `payload_hash`: `1872`

No raw supplier product records or identifiers are included here.

## Identifier Findings

The audit found:

- no supplier SKU duplicate groups
- no EAN duplicate groups
- no MPN duplicate groups
- no case-normalized duplicate groups
- no whitespace-normalized duplicate groups
- no brand-plus-MPN duplicate groups
- no invalid EAN formats detected
- `112` records without EAN
- all records have an MPN or supplier SKU
- maximum EAN length: `13`
- maximum MPN/SKU length: `31`

Missing EAN values are an inventory finding, not an instruction for automatic
correction.

## Linked Catalog Findings

- `989` linked staging rows
- `989` distinct linked catalog products
- no orphan `product_id` references
- no links to soft-deleted products
- no multiple APCOM staging rows linked to one catalog product
- no linked catalog product with multiple supplier offers in this dataset
- `986` linked products active
- `3` linked products draft
- `986` workflow statuses published
- `3` workflow statuses pending review
- `2` linked staging rows with unexpected status `skipped`
- `34` linked rows with `synced_at` populated

These records were not unlinked or automatically modified.

## Catalog Comparison Interpretation

- exact or normalized name equality: `989`
- exact or normalized brand equality: `989`
- matching EAN: `989`
- matching MPN: `989`
- comparable price differences: `983`
- comparable price equality: `6`

Equality is diagnostic only. It does not prove supplier content overwrite or
prove that no overwrite occurred. Historical causation remains unknown, so
the audit does not establish which historical process created or changed
catalog content.

Supplier data must not overwrite catalog names, slugs, SEO, descriptions,
images, categories, or attributes without a separate reviewed phase.

## Mapping State

- supplier category mappings: `72`
- approved: `6`
- pending review: `66`
- rejected: `0`
- unmapped distinct supplier categories: `0`

Pending mappings were not approved by this phase. No mapping was created,
approved, rejected, or changed. No category or attribute overwrite is
authorized.

## Historical Import Evidence

The following are historical counters from the legacy integration, not
mutations performed by the closeout audit:

- import history event count: `185`
- last known failed run: `2026-07-12 04:00:24` to `2026-07-12 04:00:25`
- last known successful or completed-with-warnings run: `2026-07-11 17:00:51` to `2026-07-11 17:02:33`
- historical `products_seen` total: `91275`
- historical `products_created` total: `903`
- historical `products_updated` total: `28574`
- historical `products_skipped` total: `60000`
- historical `products_failed`: `0`

These counters do not prove exactly which fields were historically changed.
The `historical_causation_unknown` warning remains. No new import was run
during the deterministic audit. Any recent-30-day run count is a moving
window and must not be treated as evidence of a production mutation without
additional evidence.

## Remaining Warnings

### `staging_present_without_verification`

Staging records exist, but an approved APCOM feed profile has not verified the
current staging interpretation. This does not authorize re-import or
automatic sync.

### `historical_causation_unknown`

The system cannot prove the exact historical cause of current catalog equality
or linkage. No destructive cleanup should be performed. Existing product
links remain unchanged unless a separate reviewed migration is approved.

## Configuration Discrepancy Requiring Follow-up

The readiness/capability audit previously reported APCOM source/auth
configuration differently from the deterministic legacy audit. The
deterministic audit reported:

- `source_configured=true`
- `source_format=xml`
- `driver=XmlImportEngine`
- `authentication_required=false`
- `authentication_configured=false`

No credential or feed configuration was changed. No credential value was
inspected or exposed. This discrepancy requires a future code-level
interpretation review and must not trigger an automatic configuration change.

## Current Safety State

- APCOM `schedule_enabled=false`
- APCOM `import_enabled=true`
- the scheduler container may run, but APCOM scheduled import remains disabled
- no manual APCOM import is approved
- no automatic schedule re-enable exists
- Catalog Sync UPDATE remains disabled
- Sync All remains disabled
- automatic sync remains disabled
- no image import is approved

## Explicit Prohibitions

- Do not re-enable the APCOM schedule.
- Do not run APCOM import.
- Do not fetch the production APCOM feed as part of closeout.
- Do not link or unlink products.
- Do not approve mappings automatically.
- Do not run Catalog Sync.
- Do not enable UPDATE sync.
- Do not add Sync All.
- Do not enable automatic sync.
- Do not overwrite catalog content.
- Do not import supplier images.
- Do not delete legacy staging rows.
- Do not reset product links.

## Next Phase

Phase 9C.6.5C.3 - APCOM Local Source Profile and Normalization Plan is
implemented as `suppliers:plan-local-source-normalization`. An explicitly
authorized local C.3 profile has since run without writes or persisted
configuration. Its safe aggregate findings are documented in
[APCOM Official Field Semantics And Read-only Reconciliation](APCOM_OFFICIAL_FIELD_SEMANTICS_RECONCILIATION.md);
the report and source remain outside Git.

It must not fetch the production APCOM feed automatically, run an import,
change `supplier_products`, modify product links or catalog products, approve
mappings, re-enable the schedule, run Catalog Sync, or import images.

Phase 9C.6.5C.3A tooling is implemented locally and in review. It has not run
an operational source-to-staging reconciliation and does not mark that
reconciliation as started or completed.

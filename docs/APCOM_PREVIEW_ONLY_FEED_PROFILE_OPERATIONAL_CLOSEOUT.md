# APCOM Preview-only Feed Profile Operational Closeout

## Status

Phase 9C.6.5C.3B tooling is merged and deployed. The operational preview
completed successfully and its strict report contract passed. This
documentation closes Phase 9C.6.5C.3B.1.

Human decisions remain pending. No import, feed-profile persistence, schedule
approval, or Catalog Sync approval exists.

## Purpose

This document closes operational preview evidence only. It does not approve
mappings, prices, stock semantics, availability semantics, import, profile
persistence, executable import configuration, schedule re-enable, Catalog Sync,
or any CREATE, UPDATE, DELETE, LINK, or UNLINK action.

The evidence below is operator-supplied. This closeout did not connect to the
VPS, production database, runtime report, or real APCOM XML.

## Evidence

- merge commit: 1fdfd9aefb26b9c569b2ecc3d2154cd101ec7b0e;
- source filename: apcom-product-list-2026-07-14-11-07.xml;
- source SHA-256: fef5e30eb5e16714014f3654fce34025a14a0fb22750bc01158163b2c14c9ac1;
- runtime report path: storage/app/imports/apcom-audit/reports/apcom_preview_feed_profile_20260715T104009Z.json;
- runtime report SHA-256: 8bac357d4cd215b932e4ecadddb19fddd30846886e30d3c124edef6f44c8d830;
- runtime stderr path: storage/app/imports/apcom-audit/reports/apcom_preview_feed_profile_20260715T104009Z.stderr.log;
- runtime stderr size: 0 bytes; and
- strict contract result: true, APCOM_PREVIEW_OPERATIONAL_CONTRACT_PASSED.

The source XML, runtime report, and stderr log remain outside Git. No raw
identifiers, bounded sample hashes, report payload, feed URL, credential, or
secret is copied into this repository.

## Contract Structure

The validated runtime report stores reconciliation evidence under the nested
source_to_staging_reconciliation object. Its explicit top-level safety keys
are:

- persisted_profile_created;
- executable_import_configuration_created;
- automatic_execution_allowed;
- profile_persistence_allowed;
- staging_write_allowed; and
- catalog_write_allowed.

The strict jq contract verification confirmed the expected schema and mode,
successful preview-only verdict, read_only=true, human_review_required=true,
valid decision register, no decision-register validation errors, correct
profile and semantics dependencies, matching source fingerprint and supplier
baseline, clear active-import state, all disabled Catalog Sync safety flags,
complete reconciliation aggregates, zero mutation counters, unchanged
protected fingerprints, no raw values emitted, and blocking decision IDs.

The contract passed as a safety and evidence check. It did not approve import
execution, profile persistence, schedule re-enable, or Catalog Sync.

## Operational Preview Result

- schema_version: supplier-preview-feed-profile-design-v1;
- mode: preview_feed_profile_design;
- success: true;
- verdict: preview_feed_profile_requires_human_decisions;
- preview_only: true;
- read_only: true;
- human_review_required: true; and
- blockers: 0.

Warnings were:

- blank_ean_requires_review;
- documented_greentax_absent_in_current_snapshot;
- eol_rows_require_human_review;
- exact_supplier_sku_reconciliation_requires_review;
- source_staging_count_delta_requires_review;
- stock_semantics_discrepancy_requires_review; and
- zero_price_candidate_requires_review.

These warnings are review gates. They do not authorize execution, approve the
feed profile, or authorize import.

## Supplier And Source Evidence

APCOM is Supplier #1 and remains the historically integrated supplier. ASBIS
is Supplier #2. Supplier #3 remains unselected.

- supplier ID: 5;
- supplier key: apcom;
- supplier name: APCOM;
- source format: xml;
- record path: xml.product;
- source fingerprint matches: true; and
- schedule: disabled.

The source filename and SHA-256 above are evidence labels only. No feed URL is
recorded here.

## Baseline And Active Import State

- expected_state_required: true;
- baseline matches: true;
- source/staging count delta: -69;
- delta percentage: -3.6859%; and
- schedule must remain disabled: true.

Operational supplier baseline:

- supplier ID: 5;
- import_enabled: true;
- schedule_enabled: false;
- schedule_type: twice_daily;
- staged rows: 1872;
- linked rows: 989;
- unlinked rows: 883; and
- last_import_at: 2026-07-13T04:00:56.000000Z.

The active-import check was clear: active supplier_import_runs was 0,
active import_jobs was 0, unknown state count was 0, and state was clear.

## Catalog Sync Safety State

- CATALOG_SYNC_CREATE_ENABLED: true;
- CATALOG_SYNC_UPDATE_ENABLED: false;
- CATALOG_SYNC_SYNC_ALL_ENABLED: false; and
- CATALOG_SYNC_AUTO_ENABLED: false.

CREATE being enabled did not authorize the 17 APCOM source-only rows to be
created. No Catalog Sync action was invoked and no Catalog Sync batch or log
was created by the preview.

## Decision Register

The decision register key was apcom-human-decisions-v1. It was valid,
read-only, non-persisted, and had no validation errors.

- decision count: 24;
- blocking decision count: 22;
- confirmed: 2;
- diagnostic-only: 1;
- review-only: 3;
- pending: 11; and
- prohibited: 7.

Confirmed decisions are that partno is authoritative only for source-to-staging
supplier identity and that pinned local XML plus SHA-256 is required source
evidence.

EAN is diagnostic-only. It cannot replace supplier SKU identity or create links
automatically.

Review-only groups are lifecycle/EOL rows, the EOL candidate population, and
zero-price rows.

Pending decisions are stock semantics, quantity, availability, MPN, selected
price, currency, VAT, Green Tax, source-only handling, staging-only handling,
and linked-staging-only handling.

Prohibited decisions are automatic import, schedule enablement, Sync All,
automatic Catalog Sync, UPDATE sync, supplier image import, and supplier
content overwrite.

The 22 blocking decisions prevent execution approval. They do not make the
read-only preview invalid, and no blocking decision was resolved by this run.
No CLI approval mechanism exists.

## Preview Profile

The preview profile key was apcom-preview-feed-profile-v1, dependent on
apcom-observed-stock-v1.

- persisted: false;
- executable: false;
- read-only: true;
- preview-only: true; and
- human review: required.

Field policy:

- supplier SKU: partno, reconciliation identity only;
- EAN: diagnostic-only;
- MPN: unresolved;
- product name: presence-only, overwrite prohibited;
- brand: presence-only, overwrite prohibited;
- supplier category: presence-only, mapping/category overwrite prohibited;
- stock: unresolved numeric observation;
- quantity: unresolved;
- availability: unresolved;
- EOL: review-only;
- price candidates: dac_price and fd_price;
- selected price: unresolved;
- currency: unresolved;
- VAT: unresolved;
- Green Tax: unresolved;
- images: presence-only, import prohibited; and
- CN code/group: presence-only metadata.

## Preview Action Matrix

Every action-matrix entry had automatic_execution_allowed=false,
catalog_write_allowed=false, staging_write_allowed=false, and
profile_persistence_allowed=false.

| Action | Classification | Result |
| --- | --- | --- |
| CREATE detection | source_only | Preview only; not authorized. |
| UPDATE comparison | exact_match | Aggregate comparison only; UPDATE disabled. |
| DELETE classification | staging_only | Deletion prohibited. |
| LINK diagnostic | matched_unlinked | Linking prohibited. |
| UNLINK classification | staging_only_linked | Unlinking prohibited. |
| Content overwrite | prohibited | Not allowed. |
| Image import | prohibited | Not allowed. |
| Schedule enablement | prohibited | Not allowed. |
| Automatic import | prohibited | Not allowed. |
| Sync All | prohibited | Not allowed. |
| Automatic sync | prohibited | Not allowed. |
| UPDATE sync | prohibited | Not allowed. |

## Source Inventory

Total source records: 1803.

Supplier SKU:

- non-blank: 1803;
- exact unique: 1803;
- blank: 0;
- duplicate groups: 0; and
- duplicate rows: 0.

EAN:

- non-blank: 1691; and
- blank: 112.

EOL:

- `eol=0` (non-EOL flag rows): 1700;
- `eol=1` (EOL review rows): 103; and
- invalid: 0.

## Staging Inventory

- total rows: 1872;
- linked: 989;
- unlinked: 883;
- blank supplier SKU: 0; and
- blank EAN: 112.

No staging row was inserted, updated, or deleted, and no product link changed.

## SKU Reconciliation

- source unique SKUs: 1803;
- staging unique SKUs: 1872;
- exact one-to-one matches: 1786;
- source-only: 17;
- staging-only: 86;
- matched linked: 951;
- matched unlinked: 835;
- staging-only linked: 38;
- staging-only unlinked: 48;
- one-source-to-many risk: 0;
- many-source-to-one risk: 0;
- source balance valid: true;
- staging balance valid: true; and
- automatic link/merge/repair: false.

The balances are:

- 1786 + 17 = 1803;
- 1786 + 86 = 1872; and
- 86 - 17 = 69.

The 17 source-only records are preview candidates only. The 86 staging-only
records are review candidates only. The 38 linked staging-only rows remain the
highest-priority manual-review group. No CREATE, DELETE, or UNLINK was
approved.

## EAN Diagnostics

- EAN equal on exact SKU matches: 1674;
- EAN blank in both: 112;
- EAN different: 0;
- source blank only: 0;
- staging blank only: 0; and
- cross-SKU EAN conflicts: 0.

EAN remains diagnostic-only. No automatic identity replacement or link is
approved.

## Candidate Classifications

| Classification | Count |
| --- | ---: |
| exact_match | 1786 |
| source_only | 17 |
| staging_only | 86 |
| matched_linked | 951 |
| matched_unlinked | 835 |
| staging_only_linked | 38 |
| staging_only_unlinked | 48 |
| eol_review | 103 |
| zero_price_review | 72 |
| blank_ean_review | 112 |
| ean_conflict_review | 0 |
| unresolved_stock_review | 1803 |

Every class had preview_only=true, automatic_execution_allowed=false, and
raw_values_emitted=false.

## Stock And EOL Review

Observed stock values were numeric but their business meaning remained
unresolved:

- total: 1803;
- zero: 982;
- one: 30;
- greater than one: 791;
- positive: 821;
- distinct numeric values: 90;
- minimum: 0;
- maximum: 100;
- blank: 0;
- non-numeric: 0;
- fractional: 0;
- negative: 0;
- official binary semantics match: false; and
- observed numeric contract valid: true.

Stock meaning, quantity mapping, and availability mapping were not approved.
No automatic catalog availability action exists.

Stock/EOL combinations:

- stock 0 / EOL 0: 982;
- stock 1 / EOL 0: 22;
- stock greater than 1 / EOL 0: 696;
- stock 0 / EOL 1: 0;
- stock 1 / EOL 1: 8; and
- stock greater than 1 / EOL 1: 95.

All EOL rows remain manual-review-only.

## Price Review

DAC and FD were both numeric for 1803 records:

- positive: 1731 each;
- zero: 72 each;
- negative: 0 each;
- equal: 912;
- DAC higher: 891; and
- DAC lower: 0.

Selected price, currency, VAT, and Green Tax remain unresolved. Zero-price
rows require review and no price write is approved.

This closeout does not infer EUR, VAT inclusion or exclusion, selected DAC or FD
price, Green Tax being zero, or Green Tax inclusion in a price.

## Protected-State Result

The preview reported all of the following as false:

- persisted_profile_created;
- executable_import_configuration_created;
- automatic_execution_allowed;
- profile_persistence_allowed;
- staging_write_allowed;
- catalog_write_allowed;
- import_executed;
- catalog_sync_executed;
- links_changed;
- schedule_changed;
- images_imported; and
- raw_values_emitted.

All records_changed values were zero for suppliers, supplier_products,
products, categories, supplier_category_mappings, canonical_product_families,
category_product_attributes, product_attributes, attribute_values,
product_attribute_values, supplier_import_runs, import_jobs,
catalog_sync_batches, catalog_sync_logs, and the aggregate Catalog Sync
counter.

Protected counts before and after were equal, protected fingerprints before and
after were equal, and no rollback was required.

## Operational Closeout Decision

The preview evidence is valid and no repeat run is required for this source
snapshot. No rollback is required. The preview is not an import approval.

The decision register remains blocked for execution. APCOM's schedule must
remain disabled and Catalog Sync must not run.

## Current Phase Status

- C.3A.2: completed, merged, and synced;
- C.3B tooling: completed, merged, and deployed;
- C.3B operational preview: completed read-only;
- C.3B strict contract: passed;
- C.3B.1 closeout: completed, merged, and synced;
- human decisions: pending;
- profile persistence: not approved;
- executable import configuration: not approved;
- import: not approved;
- schedule re-enable: not approved; and
- Catalog Sync: not approved.

## Next Gate

### Phase 9C.6.5C.3C - APCOM Authoritative Human Decision Evidence and Profile Approval Gate

C.3C tooling is implemented locally and in review. The C.3C operational v2
preview has not run. Operator-confirmed business evidence partially confirms
stock, availability, lifecycle, FD price, EUR, VAT-exclusive, and Green Tax
semantics. MPN and missing-product handling remain pending, zero-price remains
review-only, profile persistence and import remain unapproved, and the profile
approval gate remains blocked.

C.3C must not automatically persist a feed profile, execute import, write
supplier_products, create or update catalog products, delete staging rows, link
or unlink products, change the schedule, run Catalog Sync, enable UPDATE, add
Sync All, enable automatic sync, or import images.

## Non-Approval And Safety Boundary

At the C3B.1 operational closeout, APCOM was not normalized or approved for
import. Stock was not declared to be quantity or availability, DAC and FD were
not selected, currency/VAT/Green Tax treatment were not known, and EOL did not
mean automatic deactivation. C3C records partial operator-confirmed preview
semantics only; source-only rows remain unapproved for creation, staging-only
rows remain unapproved for deletion, linked staging-only rows remain unapproved
for unlinking, and the schedule cannot be re-enabled.

# Supplier Integration Inventory

## Purpose

This inventory records the supplier integration surfaces reviewed for Phase
9C.6.5A and the local Phase 9C.6.5B readiness matrix. It is an architecture
reference, not an instruction to run an import.

## Existing Runtime Surfaces

### Shared supplier/staging models

- `Supplier` stores supplier configuration and relationships.
- `SupplierFeed` stores feed metadata; feed passwords use the existing model
  protection and are not part of the new onboarding contracts.
- `SupplierProduct` is the staging boundary for supplier observations.
- Availability status and mapping models are existing database-backed runtime
  behavior and are intentionally not called by the new pure normalization
  services.

### Existing import and preview services

- `SupplierImportOrchestrator` coordinates existing supplier import history and
  staging paths. Supplier import remains staging-first.
- `SupplierStagingImportPreviewService` is the existing local XML/CSV/JSON
  preview surface and remains read-only.
- `SupplierCsvFeedImportService` and related import services retain their
  existing supplier-specific behavior.
- Existing schedule/import commands and jobs remain outside the new contract
  namespace.

### Existing ASBIS surfaces

- `AsbisDualFeedPreviewService` and `AsbisXmlStreamReader` provide the existing
  local ASBIS preview/readiness behavior.
- `ControlledAsbisDualFeedStagingImportService` is the existing explicitly
  guarded ASBIS staging path.
- ASBIS candidate fingerprinting, payload validation, readiness auditing, and
  post-apply verification remain isolated and unchanged by Phase 9C.6.5A.

## New Discovery-Only Contract Surface

The following new files are intentionally isolated under:

- `app/Contracts/Suppliers/Onboarding/`
- `app/Data/Suppliers/Onboarding/`
- `app/Services/Suppliers/Onboarding/`

They define driver, source, feed profile, normalized record, fingerprint,
preview report, staging plan, verification result, price normalization,
availability normalization, and validation issue contracts. They are not bound
to production import services and no runtime driver is registered.

## Ownership and Safety Boundaries

```text
supplier feed
-> supplier_products staging
-> preview and diagnostics
-> pricing, exclusions, matching
-> sync_action
-> manually selected sync
```

Supplier observations remain supplier-owned. Catalog product content,
categories, attributes, localized content, SEO, images, and workflow remain
catalog-owned unless a separately approved phase changes that boundary.

The new contract surface has no write authority. A future staging apply phase
must be separately reviewed, server-revalidated, bounded to
`supplier_products`, create-only by default, and followed by verification.

## Phase 9C.6.5A Review Outcome

- no new supplier was selected;
- no supplier record, feed, mapping, availability record, migration, or seeder
  was added;
- no feed was fetched and no production credential or `.env` value was added;
- no preview/import/apply operation was run;
- no `supplier_products`, products, categories, mappings, attributes, images,
  or Catalog Sync records were written;
- no schedule, queue, automatic sync, Sync All, or UPDATE enablement was added.

## Phase 9C.6.5B Readiness Matrix Outcome

- `suppliers:audit-onboarding-readiness-matrix` is a local, read-only command
  with table and JSON output using `supplier-readiness-matrix-v1`.
- It reads safe supplier configuration presence, existing capability-audit
  facts, staging counts/provenance, mapping counts, and effective Catalog Sync
  flag values only.
- It does not expose URLs, credentials, raw feed content, production paths, or
  full supplier SKU samples.
- It does not fetch feeds, call supplier APIs, run a preview/import/apply,
  create or update staging/catalog/mapping/attribute data, call Catalog Sync,
  dispatch a job, or change a schedule.
- A generic Phase 9C.6.5A contract is reported as a contract only; it is not
  treated as a registered production driver/profile.
- ASBIS reference capability is evidence-based from staged provenance and
  existing isolated services, not supplier slug or fixed production counts.

The readiness matrix remains a local read-only artifact. The separately
controlled APCOM deterministic audit and schedule freeze have now completed;
their runtime reports are documented in the APCOM closeout record and are not
committed as production artifacts.

## APCOM Legacy Integration Audit

APCOM is Supplier #1 and must not be imported again as a new supplier. The
local `suppliers:audit-legacy-staging-state` command examines existing staging
rows, existing catalog links, mapping and provenance indicators, import
history, and schedule risk without changing them. The local
`suppliers:profile-local-source` command profiles only an explicitly supplied
local XML fixture or file and produces a non-persisted, human-review feed
profile draft.

The completed APCOM closeout recorded supplier ID 5, 1,872 staging rows, 989
linked rows, XML source format, and `XmlImportEngine`. The controlled freeze
changed only `suppliers.schedule_enabled` from true to false;
`import_enabled` remains true and `schedule_type` remains `twice_daily`. The
deterministic audit was read-only, returned
`APCOM_DETERMINISTIC_AUDIT_COMPARISON_PASSED`, and ended with
`legacy_state_requires_review`, no blockers, and the warnings
`staging_present_without_verification` and `historical_causation_unknown`.
No cleanup, re-import, link repair, feed-profile approval, or Catalog Sync was
performed.

ASBIS is Supplier #2 and its controlled staging verification is complete.
Supplier #3 has not been selected.

## APCOM Local Source Normalization Planner

Phase 9C.6.5C.3 adds the generic, local-file-only
`suppliers:plan-local-source-normalization` command. APCOM is its immediate
target, but no real APCOM source was used while implementing or validating the
tool. The command requires an explicit local XML file, SHA-256 fingerprint,
and exact expected supplier/schedule/import/staging baseline. It reads only
safe aggregates and emits a non-persisted human-review plan. A separately
authorized C.3 local profile ran without writes; its report and source remain
outside this repository.

It does not request configured feed credentials or URLs, fetch a feed, import
or modify `supplier_products`, modify catalog products or taxonomy, create or
approve mappings, interpret attributes, download images, change schedules,
dispatch jobs, or call Catalog Sync. Its source/staging difference and
identifier collisions are diagnostic only. See
`docs/APCOM_LOCAL_SOURCE_NORMALIZATION_PLAN.md`.

Facts not confirmed from local code are marked `unknown` or `requires a
production read-only audit`; this inventory does not infer production feed
configuration, supplier coverage, or live staging state.

## APCOM Official Semantics And Reconciliation

Phase 9C.6.5C.3A added a local, read-only comparison command backed by the
operator-confirmed `apcom-official-v1` field contract. It cannot use generic
role guesses to infer quantity, MPN, currency, VAT, price choice, or greentax.
`stock` is not quantity; `partno` is not MPN; `cncode` is not an identifier.

The command's exact source `partno` to staging `supplier_sku` comparison is
the only authoritative rule. EAN and normalized forms are review-only
diagnostics. It outputs only aggregates and bounded hashes and cannot modify
APCOM staging, catalog products, categories, mappings, attributes, images,
jobs, schedules, or Catalog Sync. Its first operational reconciliation safely
failed closed on observed non-binary stock values with no mutations.

Phase 9C.6.5C.3A.1 adds `apcom-observed-stock-v1` for unresolved numeric stock
evidence only. It permits diagnostics to continue when stock values are
non-negative integers, but never treats them as quantity or availability. See
[APCOM Observed Stock Semantics Discrepancy](APCOM_OBSERVED_STOCK_SEMANTICS_DISCREPANCY.md).

## Controlled Schedule Freeze

Phase 9C.6.5C.1 adds the separate command
`suppliers:controlled-schedule-freeze`. It is a dry-run-first guard for one
explicit supplier before a deterministic read-only audit. It must not be used
to redefine `suppliers:cleanup-unsafe-schedules`: APCOM remains safe from the
catalog-safety perspective when its staging is present, but its schedule may
still require a separately approved temporary freeze for audit stability.

The command reads safe supplier state, staging/link counts, import-run/job
activity, protected-table counts, and Catalog Sync flags. Apply mode requires
exact expected-state and operator confirmations and can change only
`suppliers.schedule_enabled` from true to false. It does not import, fetch,
queue, link/unlink, write `supplier_products`, mutate products or taxonomy,
call Catalog Sync, or change feature flags. The scheduler was stopped and
restarted operationally around the completed APCOM freeze; the command itself
does not control containers and has no automatic unfreeze.

## APCOM Deterministic Audit Closeout

Phase 9C.6.5C.2 completed the separately approved APCOM audit after the
controlled freeze. APCOM remains Supplier #1, ASBIS remains Supplier #2, and
Supplier #3 remains unselected. The audit compared pre-freeze and post-freeze
state with zero protected-table changes and confirmed that the schedule freeze
was the only intended state change.

The final audit is read-only and carries no blockers. Its warnings are limited
to `staging_present_without_verification` and
`historical_causation_unknown`; these do not authorize re-import, mapping
approval, link repair, or Catalog Sync. Current safety is
`schedule_enabled=false`, `import_enabled=true`,
`CATALOG_SYNC_UPDATE_ENABLED=false`,
`CATALOG_SYNC_SYNC_ALL_ENABLED=false`, and
`CATALOG_SYNC_AUTO_ENABLED=false`.

See `docs/APCOM_DETERMINISTIC_AUDIT_CLOSEOUT.md` for the evidence paths,
aggregate counts, interpretation limits, and the pending local-source profile
phase. Runtime reports and any credentials remain outside Git.

## APCOM Reconciliation Review Closeout

Phase 9C.6.5C.3A.2 completed a read-only operational source-to-staging review
using the authorized source snapshot. APCOM remains Supplier #1, ASBIS
remains Supplier #2, and Supplier #3 remains unselected.

The documented aggregates are:

- schedule disabled;
- `import_enabled=true`;
- staging rows: `1872`;
- linked staging rows: `989`;
- unlinked staging rows: `883`;
- authorized source rows: `1803`;
- exact source/staging matches: `1786`;
- source-only rows: `17`;
- staging-only rows: `86`;
- staging-only linked rows: `38`;
- staging-only unlinked rows: `48`; and
- EAN conflicts: `0`.

Stock semantics remain unresolved. The operational reconciliation completed
read-only with human review required, and automatic import is not approved.
The feed profile is not approved. Existing staging rows and links remain
unchanged. See [APCOM Reconciliation Review and Operational Closeout](APCOM_RECONCILIATION_REVIEW_CLOSEOUT.md).

## APCOM C3B Human Decision Register

The local C3B implementation records APCOM source/staging identity, diagnostic
EAN, review-only lifecycle evidence, pending stock and commercial decisions,
and explicit prohibitions in `apcom-human-decisions-v1`. The matching
`apcom-preview-feed-profile-v1` remains non-persisted and non-executable.
Neither has run operationally. No real XML or production state is read during
local validation, and no supplier, staging, product, taxonomy, mapping,
attribute, image, queue, schedule, or Catalog Sync state can change.

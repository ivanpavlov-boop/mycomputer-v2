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

No production matrix run has occurred in this phase. Phase 9C.6.5C is now
implemented locally and requires a separately controlled production read-only
audit after review, merge, deployment, and an operational APCOM schedule
freeze. The implementation does not perform that audit.

## APCOM Legacy Integration Audit

APCOM is Supplier #1 and must not be imported again as a new supplier. The
local `suppliers:audit-legacy-staging-state` command examines existing staging
rows, existing catalog links, mapping and provenance indicators, import
history, and schedule risk without changing them. The local
`suppliers:profile-local-source` command profiles only an explicitly supplied
local XML fixture or file and produces a non-persisted, human-review feed
profile draft.

The supplied operational baseline is APCOM supplier ID 5, 1,872 staging rows,
989 linked rows, XML source format, `XmlImportEngine`, configured source and
authentication, and an enabled twice-daily staging schedule. These facts are
documented as supplied inputs and are not claimed as a production audit result
from this implementation. A schedule freeze remains a separate approved
operational step. No cleanup, re-import, link repair, feed-profile approval,
or Catalog Sync is included.

ASBIS is Supplier #2 and its controlled staging verification is complete.
Supplier #3 has not been selected.

Facts not confirmed from local code are marked `unknown` or `requires a
production read-only audit`; this inventory does not infer production feed
configuration, supplier coverage, or live staging state.

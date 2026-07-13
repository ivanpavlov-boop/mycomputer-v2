# Supplier Onboarding Framework

## Status

Phase 9C.6.5A is complete and merged. It defines reusable data contracts and
pure normalization/fingerprint services. It is not production wiring and does
not select or onboard a new supplier.

Phase 9C.6.5B is implemented locally as a strictly read-only multi-supplier
readiness matrix. It has not been run against production. It does not fetch
feeds, invoke previews, import data, write staging/catalog/mapping data, call
Catalog Sync, dispatch jobs, or enable schedules.

Phase 9C.6.5C - APCOM Supplier #1 Legacy Integration Audit & Normalization
Discovery is implemented locally as read-only tooling. It does not run a
production audit, change the APCOM schedule, re-import APCOM, or select
Supplier #3.

## Intended Pipeline

Every future supplier must use the same reviewed pipeline:

```text
supplier registration
-> capability audit
-> versioned feed profile
-> driver/adapter
-> normalized record
-> read-only preview/report
-> controlled supplier_products staging plan
-> post-apply verification
-> mapping review
-> manual selected CREATE
-> optional guarded UPDATE pilot
```

This phase defines the contracts between these steps. It does not execute the
pipeline.

## Contracts

The contract namespace is `App\\Contracts\\Suppliers\\Onboarding` and the
immutable value objects are in `App\\Data\\Suppliers\\Onboarding`.

### Driver and source

- `SupplierFeedDriverInterface` describes a driver without selecting a runtime
  implementation.
- `SupplierFeedSource` describes a local source and optional expected SHA-256
  fingerprint. Remote HTTP, HTTPS, and FTP locations are rejected.
- `SupplierFeedProfile` stores a versioned, supplier-specific mapping/rule
  profile without feed credentials or remote URLs.
- `DriverInspection` is a bounded, safe inspection result.

### Normalized record

`NormalizedSupplierRecord` uses schema `supplier-normalized-record-v1` and
keeps supplier-owned fields distinct from catalog-owned fields. It carries
identifiers, name/brand/category observations, price/currency, quantity,
availability observations, provenance fingerprints, warnings, and validation
issues. Canonical serialization excludes runtime database identifiers and
source paths.

### Fingerprints and preview

- `SourceFingerprint` accepts SHA-256 digests only.
- `CandidateFingerprintService` hashes canonical normalized records in stable
  order, so the result is independent of input order.
- `PreviewReport` is read-only, bounded, and reports classifications, issue
  samples, source/candidate fingerprints, and zero mutation counters.
- `ValidationIssue` and `PreviewClassification` make blockers and warnings
  explicit without performing a write.

### Staging and verification

`StagingPlan` is a create-only planning structure. Its fixed scope is
`supplier_products-only`, its update count must remain zero, and it has no
apply method. It is a contract for a later controlled staging phase, not a
staging writer.

`PostApplyVerificationResult` describes source, candidate, SKU, canonical row,
provenance, price, availability, truncation/schema, and protected-table
checks. Its verified state requires zero linked catalog products and zero
changes to protected tables.

## Pure Normalization Rules

`PriceNormalizationService` is profile-driven and does not query currency,
tax, pricing, or catalog tables. It validates decimal input, emits a fixed
two-decimal representation without implicit tax assumptions or silent rounding,
and reports negative, overflow, precision, and missing-value issues.

`AvailabilityNormalizationService` applies an explicit profile mapping first,
then conservative standard labels, then a quantity-only fallback using the
existing threshold convention. Unknown external values remain warnings rather
than being silently treated as a safe mapping. It does not query or write
availability tables.

## Security and Safety Boundary

Contract metadata is guarded against passwords, secrets, tokens, credentials,
API keys, private keys, authorization values, and remote feed URLs. Local
source paths are intentionally omitted from serialized source descriptors.

The Phase 9C.6.5A code has no HTTP client, queue dispatch, scheduler, storage
write, Eloquent query, Catalog Sync call, image action, or production service
container binding. There is no generic XML/CSV/JSON driver yet. A fake driver
is used only by tests.

## Readiness Matrix

`suppliers:audit-onboarding-readiness-matrix` combines existing redacted
supplier capability facts, local database metadata, staging provenance, mapping
counts, and the Phase 9C.6.5A contract surface into a machine-readable report
with schema `supplier-readiness-matrix-v1`.

The command is read-only. It does not request feeds or APIs, inspect remote
credentials, invoke a preview or verifier command, import records, create
staging rows, call Catalog Sync, dispatch jobs, alter schedules, or download
images. It reports only configuration presence and safe metadata; URLs,
usernames, passwords, tokens, header values, raw source records, production
paths, and full supplier SKUs are excluded. Optional staging samples are
SHA-256 hashes and bounded by `--sample-limit`.

The matrix distinguishes a generic interface/profile contract from a
production-wired driver or profile. A configured legacy XML/CSV staging driver
is evidence that a local staging surface exists, but is not evidence that the
new onboarding driver/profile is ready. ASBIS reference evidence is derived
from actual staged provenance metadata and the existing isolated capability
classes, never from the supplier slug or hard-coded counts.

Each supplier has exactly one machine-readable primary stage. Stages range from
`disabled` and `source_not_configured` through `driver_required`,
`source_profile_required`, `staging_present_unverified`, and
`staging_verified`; `blocked` overrides every other stage when linked staging,
an early schedule, or unsafe global Catalog Sync flags are observed.

The diagnostic score is deterministic and never selects a supplier or permits
an operation. It awards: active `10`, import enabled `10`, known format `5`,
source configured `10`, configured required authentication `5`, driver `15`,
profile `15`, preview `10`, controlled staging capability `10`, post-apply
verification capability `5`, and verified staging provenance `5`. Schedule
state awards no points. Blockers override score-based ordering.

The report exposes effective Catalog Sync flags without changing them. UPDATE,
Sync All, or automatic sync enabled produces the unsafe matrix verdict. CREATE
being enabled is informational only. All protected-table counters remain zero
for a normal isolated audit.

## Explicitly Not Implemented

- no supplier #2 selection or supplier record;
- no remote feed fetch or production credential configuration;
- no preview/import/apply command invocation;
- no `supplier_products` or product write;
- no category, mapping, attribute, image, or SEO write;
- no Catalog Sync call;
- no schedule, job, automatic sync, Sync All, or UPDATE enablement;
- no migration, seeder, route, admin page, or deployment.

ASBIS behavior remains in its existing isolated services and is unchanged.

## Phase 9C.6.5C - APCOM Legacy Discovery

APCOM remains Supplier #1, the historically integrated supplier. It is not
imported again as a new supplier. ASBIS remains Supplier #2 with its completed
controlled staging verification. Supplier #3 has not been selected.

The local audit command is `suppliers:audit-legacy-staging-state`. It reads
existing APCOM `supplier_products`, links, catalog comparison indicators,
mapping state, import history, schedule facts, and effective Catalog Sync
flags. It returns `supplier-legacy-staging-audit-v1`, bounded aggregate
diagnostics, hashed identifier samples, before/after table counts, and zero
mutation counters. It never fetches the APCOM feed, calls a supplier API,
changes a schedule, links or unlinks products, runs Catalog Sync, dispatches
work, or writes any table. An enabled schedule with linked and unverified
staging produces `schedule_must_be_frozen`; the command does not freeze it.

The local source profiler is `suppliers:profile-local-source`. It accepts only
an explicitly supplied local XML file, uses streaming XMLReader, rejects remote
URLs and stream wrappers, reports a SHA-256 fingerprint and bounded field/path
diagnostics, and emits a non-persisted `supplier-feed-profile-draft-v1`
requiring human review. It never uses the configured supplier feed URL,
downloads images, persists a profile, or starts an import.

Both commands require CREATE enabled, UPDATE disabled, Sync All disabled, and
automatic sync disabled. The supplied APCOM operational baseline is 1,872
staging rows and 989 linked rows, with XML and `XmlImportEngine` configured and
an enabled twice-daily staging schedule. These are audit inputs, not a
production audit result. No schedule freeze, cleanup, re-import, link repair,
Catalog Sync, or production audit is performed in this phase.

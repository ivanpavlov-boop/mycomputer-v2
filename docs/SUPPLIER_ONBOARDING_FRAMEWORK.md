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
Discovery is implemented as read-only tooling. Its production deterministic
audit and controlled schedule freeze completed under the separately approved
Phase 9C.6.5C.1 and 9C.6.5C.2 operational sequence. Phase 9C.6.5C.3 local-only
normalization planning, C.3A official/observed semantics tooling, and the
C.3A.2 operational review closeout are complete read-only stages. No feed
profile, import, or Catalog Sync action is approved. Supplier #3 remains
unselected.

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
automatic sync disabled. The completed deterministic audit recorded 1,872
staging rows and 989 linked rows, with XML and `XmlImportEngine` configured.
The APCOM schedule is now disabled and `import_enabled` remains true. The
approved feed profile is still missing, staging remains unverified, and no
cleanup, re-import, link repair, mapping approval, Catalog Sync, or automatic
operation is authorized.

## Phase 9C.6.5C.1 Controlled Supplier Schedule Freeze

`suppliers:controlled-schedule-freeze` is a separate, reusable operational
guard for deterministic audits. It is not a replacement for
`suppliers:cleanup-unsafe-schedules`: the cleanup command classifies unsafe
configuration, while this command temporarily freezes one explicitly selected
supplier whose staging may otherwise change during an audit. APCOM remains
`safe_staging_only` for catalog-safety classification.

The command is dry-run-first. It reads one supplier, staging/link counts,
available import-run/job state, protected-table counts, and effective Catalog
Sync flags. It does not fetch feeds, run imports, dispatch jobs, call Catalog
Sync, or write during dry-run. The controlled freeze completed with one
committed `suppliers.schedule_enabled: true -> false` change.

Apply mode requires an explicit supplier confirmation, the
`freeze-for-audit` action, the `schedule-enabled-only` write scope, an
external scheduler-stopped acknowledgement, a non-empty reason, and locked
expected supplier/schedule/import/staging values. It rechecks those values
while holding the selected supplier row with a database `FOR UPDATE` lock.
The only semantic write is `suppliers.schedule_enabled: true -> false`;
import settings, schedule type/timestamps, staging links, catalog data,
mappings, attributes, and Catalog Sync records remain unchanged. Postconditions
are checked inside the transaction and any mismatch rolls back.

The completed operational sequence was: fresh dry-run, capture expected state,
stop the scheduler container operationally, confirm no active import, run the
guarded apply, verify the schedule flag, restart the scheduler, and run the
separate deterministic read-only APCOM audit. The command never stops or
starts containers and has no automatic unfreeze.

## Phase 9C.6.5C.2 Deterministic Audit Closeout

The APCOM deterministic production audit completed with `read_only=true`,
`FINAL_AUDIT_EXIT=0`, `COMPARE_EXIT=0`, and comparison result
`APCOM_DETERMINISTIC_AUDIT_COMPARISON_PASSED`. The audit verdict remains
`legacy_state_requires_review` with no blockers and the warnings
`staging_present_without_verification` and `historical_causation_unknown`.
All audit records-changed counters were zero. Runtime reports are operational
evidence and are not Git artifacts.

The closeout preserves the deterministic sequence and explicitly keeps APCOM
frozen. It does not approve automatic imports, mapping approval, content
normalization, link repair, or Catalog Sync.

## Phase 9C.6.5C.3 Local Source Normalization Planning

`suppliers:plan-local-source-normalization` is a reusable local XML planner.
It locks an explicitly supplied expected baseline, requires a SHA-256-pinned
local source, refuses a changed schedule or active/unknown import state, and
combines the local source profiler with existing staging counts and safe
fingerprints. It emits `supplier-local-source-normalization-plan-v1` with
field coverage, normalization proposals, offer-field diagnostics, category and
attribute/image policy, collision counts, source-to-staging count drift, and
zero-change proof.

The planner is read-only and requires human review. It does not persist a feed
profile, create an executable import configuration, fetch a feed, write
staging/catalog/mapping/attribute data, repair links, download images, change
a schedule, dispatch jobs, or call Catalog Sync. An authorized local C.3
profile has run without writes; its source and report remain outside Git. See
`docs/APCOM_LOCAL_SOURCE_NORMALIZATION_PLAN.md` for its full safety contract.

## Phase 9C.6.5C.3A Official Semantics Reconciliation

`suppliers:reconcile-local-source-staging` is a local-only review tool. It
combines the existing local profiler, the shared active-import guard, an
explicit baseline lock, and `apcom-official-v1`. Exact normalized-safe source
`partno` to staged `supplier_sku` is authoritative; EAN and case/whitespace
normalization remain diagnostics only. The command emits aggregates and bounded
domain-separated hashes, not raw source records or IDs.

The strict profile's first operational run was read-only and safely failed
closed when observed stock values exceeded the published binary contract. It
made no change. Phase 9C.6.5C.3A.1 adds an observed numeric-stock review
profile so SKU/EAN diagnostics can continue without approving quantity or
availability semantics. It has no apply mode, profile persistence, remote
fetch, import, mapping, link repair, image operation, schedule change, job
dispatch, or Catalog Sync behavior. See
[APCOM Observed Stock Semantics Discrepancy](APCOM_OBSERVED_STOCK_SEMANTICS_DISCREPANCY.md).

## Phase 9C.6.5C.3A.2 Operational Review Closeout

The reviewed operational sequence was:

```text
strict profile
-> fail-closed evidence
-> observed profile
-> zero-mutation reconciliation
-> human review
-> documentation closeout
-> separate preview-only decision phase
```

The observed-profile reconciliation completed read-only with
`reconciliation_requires_stock_semantics_review`, zero blockers, seven
warnings, and zero records changed. Exact source-to-staging results,
linked-state risk groups, EAN consistency, unresolved commercial decisions,
and prohibitions are recorded in
[APCOM Reconciliation Review and Operational Closeout](APCOM_RECONCILIATION_REVIEW_CLOSEOUT.md).

The closeout does not authorize a feed profile, import, staging mutation,
link repair, catalog mutation, schedule re-enable, or Catalog Sync. C3B.1 is
completed, merged, and synced; C3C tooling is implemented locally and in
review, and its operational v2 preview has not run.

## Phase 9C.6.5C.3B Human Decision Register And Preview-only Design

The C3B implementation is local and in review. It adds
`apcom-human-decisions-v1`, `apcom-preview-feed-profile-v1`, and
`suppliers:design-preview-feed-profile`. The command layers static decisions
over the existing read-only reconciler and reports aggregate candidate classes
and bounded hashes only. It cannot persist a profile, create executable import
configuration, import, write staging/catalog data, alter links, change a
schedule, import images, dispatch jobs, or call Catalog Sync. Pending decisions
remain blocking, and a safe success verdict means human decisions are still
required rather than approved.

## Phase 9C.6.5C.3B.1 Operational Closeout

The onboarding sequence now includes the completed read-only operational
preview and documentation closeout. The strict contract passed, but the
preview verdict still requires human decisions. The source snapshot produced
1803 source records, 1872 staging rows, 1786 exact matches, 17 source-only
rows, 86 staging-only rows, and 22 blocking decisions. No import, persistence,
link change, schedule change, image action, or Catalog Sync action occurred.
See APCOM_PREVIEW_ONLY_FEED_PROFILE_OPERATIONAL_CLOSEOUT.md.

## Phase 9C.6.5C.3C Authoritative Decisions And Blocked Gate

`apcom-human-decisions-v2` and `apcom-preview-feed-profile-v2` are additive
read-only contracts. They introduce supplier-neutral availability/lifecycle
statuses and the APCOM-only `apcom-availability-policy-v1`; exact supplier
quantities remain hidden publicly. The policy confirms 0 -> on_request, 1-5
-> limited, 6+ -> in_stock, EOL positive stock -> last_units, and EOL zero
stock -> discontinued. It does not implement catalog aggregation, storefront
behavior, import, profile persistence, schedule enablement, or Catalog Sync.

The immutable profile approval gate is
`blocked_pending_human_decisions`. MPN and missing-product handling remain
pending, zero-price remains review-only, and snapshot freshness remains
unresolved. UPDATE remains disabled, Sync All remains disabled, automatic sync
remains disabled, and images remain prohibited.

## Phase 9C.6.5C.3D Missing Offer Lifecycle Preview

Phase 9C.6.5C.3D is merged and deployed synthetic read-only tooling at
`c22fc9a8dddf3c6778ab0b88e5a50cbc02fe3f21`. It defines qualified
full-snapshot absence tracking, three consecutive qualified missing snapshots
plus a 48-hour duration, reappearance validation, multi-supplier offer
aggregation, future
visibility/archival policy, deletion prohibition, and retention planning. It
also has a documented input-driven operational evidence contract for review:
immutable versioned evidence, explicit `evaluated_at`, CLI-only evaluation,
stable deterministic output, no persistence, and fail-closed import
concurrency checks. No real APCOM evidence has been processed and operational
execution remains unapproved. The current tooling does not read real supplier
XML, modify any database table, use a scheduler, alter the storefront, Scout,
sitemap, robots, or Catalog Sync. See
[Supplier Offer Missing Lifecycle Policy](SUPPLIER_OFFER_MISSING_LIFECYCLE_POLICY.md),
[Catalog Product Visibility And Archival Policy](CATALOG_PRODUCT_VISIBILITY_ARCHIVAL_POLICY.md),
and [Supplier Technical Retention Policy](SUPPLIER_TECHNICAL_RETENTION_POLICY.md).

Its operational safety marker is the importer-owned `import_histories.id`:
real XML/CSV engines create one row before feed/staging work and transition
that same row to terminal evidence. Import History administration is
list/view-only, application mutation/deletion and supplier cascade deletion of
generation evidence are blocked, and no retention/pruning path is authorized.
The final preview boundary performs supplier/import, generation and catalog
fingerprint checks in that order and performs no mutable-state read afterward.

[APCOM Missing Offer Decisions V4](APCOM_MISSING_OFFER_DECISIONS_V4.md) is the
current documentation-only decision register for this phase. It closes only
source-only preview classification, supplier-SKU-only `partno`, zero-price
review, and APCOM-specific 24-hour freshness semantics. Documentation merge is
a prerequisite only; the implementation gate remains closed and the current
tooling and supplier onboarding flow remain non-persistent and non-executable.

## Phase 9C.6.5C.3D.1-PRE.A Immutable Snapshot Persistence Design

The confirmed C3D.1 blocker is the absence of a qualified immutable historical
source. `supplier_products`, ImportHistory aggregate context, mutable feed
items, logs and current timestamps cannot prove historical absence,
reappearance or chronology. They must not be backfilled into evidence.

[Immutable Supplier Offer Snapshot Persistence Design](IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md)
defines a documentation-only prerequisite: future imports may add one final
append-only generation header, immutable first-enrollment cohort rows and an
exhaustive physical privacy-safe hashed presence/absence set without changing
staging semantics. A separate stable parent-execution claim shared by both XML
job paths prevents concurrent or sequential redelivery from creating another
ImportHistory or snapshot generation; terminal delivery is a no-op, and
different source bytes for the same key fail closed. The remediated design also
requires a strict opaque source identity, one common supplier capture lock,
bounded temp-file streaming, exact named foreign-key/query indexes, fail-closed
gaps and deterministic qualification. It does not add a claim table, migration,
streaming parser change, capture implementation, producer, import approval,
schedule enablement, lifecycle action or Catalog Sync behavior.

The first complete generation in each source/cohort epoch is a comparison
baseline only. Because the current V1 lifecycle contract requires
`comparable=true`, the unchanged V4 threshold is met only by three later
qualified comparable absences spanning at least 48 hours from the first of
those three. Any gap, overlap or cohort expansion requires a new baseline.
C3D.1 remains blocked until a separately authorized implementation is deployed
and enabled and that future-history window is collected. Supplier #3 work must
not begin before this prerequisite is resolved.

The immutable-persistence rollout sequence is exact and non-combinable:

1. Persistence design receives independent approval and its documentation PR is merged into `main`.
2. Additive schema/migration implementation is separately authorized, implemented and tested.
3. Capture/idempotency implementation is separately authorized, implemented and tested while remaining disabled by default.
4. Schema and capture implementation receive independent review and are merged into `main`.
5. The merged implementation is separately deployed to staging and verified; deployment does not enable capture.
6. APCOM snapshot capture is separately authorized and explicitly enabled; enablement does not authorize an import.
7. Individual future APCOM imports are separately and manually authorized.
8. Immutable qualified history is collected and the full warm-up/readiness gate passes.
9. The read-only evidence producer is separately implemented, reviewed, merged and deployed.
10. One exact evidence candidate is prepared and explicitly approved by the human operator.
11. Exactly one controlled read-only operational preview is separately authorized and executed.
12. Operational results receive independent review and documentation-only closeout is completed.

Review, merge, deployment, enablement, import, evidence preparation, approval,
preview and closeout remain separate authorization boundaries. Failure at one
gate cannot authorize the next.

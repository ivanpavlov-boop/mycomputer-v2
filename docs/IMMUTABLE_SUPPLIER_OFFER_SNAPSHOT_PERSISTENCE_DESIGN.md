# Immutable Supplier Offer Snapshot Persistence Design

## Status And Scope

Phase 9C.6.5C.3D.1-PRE.A is a documentation-only design. It resolves the
architecture question behind `BLOCKED_HISTORICAL_SOURCE_CONTRACT_REQUIRED`,
but it does not add tables, models, import hooks, evidence production, or an
operational preview. No existing data is qualified by this design.

The design is supplier-generic where the existing importer already provides a
supplier and feed boundary. APCOM is the first bounded consumer. V1 through V3
remain historical contracts and V4 remains the current semantic authority.

Read this design with [APCOM Missing Offer Decisions V4](APCOM_MISSING_OFFER_DECISIONS_V4.md),
[APCOM Operational Offer Lifecycle Preview](APCOM_OPERATIONAL_OFFER_LIFECYCLE_PREVIEW.md),
[Supplier Offer Missing Lifecycle Policy](SUPPLIER_OFFER_MISSING_LIFECYCLE_POLICY.md),
[Catalog Product Visibility And Archival Policy](CATALOG_PRODUCT_VISIBILITY_ARCHIVAL_POLICY.md),
and [Supplier Technical Retention Policy](SUPPLIER_TECHNICAL_RETENTION_POLICY.md).

## Existing Boundary

The current application cannot reconstruct qualified lifecycle history:

- `import_histories.id` is an immutable importer-owned generation marker, but
  its terminal context contains aggregate processed and failed counts only;
- `supplier_import_runs` contains mutable aggregate execution reports;
- `supplier_products` is the mutable current staging projection;
- `supplier_feed_items` is raw mutable data without qualified generation
  provenance and is not populated by the reviewed import flow;
- `received_at`, `updated_at`, logs, caches, current presence, and current
  payloads are not authoritative historical snapshot evidence.

The future schema therefore starts empty. Existing rows must not be backfilled,
converted, or presented as historical absence, presence, or reappearance.

The reviewed XML flow is
`SupplierImportOrchestrator -> RunSupplierImportJob -> XmlImportEngine::import()`;
the legacy scheduled path also reaches `XmlImportEngine::import()` through
`ProcessXmlSupplierFeed`. The engine creates the ImportHistory generation before
loading and mapping the source, updates current `supplier_products`, and then
records the terminal import outcome. `SupplierImportRun` remains an outer
mutable aggregate/report. The first APCOM capture integration therefore belongs
inside the shared XML engine after `ImportHistory::startForImport()`, not only
in one caller. Other import engines require their own later reviewed source
adapter and cannot be treated as qualified by inference.

## Selected Architecture

The future implementation adds two append-only evidence tables and reuses the
existing `import_histories.id` as the attempt sequence marker:

1. `supplier_offer_snapshot_generations` stores one immutable final header for
   one import generation.
2. `supplier_offer_snapshot_observations` stores the exhaustive set of offers
   actually observed during that generation, using hashes rather than raw
   identifiers.

There is no mutable current-snapshot row. A complete header and its observations
are inserted in one database transaction after source traversal. A failed or
partial traversal may insert a frozen header with zero observations. If even
that persistence fails, the existing ImportHistory generation remains without
a snapshot header. That missing header is a sequence gap and blocks lifecycle
readiness; it must never be interpreted as offer absence.

The header is not updated from `started` to `finished`. It is a final fact.
`import_histories` continues to own the start/terminal import transition, while
the snapshot header records the final capture outcome once.

## Generation Header Data Dictionary

Proposed additive table: `supplier_offer_snapshot_generations`.

| Column | Type | Null/default | Purpose and invariant | Index/FK | Privacy |
| --- | --- | --- | --- | --- | --- |
| `id` | unsigned bigint | not null | Surrogate storage key; never emitted as evidence | primary | internal |
| `supplier_id` | unsigned bigint | not null | Supplier ownership copied from the generation | index; FK `RESTRICT` | internal |
| `supplier_key` | varchar(96) ASCII | not null | Versioned canonical supplier key captured with the generation; later supplier edits cannot rewrite provenance | index with generation | public contract |
| `supplier_feed_id` | unsigned bigint | not null | Exact feed ownership at capture time | index; FK `RESTRICT` | sensitive metadata |
| `import_history_id` | unsigned bigint | not null | Existing immutable importer generation identity | unique; FK `RESTRICT` | internal |
| `schema_version` | varchar(96) | not null | Snapshot persistence schema version | index | public contract |
| `producer_version` | varchar(96) | not null | Capture implementation contract version | index | public contract |
| `policy_versions` | JSON | not null | Canonically ordered policy-version map used for qualification | application exact-key check | public contract |
| `freshness_policy_key` | varchar(96) ASCII | nullable | Exact approved supplier freshness-policy key, when one exists | index | public contract |
| `freshness_max_age_hours` | unsigned integer | nullable | Captured approved maximum age; null when no policy exists | none | policy metadata |
| `freshness_policy_approved` | boolean | false | Approval fact captured for V1 projection | index | public contract |
| `source_identity` | varchar(512) UTF-8 | not null | Exact validated identity, maximum 128 Unicode code points; no path or URL | supplier/index prefix where supported | restricted metadata |
| `source_fingerprint` | char(64) ASCII | not null | Lowercase SHA-256 of the exact source bytes consumed by that import | index | pseudonymous |
| `captured_at` | char(25) ASCII | not null | Canonical ISO-8601 completion instant with numeric offset and second precision | index | operational metadata |
| `authoritative_snapshot_at` | char(25) ASCII | nullable | Supplier-authoritative instant only when genuinely available | index | operational metadata |
| `capture_started_at` | char(25) ASCII | not null | Canonical source traversal start | none | operational metadata |
| `capture_completed_at` | char(25) ASCII | not null | Canonical source traversal end; not evidence freshness unless authoritative | index | operational metadata |
| `capture_outcome` | varchar(48) | not null | Closed code: `completed`, `completed_with_errors`, `failed`, `incomplete`, `overflow` | index | public contract |
| `failure_reason_code` | varchar(96) | nullable | Stable privacy-safe code only; no exception message or source data | index | public contract |
| `qualification_state` | varchar(48) | not null | Closed final code: `qualified`, `failed`, `incomplete`, `schema_invalid`, `truncated`, `overflow`, `anomalous`, `not_comparable`, or `integrity_blocked` | index | public contract |
| `successful` | boolean | false | Import/capture success fact | index | public contract |
| `full` | boolean | false | Exhaustive traversal completed | index | public contract |
| `schema_valid` | boolean | false | Every required source field passed the approved schema | index | public contract |
| `truncated` | boolean | false | Any source or collector truncation occurred | index | public contract |
| `fatal_integrity_blocker` | boolean | false | Integrity failure freezes this generation | index | public contract |
| `supplier_identity_confirmed` | boolean | false | Source belongs to the expected supplier | index | public contract |
| `comparable` | boolean | false | Source identity and semantics match the previous qualified generation | index | public contract |
| `total_observed_count` | unsigned integer | 0 | Parsed source records before deduplication | none | aggregate |
| `valid_observation_count` | unsigned integer | 0 | Unique observations eligible for the immutable set | none | aggregate |
| `invalid_observation_count` | unsigned integer | 0 | Records rejected by field validation | none | aggregate |
| `rejected_observation_count` | unsigned integer | 0 | Records rejected by policy/scope validation | none | aggregate |
| `duplicate_observation_count` | unsigned integer | 0 | Duplicate stable offer identities | none | aggregate |
| `minimum_product_count` | unsigned integer | not null | Supplier threshold captured for reproducibility | none | policy metadata |
| `product_drop_percent` | decimal(9,6) | not null | Exact count drop from the preceding comparable generation | none | aggregate |
| `maximum_product_drop_percent` | unsigned tinyint | not null | Captured supplier threshold | none | policy metadata |
| `observation_set_fingerprint` | char(64) ASCII | nullable | Hash of ordered canonical observation fingerprints; required for a complete set | index | pseudonymous |
| `created_at` | timestamp | database current time | Storage audit time only; never lifecycle chronology | index | operational metadata |

The table has no `updated_at`. Application validation requires lowercase
64-character hexadecimal fingerprints, exact policy keys, canonical timestamps,
non-negative counts, count reconciliation under the capture contract, and a
non-null observation-set fingerprint only for a persisted exhaustive set.
`qualification_state=qualified` is allowed only when every objective gate in
this document passes; it is a final reproducibility fact, not a mutable workflow
status.

`supplier_id`, `supplier_feed_id`, and `import_history_id` must agree with the
existing ImportHistory generation. `supplier_key` must agree with the
versioned feed profile at capture time. A freshness key, age and approval must
either form one complete valid triple or all be absent/false. The unique
`import_history_id` constraint provides retry idempotency and rejects duplicate
capture finalization.

The future MySQL DDL must use `CHECK` constraints for the closed outcome and
qualification codes, boolean domains, 0-100 maximum drop threshold, fingerprint
shape, and freshness triple. A `qualified` row must imply `successful=true`,
`full=true`, `schema_valid=true`, `truncated=false`, no fatal blocker, confirmed
supplier identity, comparability, zero invalid/rejected rows, a completed
outcome, and a non-null observation-set fingerprint. Cross-row count and
fingerprint reconciliation remains an application transaction invariant and
must be verified again by the reader.

## Observation Data Dictionary

Proposed additive table: `supplier_offer_snapshot_observations`.

| Column | Type | Null/default | Purpose and invariant | Index/FK | Privacy |
| --- | --- | --- | --- | --- | --- |
| `id` | unsigned bigint | not null | Surrogate storage key; never emitted | primary | internal |
| `snapshot_generation_id` | unsigned bigint | not null | Immutable owning header | FK `RESTRICT`; composite indexes | internal |
| `supplier_sku_hash` | char(64) ASCII | not null | Existing domain-separated offer identity | unique with generation | pseudonymous |
| `present` | boolean | true | Stored rows are offers observed in the complete traversal | index | public contract |
| `price` | decimal(12,2) | nullable | Canonical supplier price needed by V4 preview | none | commercial restricted |
| `currency` | char(3) ASCII | nullable | ISO 4217 currency when the source carries it; null only when the versioned feed profile fixes the currency unambiguously | none | commercial metadata |
| `raw_quantity_observed` | unsigned integer | nullable | Bounded internal stock observation | none | commercial restricted |
| `eol_flag` | unsigned tinyint | nullable | Validated 0/1 supplier lifecycle evidence | none | restricted metadata |
| `canonical_public_status` | varchar(48) | nullable | Existing canonical availability enum value | index | public contract |
| `supplier_mapper_valid` | boolean | false | Approved mapper accepted the observation | index | public contract |
| `exact_supplier_sku_match` | boolean | false | Identity validation result | index | public contract |
| `identifier_conflict` | boolean | false | Conflict freezes reappearance/matching interpretation | index | public contract |
| `blocking_validation_issue` | boolean | false | Stable blocker fact without raw error text | index | public contract |
| `duplicate_offer` | boolean | false | Duplicate identity classification | index | public contract |
| `reliable_manufacturer_mpn_hash` | char(64) ASCII | nullable | Reserved V1 evidence field; APCOM V4 requires null because no reliable MPN field or approved MPN hash domain exists | none | pseudonymous |
| `observation_fingerprint` | char(64) ASCII | not null | Hash of canonical observation fields | index | pseudonymous |
| `created_at` | timestamp | database current time | Storage audit time only | none | operational metadata |

The table has no `updated_at`. A complete generation stores exactly one row per
unique observed supplier-offer identity. Raw identifiers are never stored.
The later evidence adapter selects an explicit bounded generation range, forms
the deterministic union of immutable offer hashes, and emits explicit
`present=false` observations for identities absent from a complete generation.
An incomplete or frozen generation is never used to prove absence.

The future MySQL DDL must constrain physical rows to `present=true`, EOL to
null/0/1, currency to null or three uppercase ASCII letters, fingerprint/hash
columns to lowercase hexadecimal shape, and `canonical_public_status` to the
versioned enum accepted by the current V1 reader. The composite unique
constraint is (`snapshot_generation_id`, `supplier_sku_hash`).

The future producer must fail if current catalog coverage requires an identity
that cannot be accounted for by the selected immutable generation range. It
must not fill that gap from mutable staging.

## Projection Into The Existing V1 Evidence Contract

The later source adapter must project a bounded selection into
`supplier-offer-lifecycle-operational-evidence-v1`; it must not change that
reader schema silently. The mapping is fixed as follows:

| V1 field | Immutable source |
| --- | --- |
| `snapshot_id` | `sample('snapshot_generation', CanonicalOnboardingData::encode(['import_history_id' => ..., 'supplier' => ...]))`; the raw database ID is not emitted |
| `supplier` | stored versioned canonical `supplier_key` |
| `source_identity` | exact stored `source_identity` |
| `captured_at` | stored `captured_at` |
| `authoritative_snapshot_at` | stored authoritative timestamp; a missing value blocks APCOM qualification |
| `fingerprint` | stored `source_fingerprint`; repeated exact source bytes remain duplicate-fingerprint evidence |
| `status` | stored `capture_outcome` |
| qualification booleans | stored `successful`, `full`, `schema_valid`, `truncated`, `fatal_integrity_blocker`, `supplier_identity_confirmed`, and `comparable` |
| `product_count` | stored `valid_observation_count` |
| count/drop thresholds | stored `minimum_product_count`, `product_drop_percent`, and `maximum_product_drop_percent` |
| observations | deterministic union projection described below |

The adapter recomputes V4 qualification from the primitive fields and requires
it to agree with the stored `qualification_state`; the stored state cannot
override the current versioned policy. Header audit counts, capture timing,
currency, row/set fingerprints and failure codes are persistence-only metadata
and are not added to the exact-key V1 JSON.

Bundle-level supplier scope, source identity, policy versions and freshness
policies are emitted only when every selected generation agrees exactly on the
stored values. An absent or unapproved APCOM freshness policy blocks bundle
production; the producer must not read a newer mutable supplier setting to fill
the gap.

For an explicit selected generation range, the adapter first forms the sorted
union of every persisted `supplier_sku_hash`. Each generation then emits one
V1 observation for every union member. A physical row maps its semantic fields
directly and has `present=true`. An identity absent from an otherwise qualified
complete generation is emitted deterministically with `present=false`, null
price/quantity/EOL/status/MPN, false mapper and exact-match flags, and false
conflict/blocker/duplicate flags. A missing, incomplete or frozen generation is
never expanded into absence observations and instead blocks the bundle.

Optional V1 `product_lifecycle_evidence` is not snapshot-table data. If a later
producer includes it, it must come from the existing separately fingerprinted
read-only catalog boundary and use the existing `product()` hash domain. It
must never create a Product foreign key or Product mutation in these tables.

## Explicitly Prohibited Data

Neither table may contain raw supplier SKU, EAN/GTIN, MPN, product name,
description, raw source record, XML, feed URL, credential, token, host or
container path, catalog SEO, category, attribute, image, or application secret.
Exception messages and log prose are not evidence fields.

## Cryptographic Contract

The design reuses `OperationalSupplierOfferIdentityHasher` and
`CanonicalOnboardingData`; it does not introduce a second algorithm.

- Encoding is UTF-8.
- Supplier keys use the existing lowercase/trim behavior.
- Source identity is the exact validated decoded UTF-8 value. It is not
  trimmed, case-folded or Unicode-normalized, and remains bounded to 128 code
  points under the existing validator.
- Supplier SKU identity is exactly SHA-256 of
  `supplier-offer-lifecycle-operational-preview-v1|supplier_sku|<supplier>|<sku>`
  through the existing `supplierSku()` method.
- A product reference, when the existing evidence/report contract requires it,
  uses the existing `product()` domain.
- Observation fingerprints use the existing `sample()` primitive with the
  explicit bucket `snapshot_observation` and canonical JSON bytes of the
  semantic observation fields, excluding storage ID, foreign key,
  `observation_fingerprint`, and `created_at`.
- Observation-set fingerprints use `sample()` with the explicit bucket
  `snapshot_observation_set` and canonical JSON containing the sorted
  observation fingerprints.
- Evidence `snapshot_id` values use `sample()` with the explicit bucket
  `snapshot_generation` and canonical JSON bytes containing the stored supplier
  key and ImportHistory ID.
- Source fingerprints remain lowercase SHA-256 of the exact bytes consumed by
  the authorized importer. They are not derived from a path or URL.

The current hasher has no dedicated manufacturer-MPN method. APCOM V4 leaves
`reliable_manufacturer_mpn_hash` null, so this persistence design does the same.
It must not improvise an MPN bucket through `sample()`. A future supplier that
requires MPN evidence needs a separately reviewed, versioned cryptographic
contract before that nullable column may be populated.

The current V1 contract uses unkeyed domain-separated SHA-256; keyed hashing is
not currently required. Hashes are therefore pseudonymous, not anonymous, and
remain restricted operational data. No secret is introduced or referenced.
A future keyed-hash change requires a new versioned schema and migration design;
old hashes must not be silently rehashed or mixed with a new namespace.

Any equal supplier-offer hash with non-identical canonical observation input is
a collision/conflict and freezes the generation. The system must not choose one
row. Raw values must not be logged to diagnose the conflict.

## Append-only Enforcement

The future migration and models must enforce all of these boundaries:

- no update path for either table;
- database `BEFORE UPDATE` and `BEFORE DELETE` guards reject mutation with a
  stable error on supported MySQL deployments; the migration contract must
  test those guards rather than relying only on model behavior;
- no mass-assignment mutation surface;
- model insert methods callable only from the capture repository;
- model `delete`, `forceDelete`, increment, decrement, and touch rejected;
- no `CASCADE` or `SET NULL` from supplier, feed, ImportHistory, or header;
- parent deletion blocked by `RESTRICT` foreign keys and application guards;
- one final header per ImportHistory generation;
- one observation per generation and supplier SKU hash;
- all final rows inserted in one transaction;
- no status transition represented by updating a header;
- `created_at` and IDs never used as source chronology;
- no automatic prune, retention job, or admin mutation resource.

Direct query-builder writes remain an implementation-review concern. Tests must
prove database constraints and restricted foreign keys independently of model
guards. Database users should receive no ordinary UPDATE or DELETE grant for
these tables where deployment operations support table-level grants.

## Future Import Capture Semantics

The future integration must be an additive observer of the existing authorized
import traversal, not a second feed request or a second import framework.

1. `ImportHistory::startForImport()` allocates the immutable generation before
   source reading, as it does now.
2. A supplier-scoped, default-off capture gate decides whether a bounded
   collector is attached. Deployment alone does not enable it.
3. The APCOM XML integration is inside `XmlImportEngine::import()`, so both
   reviewed callers share one capture boundary. The engine must expose the same
   bounded byte buffer to XML parsing and hashing; no second URL request or
   source copy is allowed.
4. Every parsed source row contributes only validation facts and privacy-safe
   hashes to a bounded in-memory collector. Existing staging mapping and writes
   remain unchanged.
5. The collector has an implementation-tested hard bound derived from the
   8 MiB evidence limit and the maximum canonical observation size. Reaching
   the bound produces `overflow`, never truncation presented as complete.
6. After traversal, counts, chronology, source identity, qualification facts,
   canonical observations, row fingerprints, and the ordered set fingerprint
   are finalized.
7. One transaction inserts the final header and, only for an exhaustive set,
   its observations in deterministic chunks. It performs no Product, mapping,
   staging, Catalog Sync, cache, job, or event write.
8. The normal import terminal transition remains owned by the importer.

Capture failure does not roll back a successful supplier staging import. The
ImportHistory generation without a valid snapshot header is an explicit gap;
all lifecycle chains crossing it freeze. Operators may investigate the capture
implementation, but they may not backfill the missing evidence from staging.

A parsing failure may insert a frozen final header with zero observations and a
stable failure code. Partial observations are not retained as absence evidence.
If the frozen-header transaction also fails, the missing header is still a
fail-closed gap.

Retries receive a new ImportHistory generation. Retrying finalization for the
same generation is idempotent only when the complete canonical header and set
fingerprints match; otherwise the unique constraint rejects it as conflict.
Concurrent imports cannot share a generation and the existing activity guard
continues to block operational evaluation while an import is active or unknown.

APCOM remains `schedule_enabled=false`. Capture enablement, an import run, an
evidence candidate, and lifecycle preview each require separate authorization.
Super Admin, authorization, queue, scheduler, and Catalog Sync behavior are
outside this integration and remain unchanged.

## Qualification And Completeness

A generation can participate in lifecycle evidence only when all are true:

- one terminal ImportHistory generation and one matching final header exist;
- source traversal was successful and full;
- schema is valid and no truncation or overflow occurred;
- source identity is valid and stable across the selected supplier sequence;
- supplier identity is confirmed;
- authoritative timestamp provenance is present and valid where freshness is
  evaluated;
- total/count reconciliation and deterministic duplicate classification pass;
- `invalid_observation_count` and `rejected_observation_count` are zero; V4
  defines no non-zero tolerance that could safely prove exhaustive absence;
- exact duplicate rows may be counted and collapsed only when their complete
  canonical observation bytes are identical; conflicting duplicates set a
  blocker, while duplicate snapshot fingerprints are detected across
  generations and freeze the later generation;
- source and observation-set fingerprints are present and valid;
- minimum product count and existing maximum product-drop checks pass;
- the generation is comparable with the previous qualified generation;
- no fatal integrity blocker or unknown state exists;
- every ImportHistory generation in the selected interval is accounted for.

Invalid, partial, duplicate, failed, anomalous, missing-header, or unknown
generations freeze the sequence. They neither increment nor reset missing
tracking. Existing V4 thresholds remain unchanged: three consecutive qualified
missing snapshots and at least 48 elapsed hours are required before an offer
may receive a preview-only future-deactivation recommendation.

## No Backfill And Readiness State Machine

The new tables start empty. There is no conversion from `supplier_products`,
ImportHistory messages/context, SupplierImportRun reports, feed items, logs,
caches, or raw files.

Readiness is evaluated per supplier as:

```text
capture_disabled
-> awaiting_first_generation
-> baseline_only
-> collecting_qualified_history
-> evidence_window_ready
```

Any failed, incomplete, unknown, missing-header, identity-drift, chronology,
count-drop, duplicate, or fingerprint gap changes the state to
`history_gap_requires_new_sequence`. The next qualified generation starts a
new sequence; a gap is never skipped.

The first qualified generation is baseline only. Operational evidence is
unavailable until a bounded selection contains at least the V4-required three
consecutive qualified snapshots, covers at least 48 elapsed hours when a
confirmed-missing recommendation is sought, and satisfies freshness and
coverage at the explicit operator-supplied `evaluated_at`. This is a formula,
not a promised calendar date.

## Catalog Sync Separation

Snapshot capture writes only the two new append-only evidence tables in
addition to the importer's existing staging behavior. It does not write a
Product, execute CREATE or UPDATE, link or unlink, apply lifecycle or visibility
recommendations, change a schedule, or call Catalog Sync.

Required defaults remain:

```text
CATALOG_SYNC_CREATE_ENABLED=true
CATALOG_SYNC_UPDATE_ENABLED=false
CATALOG_SYNC_SYNC_ALL_ENABLED=false
CATALOG_SYNC_AUTO_ENABLED=false
```

Evidence persistence is not Catalog Sync and does not authorize supplier import
or automatic supplier-to-catalog behavior.

## Retention And Capacity

The current planning policy retains raw snapshots and detailed technical logs
for 90 days and summarized import runs for 24 months. Immutable lifecycle
evidence must retain at least the maximum V4 evaluation horizon represented in
an approved bundle plus a separately reviewed safety margin. Because the
visibility policy can describe a 24-month cold-archive candidate, the minimum
functional horizon is 24 complete months plus that margin. The margin is not
approved in this phase, so the initial effective retention is indefinite and
the implementation must not automatically delete snapshot evidence. A concrete
cleanup period requires a later privacy, audit, legal, and lifecycle review.

Let:

- `G` be retained generations;
- `O_g` be unique observations in generation `g`;
- `H` be average encoded header bytes including indexes;
- `R` be average encoded observation bytes including indexes.

Estimated storage is:

```text
G * H + sum(O_g * R) + database index overhead
```

The implementation phase must benchmark `H` and `R` with synthetic bounded
fixtures, not real VPS data. Primary reads are supplier/feed plus explicit
ImportHistory ID range, ordered by generation and offer hash. Expected indexes
support that query and uniqueness; no unbounded `all history` command is
allowed. The producer must stream database rows and enforce generation,
observation, output-byte, and sample limits.

Archival or deletion requires a separate dry-run-first design, explicit audit
scope, protection of evidence referenced by closeouts, and approval. Rollback
must never delete already captured immutable history.

## Future Rollout And Rollback

1. Add empty additive tables and restrictive constraints.
2. Add append-only models and repositories.
3. Add the bounded capture service behind a supplier-scoped default-off gate.
4. Add synthetic, MySQL, immutability, concurrency, and zero-regression tests.
5. Obtain independent code and security review.
6. Merge into `main` with green CI.
7. Deploy separately to staging with capture still disabled.
8. Verify schema, permissions, existing imports, staging, and Catalog Sync are
   unchanged.
9. Obtain separate authorization to enable capture for APCOM manual imports.
10. Collect future qualified generations without enabling the APCOM schedule.
11. Verify readiness and gaps read-only.
12. Implement or activate the bounded evidence producer in a separate phase.
13. Obtain human approval for one exact evidence candidate.
14. Run one separately authorized C3D.1 operational preview.
15. Close out through documentation only after zero-mutation proof.

Deployment is never capture activation. Capture activation is never import
authorization. Import completion is never evidence approval.

Rollback disables only the capture gate and leaves imports, staging, Products,
Catalog Sync flags, and captured history untouched. A defective schema or
capture implementation requires a forward fix; captured evidence is not
deleted or rewritten.

## Future Implementation Map

Proposed later files, subject to implementation review:

```text
database/migrations/*_create_supplier_offer_snapshot_generations_table.php
database/migrations/*_create_supplier_offer_snapshot_observations_table.php
app/Models/SupplierOfferSnapshotGeneration.php
app/Models/SupplierOfferSnapshotObservation.php
app/Repositories/Suppliers/ImmutableSupplierOfferSnapshotRepository.php
app/Services/Suppliers/Snapshots/SupplierOfferSnapshotCollector.php
app/Services/Suppliers/Snapshots/SupplierOfferSnapshotCaptureService.php
app/Services/Suppliers/Snapshots/ImportHistorySnapshotSourceAdapter.php
app/Services/Suppliers/Onboarding/OperationalSupplierOfferEvidenceProducer.php
app/Console/Commands/PrepareOperationalSupplierOfferLifecycleEvidence.php
config/supplier_snapshot_capture.php
tests/Feature/SupplierOfferSnapshotPersistenceTest.php
tests/Feature/SupplierOfferSnapshotCaptureTest.php
tests/Feature/OperationalSupplierOfferEvidenceProducerTest.php
tests/Unit/Suppliers/SupplierOfferSnapshotFingerprintTest.php
tests/Feature/SupplierOfferLifecycleDocumentationContractTest.php
docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md
docs/APCOM_OPERATIONAL_OFFER_LIFECYCLE_PREVIEW.md
docs/SUPPLIER_ONBOARDING_FRAMEWORK.md
docs/PHASES.md
docs/ROADMAP.md
```

The implementation should be split into independently reviewable phases:

1. additive schema and immutable repository contract, capture disabled;
2. bounded import capture integration and zero-regression verification;
3. separately authorized APCOM capture enablement and future-history collection;
4. evidence source adapter, producer, and private writer;
5. candidate approval, one operational preview, and documentation closeout.

Migration, capture enablement, evidence generation, and operational closeout
must not share one authorization.

## Non-approval Boundary

This design does not authorize a migration, model, producer, import hook,
feature flag, real evidence candidate, supplier import, APCOM schedule change,
Catalog Sync action, Product mutation, retention cleanup, deployment, or C3D.1
operational preview. Supplier #3 selection must not begin while this prerequisite
remains unresolved.

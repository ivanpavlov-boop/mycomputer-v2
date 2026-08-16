# Immutable Supplier Offer Snapshot Persistence Design

## Status And Scope

Phase 9C.6.5C.3D.1-PRE.A is a documentation-only prerequisite. It resolves the
architecture questions behind `BLOCKED_HISTORICAL_SOURCE_CONTRACT_REQUIRED`,
but it does not add a migration, model, parser, import hook, feature flag,
evidence file, or operational preview. No existing data is qualified by this
design.

The design is supplier-generic where the existing importer already provides a
supplier and feed boundary. APCOM is the first bounded consumer. V1 through V3
remain historical contracts. V4 remains the current semantic authority.

The read-only C3D preview implementation was merged through PR #210 and
deployed at `c22fc9a8dddf3c6778ab0b88e5a50cbc02fe3f21`. This persistence design
is a local documentation-only follow-up under fresh independent review. Its
migration, parser/capture implementation, evidence preparation, operational
execution, and closeout are not approved or implemented.

Read this design with [APCOM Missing Offer Decisions V4](APCOM_MISSING_OFFER_DECISIONS_V4.md),
[APCOM Operational Offer Lifecycle Preview](APCOM_OPERATIONAL_OFFER_LIFECYCLE_PREVIEW.md),
[Supplier Offer Missing Lifecycle Policy](SUPPLIER_OFFER_MISSING_LIFECYCLE_POLICY.md),
[Catalog Product Visibility And Archival Policy](CATALOG_PRODUCT_VISIBILITY_ARCHIVAL_POLICY.md),
and [Supplier Technical Retention Policy](SUPPLIER_TECHNICAL_RETENTION_POLICY.md).

## Existing Boundary

The current application cannot reconstruct qualified lifecycle history:

- `import_histories.id` is an immutable importer-owned attempt marker, but its
  terminal context contains aggregate processed and failed counts only;
- `supplier_import_runs` contains mutable aggregate execution reports;
- `supplier_products` is the mutable current staging projection;
- `product_supplier_offers` is current catalog-offer state, not source history;
- `supplier_feed_items` is mutable raw data without the required qualified
  generation provenance and is not populated by the reviewed XML flow;
- timestamps, logs, caches, current presence, and current payloads are not
  authoritative historical presence or absence evidence.

The future schema therefore starts empty. Existing rows must not be backfilled,
converted, or represented as historical presence, absence, or reappearance.

The reviewed XML execution paths are:

```text
RunSupplierImportJob
-> SupplierImportOrchestrator::execute()
-> XmlImportEngine::import()

ProcessXmlSupplierFeed
-> XmlImportEngine::import()
```

`XmlImportEngine::import()` creates the ImportHistory generation before source
loading, calls `SsrfProtectionService::downloadToTemporaryFile()`, currently
parses that file with `simplexml_load_file()`, maps rows, and writes current
`supplier_products`. `SupplierImportRun` remains an outer mutable report. The
first APCOM capture boundary must therefore be shared by both XML callers and
must begin only after `ImportHistory::startForImport()`.

The current `simplexml_load_file()` and `extractRows()` implementation builds a
complete in-memory XML tree and row array. A capture implementation must not
pretend that this is bounded memory. Before capture can be enabled, a separately
reviewed implementation phase must replace that parser traversal with a
behavior-equivalent streaming traversal over the same downloaded temporary
file. Existing mapping, validation, staging, failure isolation, and import
terminal semantics must remain unchanged and regression-tested.

## Selected Architecture

The future implementation adds one narrowly scoped mutable execution-claim
table, three append-only evidence tables, and reuses `import_histories.id` as
the attempt sequence marker:

1. `supplier_import_execution_claims` owns one stable logical execution across
   queue retry and redelivery. It coordinates an attempt but is not evidence.
2. `supplier_offer_snapshot_generations` stores one immutable final capture
   header for one ImportHistory generation.
3. `supplier_offer_snapshot_enrollments` stores the first immutable enrollment
   of every hashed offer identity in a supplier/source cohort.
4. `supplier_offer_snapshot_observations` stores one physical `present=true` or
   `present=false` observation for every identity enrolled for that generation.

The third enrollment layer is mandatory. Mutable staging can identify cohort
membership at a capture boundary, but it cannot preserve that membership after
a row is removed. An enrolled identity therefore remains in every later
generation in the same supplier/source cohort, even after it disappears from
`supplier_products` or `product_supplier_offers`.

There is no mutable current-snapshot row. A complete header, newly discovered
enrollments, and all observations are inserted atomically after source
traversal. A failed capture may persist one final frozen header without
observations. If final persistence fails, the ImportHistory generation remains
without a header. A missing header is a sequence gap and never means absence.

The header is not updated from `started` to `finished`. It is one final fact.
`import_histories` continues to own import execution state.

## Parent-execution Idempotency Contract

The supplier lock serializes concurrent work, but it does not identify a later
delivery of the same queue payload. The future implementation therefore uses
one `supplier_import_execution_claims` row as the shared parent-execution
contract for `RunSupplierImportJob` and `ProcessXmlSupplierFeed`. Extending only
`ImportJob` is rejected because the orchestrated path creates its ImportJob
inside `SupplierImportOrchestrator::execute()`, after the parent queue job has
already been dispatched.

### Stable logical execution identity

`logical_execution_key` is exactly 64 lowercase hexadecimal ASCII characters,
generated from 32 cryptographically secure random bytes exactly once before
initial dispatch. The column is `char(64)` with binary ASCII comparison and a
global unique constraint. It contains no supplier key, database identifier,
feed URL, credential, path, token or raw supplier identifier and is not an
authentication capability.

For the orchestrated path, `SupplierImportOrchestrator::dispatch()` creates the
`SupplierImportRun` and pending execution claim in one database transaction,
then dispatches `RunSupplierImportJob` after commit with the claim ID and exact
logical key in the serialized job payload. Laravel queue retry and redelivery
therefore preserve that key. Feed, ImportJob and ImportHistory are bound once
under the coordinator lock when execution begins.

For every legacy XML entry point, the explicit operator or scheduler action
creates its ImportJob and a fresh pending execution claim in one transaction,
then dispatches `ProcessXmlSupplierFeed` after commit with the ImportJob ID,
claim ID and exact logical key. Requeuing an ImportJob as a genuinely new
operator action creates a new claim and key; queue retry of the already
dispatched action retains the old claim and key. No `handle()` method generates
a logical key.

### Claim and attempt ownership

Both handlers must enter the common owner-checked
`SupplierImportExecutionCoordinator` before the orchestrator or XML engine.
After the coordinator owns `supplier_import:<supplier_id>`, it locks the claim
row with `SELECT ... FOR UPDATE`, verifies the serialized key, supplier, path
and parent references, and performs one compare-and-set transition. Each
`handle()` invocation creates a separate random attempt token and stores only
its SHA-256 hash. The raw attempt token stays in process memory. The active
attempt may proceed only while it owns both the claim token and the Redis lock;
both are rechecked before staging chunks and final snapshot commit.

The exact claim states are `pending`, `active`, `terminal_qualified`,
`terminal_frozen`, and `terminal_failed`. Only `pending -> active`, an
owner-checked `active -> active` retry takeover after the prior Redis lease is
no longer owned, and `active ->` one terminal state are valid. Terminal state,
terminal reason, bound source fingerprint, ImportJob and ImportHistory are
write-once. A database compare-and-set permits exactly one terminal transition.

Claim behavior is deterministic:

- **Unseen key at dispatch:** insert one `pending` claim. A handler receiving a
  key without that committed claim fails closed before download or import.
- **Active claim owned by another attempt:** Redis-lock acquisition or claim
  token comparison fails; the delivery is retryable and creates no ImportJob,
  ImportHistory, snapshot, staging, Product or Catalog Sync write.
- **Retry after interruption:** after the old Redis lease is no longer owned,
  the same logical key may replace the attempt-token hash and resume the same
  bound ImportJob and ImportHistory. It may never allocate another history.
- **Terminal qualified generation:** return the stored deterministic successful
  no-op result.
- **Terminal frozen generation:** return the stored deterministic frozen no-op
  result.
- **Terminal failed execution:** return the stored deterministic failed result
  without retrying import work.
- **Conflicting retry:** a mismatching key, parent, supplier, feed or bound
  source fingerprint fails closed and cannot become a new generation.
- **Explicit new execution:** a new manual authorization creates a new key and
  claim; only this case may allocate a later ImportHistory generation.

Every terminal delivery checks claim state before feed resolution, source
download, `XmlImportEngine`, ImportJob/ImportHistory allocation or snapshot
work. It does not download again, call `XmlImportEngine`, create another
ImportJob or ImportHistory, insert another header/observation set, alter
chronology, or dispatch Catalog Sync, jobs or events.

### Crash and source-fingerprint recovery

The source file is completely downloaded and incrementally hashed before
streamed row processing begins. If an interruption occurs before a fingerprint
is bound, no source bytes have been accepted for the logical execution and no
staging row has been processed; the same claim may reacquire ownership,
redownload, bind the first valid fingerprint and continue with the same
ImportJob and ImportHistory.

If interruption occurs after the fingerprint is bound but before terminal
finalization, a retry may restart processing only when the newly downloaded
exact bytes produce the identical digest. It reuses the same claim, ImportJob
and ImportHistory. A different digest atomically produces
`terminal_frozen` with the stable privacy-safe reason
`capture_source_fingerprint_conflict`; it does not overwrite the first digest,
does not allocate a generation, and creates no qualified absence evidence. The
operator must authorize a new logical execution with a new key for different
source bytes.

### Execution-claim data dictionary

Proposed additive coordination table: `supplier_import_execution_claims`.
Unlike the three evidence tables, this row is deliberately mutable only through
the owner-checked state machine above.

| Column | Type | Null/default | Purpose and invariant | Privacy |
| --- | --- | --- | --- | --- |
| `id` | unsigned bigint | not null | Surrogate claim key | internal |
| `logical_execution_key` | char(64) ASCII binary | not null | Random stable logical execution identity; globally unique | internal |
| `supplier_id` | unsigned bigint | not null | Exact supplier owner | internal |
| `supplier_feed_id` | unsigned bigint | nullable while orchestrated claim is pending | Bound once before download | sensitive metadata |
| `supplier_import_run_id` | unsigned bigint | nullable | Required orchestrated-path parent | internal |
| `import_job_id` | unsigned bigint | nullable before orchestrated allocation | Legacy parent at dispatch; bound once on orchestrated execution | internal |
| `import_history_id` | unsigned bigint | nullable before generation allocation | The only ImportHistory for this logical execution | internal |
| `execution_path` | varchar(32) ASCII | not null | `orchestrated` or `legacy_xml` | public contract |
| `state` | varchar(32) ASCII | `pending` | Closed state machine above | public contract |
| `active_attempt_token_hash` | char(64) ASCII | nullable | SHA-256 of current in-memory attempt token | pseudonymous |
| `source_fingerprint` | char(64) ASCII | nullable | First accepted exact-byte digest; immutable once set | pseudonymous |
| `terminal_reason_code` | varchar(96) ASCII | nullable | Stable allowlisted reason for terminal frozen/failed | public contract |
| `claimed_at` | char(25) ASCII | nullable | Canonical current-attempt claim instant | operational metadata |
| `terminal_at` | char(25) ASCII | nullable | Canonical terminal instant | operational metadata |
| `created_at`, `updated_at` | timestamps | database managed | Coordination audit only | operational metadata |

An orchestrated claim requires `supplier_import_run_id`; a legacy claim forbids
that parent and requires `import_job_id` at dispatch. Any active claim requires
a feed and ImportJob. A claim may bind at most one ImportHistory. Terminal
qualified/frozen requires that history; terminal failure before source work may
have no history. The implementation must enforce these implications with
database checks plus application validation.

Database uniqueness is deliberately narrow: the logical key is globally
unique; `supplier_import_execution_claims.import_history_id` is unique when
non-null; generation claim and ImportHistory references are each unique; and a
claim row can reach terminal state once. `import_histories.import_job_id` is not
globally unique because the current event/history model and explicit legacy
re-execution may legitimately associate multiple histories with one ImportJob.

The later implementation is not acceptable without focused tests proving:

- two sequential deliveries with one key create one claim, ImportHistory and
  final header;
- concurrent deliveries with one key permit one active owner and one final
  header;
- delivery after terminal qualified, frozen and failed states is a deterministic
  no-op/terminal result with no download or importer call;
- identical bytes after interruption reuse the same claim, ImportJob,
  ImportHistory and generation;
- different bytes for one key freeze without replacing the first fingerprint
  or creating qualified evidence;
- two explicitly authorized keys create two ordered logical executions;
- duplicate legacy and duplicate orchestrated deliveries obey the same state
  machine;
- interruption before fingerprint can bind the first later verified digest;
- interruption after fingerprint but before finalization cannot duplicate
  chronology; and
- every terminal logical execution has at most one final header and exhaustive
  observation set.

## Cohort Enrollment Contract

Enrollment is privacy-safe, monotonic, and source-scoped.

At finalization of each capture-capable generation, the future coordinator
forms a membership set from:

- every valid source offer observed in the streamed input;
- every valid current `supplier_products` identity required by the operational
  preview for that supplier;
- every valid current `product_supplier_offers` identity required by that
  preview; and
- all earlier immutable enrollments in the same supplier/source scope.

Only the canonical domain-separated `supplier_sku_hash` is persisted. Raw SKU,
EAN, MPN, source record, name, URL, or path is prohibited. An application row
without one unambiguous canonical supplier SKU is a capture integrity blocker;
the producer must not guess its identity.

The first capture transaction enrolls the current application cohort and all
valid source-only identities with the current ImportHistory ID as their
effective generation. Later captures enroll newly observed or newly required
identities in the same way. The provenance code records whether first
enrollment came from `initial_application_cohort`, `application_cohort_entry`,
`source_observation`, or both application and source in the same generation.
This includes the documented 86-identity APCOM staging-only cohort, including
identities that are absent from the first future captured source and therefore
do not need to reappear before they can begin accumulating explicit absence
evidence.

Enrollment never claims history before its effective generation. An identity
first enrolled from current application state and absent from that generation's
source receives a physical `present=false` observation beginning with that
generation only. An identity first discovered in the source receives
`present=true`. Deleting mutable staging later cannot erase either enrollment
or its subsequent absence history.

Every new enrollment changes the cohort fingerprint and starts a new cohort
epoch. This is required because the current V1 reader requires the exact same
identity set in every selected snapshot. A V1 evidence window may include only
qualified comparable generations from one unchanged cohort epoch. It must not
synthesize false observations before an identity was enrolled.

## Generation Header Data Dictionary

Proposed additive table: `supplier_offer_snapshot_generations`.

| Column | Type | Null/default | Purpose and invariant | Privacy |
| --- | --- | --- | --- | --- |
| `id` | unsigned bigint | not null | Surrogate storage key; never emitted | internal |
| `supplier_id` | unsigned bigint | not null | Supplier ownership copied from ImportHistory | internal |
| `supplier_key` | varchar(96) ASCII | not null | Versioned canonical supplier key at capture | public contract |
| `supplier_feed_id` | unsigned bigint | not null | Exact feed ownership | sensitive metadata |
| `supplier_import_execution_claim_id` | unsigned bigint | not null | One owning logical execution | internal |
| `import_history_id` | unsigned bigint | not null | Immutable importer generation identity | internal |
| `predecessor_snapshot_generation_id` | unsigned bigint | nullable | Immediately preceding valid header used for comparison | internal |
| `schema_version` | varchar(96) ASCII | not null | Persistence schema version | public contract |
| `producer_version` | varchar(96) ASCII | not null | Capture implementation contract | public contract |
| `qualification_policy_key` | varchar(96) ASCII | not null | Exact qualification policy | public contract |
| `capture_integrity_policy_key` | varchar(96) ASCII | not null | Exact capture-integrity policy | public contract |
| `policy_versions` | JSON | not null | Canonically ordered complete policy map | public contract |
| `freshness_policy_key` | varchar(96) ASCII | nullable | Approved supplier freshness key | public contract |
| `freshness_max_age_hours` | unsigned integer | nullable | Captured approved maximum age | policy metadata |
| `freshness_policy_approved` | boolean | false | Approval fact for V1 projection | public contract |
| `source_identity` | varchar(128) ASCII | not null | Exact validated opaque identity under the contract below | restricted metadata |
| `source_fingerprint` | char(64) ASCII | not null | SHA-256 of exact downloaded bytes consumed | pseudonymous |
| `captured_at` | char(25) ASCII | not null | Canonical capture completion instant | operational metadata |
| `authoritative_snapshot_at` | char(25) ASCII | nullable | Supplier-authoritative instant only when genuine | operational metadata |
| `capture_started_at` | char(25) ASCII | not null | Canonical source traversal start | operational metadata |
| `capture_completed_at` | char(25) ASCII | not null | Canonical source traversal end | operational metadata |
| `capture_outcome` | varchar(48) ASCII | not null | `completed`, `completed_with_errors`, `failed`, `incomplete`, or `overflow` | public contract |
| `capture_failure_reason_code` | varchar(96) ASCII | nullable | Stable privacy-safe capture code | public contract |
| `qualification_state` | varchar(48) ASCII | not null | `qualified_baseline`, `qualified_comparable`, or `frozen` | public contract |
| `qualification_reason_codes` | JSON | not null | Sorted unique closed reason-code list | public contract |
| `successful` | boolean | false | Import/capture success primitive | public contract |
| `full` | boolean | false | Exhaustive traversal primitive | public contract |
| `schema_valid` | boolean | false | Required source schema passed | public contract |
| `truncated` | boolean | false | Source or collector truncation occurred | public contract |
| `fatal_integrity_blocker` | boolean | false | Integrity failure primitive | public contract |
| `supplier_identity_confirmed` | boolean | false | Source belongs to expected supplier | public contract |
| `comparable` | boolean | false | Same source semantics and cohort as predecessor | public contract |
| `total_observed_count` | unsigned integer | 0 | Source rows before deduplication | aggregate |
| `valid_observation_count` | unsigned integer | 0 | Unique physically present source offers | aggregate |
| `invalid_observation_count` | unsigned integer | 0 | Rows failing field validation | aggregate |
| `rejected_observation_count` | unsigned integer | 0 | Rows rejected by scope/policy | aggregate |
| `duplicate_observation_count` | unsigned integer | 0 | Canonically identical duplicate source rows | aggregate |
| `enrolled_observation_count` | unsigned integer | 0 | Full physical cohort observation count | aggregate |
| `minimum_product_count` | unsigned integer | not null | Captured supplier threshold | policy metadata |
| `product_drop_percent` | decimal(9,6) | nullable | Drop from predecessor; null for baseline | aggregate |
| `maximum_product_drop_percent` | unsigned tinyint | not null | Captured supplier threshold | policy metadata |
| `cohort_fingerprint` | char(64) ASCII | nullable | Hash of sorted enrolled identities effective here | pseudonymous |
| `observation_set_fingerprint` | char(64) ASCII | nullable | Hash of sorted physical observation fingerprints | pseudonymous |
| `generation_fingerprint` | char(64) ASCII | not null | Hash of the complete canonical final header contract | pseudonymous |
| `created_at` | timestamp | database current time | Storage audit time only | operational metadata |

There is no `updated_at`. `supplier_id`, `supplier_feed_id`, execution claim,
and `import_history_id` must agree. A freshness key, age, and approval form one
complete valid tuple or remain absent/false. Counts are non-negative and
reconcile under the capture policy.

`qualified_baseline` requires all non-comparative integrity gates, a complete
cohort, `comparable=false`, a null predecessor when no usable sequence exists,
and `product_drop_percent=null`. `qualified_comparable` additionally requires
an exact predecessor, an unchanged cohort fingerprint, `comparable=true`, and
a non-null passing product-drop value. Any reason code produces `frozen`.

## Enrollment Data Dictionary

Proposed additive table: `supplier_offer_snapshot_enrollments`.

| Column | Type | Null/default | Purpose and invariant | Privacy |
| --- | --- | --- | --- | --- |
| `id` | unsigned bigint | not null | Surrogate storage key | internal |
| `supplier_id` | unsigned bigint | not null | Supplier cohort owner | internal |
| `supplier_feed_id` | unsigned bigint | not null | Feed provenance at first enrollment | sensitive metadata |
| `source_identity` | varchar(128) ASCII | not null | Exact opaque cohort identity | restricted metadata |
| `supplier_sku_hash` | char(64) ASCII | not null | Domain-separated offer identity | pseudonymous |
| `effective_import_history_id` | unsigned bigint | not null | First generation where membership is valid | internal |
| `enrollment_source` | varchar(48) ASCII | not null | Closed provenance code described above | public contract |
| `enrollment_fingerprint` | char(64) ASCII | not null | Hash of canonical privacy-safe enrollment fields | pseudonymous |
| `enrolled_at` | char(25) ASCII | not null | Canonical capture instant of first enrollment | operational metadata |
| `created_at` | timestamp | database current time | Storage audit time only | operational metadata |

There is no `updated_at`. The first enrollment for
(`supplier_id`, `source_identity`, `supplier_sku_hash`) is immutable and unique.
Retries may accept the already committed row only when its complete canonical
fingerprint is identical. A later generation cannot change provenance,
effective generation, or enrollment time.

## Observation Data Dictionary

Proposed additive table: `supplier_offer_snapshot_observations`.

| Column | Type | Null/default | Purpose and invariant | Privacy |
| --- | --- | --- | --- | --- |
| `id` | unsigned bigint | not null | Surrogate storage key | internal |
| `snapshot_generation_id` | unsigned bigint | not null | Immutable owning header | internal |
| `snapshot_enrollment_id` | unsigned bigint | not null | Exact immutable cohort enrollment observed | internal |
| `supplier_sku_hash` | char(64) ASCII | not null | Enrolled offer identity | pseudonymous |
| `present` | boolean | not null | Physical source presence fact | public contract |
| `price` | decimal(12,2) | nullable | Canonical supplier price when present | commercial restricted |
| `currency` | char(3) ASCII | nullable | ISO 4217 currency when present | commercial metadata |
| `raw_quantity_observed` | unsigned integer | nullable | Bounded internal stock observation | commercial restricted |
| `eol_flag` | unsigned tinyint | nullable | Validated 0/1 lifecycle evidence | restricted metadata |
| `canonical_public_status` | varchar(48) ASCII | nullable | Versioned canonical availability | public contract |
| `supplier_mapper_valid` | boolean | false | Approved mapper accepted observation | public contract |
| `exact_supplier_sku_match` | boolean | false | Identity validation result | public contract |
| `identifier_conflict` | boolean | false | Identity conflict fact | public contract |
| `blocking_validation_issue` | boolean | false | Stable blocker fact | public contract |
| `duplicate_offer` | boolean | false | Duplicate classification | public contract |
| `reliable_manufacturer_mpn_hash` | char(64) ASCII | nullable | Reserved V1 field; null for APCOM V4 | pseudonymous |
| `observation_fingerprint` | char(64) ASCII | not null | Hash of canonical observation fields | pseudonymous |
| `created_at` | timestamp | database current time | Storage audit time only | operational metadata |

There is no `updated_at`. Every finalized complete generation stores exactly
one row for every enrollment effective by that generation. The enrollment ID,
generation scope and repeated offer hash must reconcile in the final
transaction. `present=true` rows carry validated source semantics.
`present=false` rows must have null semantic values, false mapper and
exact-match flags, and false conflict/blocker/duplicate flags. No absence row
is inferred from a partial or frozen traversal.

## Exact Index And Foreign-key Contract

The future MySQL 8.4 additive migrations must create the exact named indexes
below. A foreign key is accepted only when its documented supporting index has
the referenced child column as the leftmost column. The implementation must not
depend on an implicit MySQL-created index or implicit name.

### `supplier_import_execution_claims`

| Index | Ordered columns | Unique | Supported boundary |
| --- | --- | --- | --- |
| `PRIMARY` | (`id`) | yes | claim identity |
| `uq_import_execution_claim_logical_key` | (`logical_execution_key`) | yes | exact retry/redelivery lookup and one claim per logical execution |
| `ix_import_execution_claim_supplier` | (`supplier_id`) | no | `fk_import_execution_claim_supplier` to `suppliers.id` |
| `ix_import_execution_claim_feed` | (`supplier_feed_id`) | no | `fk_import_execution_claim_feed` to `supplier_feeds.id` |
| `ix_import_execution_claim_run` | (`supplier_import_run_id`) | no | `fk_import_execution_claim_run` to `supplier_import_runs.id` |
| `ix_import_execution_claim_job` | (`import_job_id`) | no | `fk_import_execution_claim_job` to `import_jobs.id`; multiple explicitly authorized claims may reference one reusable legacy job |
| `uq_import_execution_claim_history` | (`import_history_id`) | yes | `fk_import_execution_claim_history` to `import_histories.id` and at most one claim per history |
| `ix_import_execution_claim_scope_state` | (`supplier_id`, `supplier_feed_id`, `state`, `id`) | no | bounded active/terminal claim inspection for one supplier/feed |

All five claim parent foreign keys use `RESTRICT`. The logical-key query is
`WHERE logical_execution_key = ?`. Active-state inspection is
`WHERE supplier_id = ? AND supplier_feed_id = ? AND state IN (...) ORDER BY id`.

### `supplier_offer_snapshot_generations`

| Index | Ordered columns | Unique | Supported boundary |
| --- | --- | --- | --- |
| `PRIMARY` | (`id`) | yes | generation identity |
| `uq_snapshot_generation_execution_claim` | (`supplier_import_execution_claim_id`) | yes | `fk_snapshot_generation_execution_claim` to `supplier_import_execution_claims.id`; at most one final header per logical execution |
| `uq_snapshot_generation_import_history` | (`import_history_id`) | yes | `fk_snapshot_generation_import_history` to `import_histories.id`; at most one final header per history |
| `ix_snapshot_generation_feed` | (`supplier_feed_id`) | no | `fk_snapshot_generation_feed` to `supplier_feeds.id` |
| `ix_snapshot_generation_feed_import` | (`supplier_id`, `supplier_feed_id`, `import_history_id`) | no | `fk_snapshot_generation_supplier` to `suppliers.id` by left prefix and exact supplier/feed/history ownership lookup |
| `ix_snapshot_generation_scope_order` | (`supplier_id`, `source_identity`, `import_history_id`) | no | exact source sequence ordered by ImportHistory ID |
| `ix_snapshot_generation_qualified_range` | (`supplier_id`, `source_identity`, `qualification_state`, `import_history_id`) | no | bounded qualified-window selection in generation order |
| `ix_snapshot_generation_retention` | (`created_at`, `id`) | no | later retention-candidate reporting only |
| `ix_snapshot_generation_predecessor` | (`predecessor_snapshot_generation_id`) | no | `fk_snapshot_generation_predecessor` self-reference |

All generation foreign keys use `RESTRICT`.

### `supplier_offer_snapshot_enrollments`

| Index | Ordered columns | Unique | Supported boundary |
| --- | --- | --- | --- |
| `PRIMARY` | (`id`) | yes | enrollment identity |
| `uq_snapshot_enrollment_scope_offer` | (`supplier_id`, `source_identity`, `supplier_sku_hash`) | yes | `fk_snapshot_enrollment_supplier` to `suppliers.id` by left prefix and first enrollment per scope/offer |
| `ix_snapshot_enrollment_feed` | (`supplier_feed_id`, `effective_import_history_id`) | no | `fk_snapshot_enrollment_feed` to `supplier_feeds.id` and feed/history ownership lookup |
| `ix_snapshot_enrollment_effective_history` | (`effective_import_history_id`) | no | `fk_snapshot_enrollment_effective_history` to `import_histories.id` |
| `ix_snapshot_enrollment_effective` | (`supplier_id`, `source_identity`, `effective_import_history_id`, `supplier_sku_hash`) | no | `WHERE supplier_id = ? AND source_identity = ? AND effective_import_history_id <= ? ORDER BY effective_import_history_id, supplier_sku_hash` |

All enrollment foreign keys use `RESTRICT`.

### `supplier_offer_snapshot_observations`

| Index | Ordered columns | Unique | Supported boundary |
| --- | --- | --- | --- |
| `PRIMARY` | (`id`) | yes | observation identity |
| `uq_snapshot_observation_generation_enrollment` | (`snapshot_generation_id`, `snapshot_enrollment_id`) | yes | `fk_snapshot_observation_generation` to generation by left prefix and one observation per enrolled identity per generation |
| `uq_snapshot_observation_generation_offer` | (`snapshot_generation_id`, `supplier_sku_hash`) | yes | one physical offer-hash fact per generation and generation traversal |
| `ix_snapshot_observation_enrollment_history` | (`snapshot_enrollment_id`, `snapshot_generation_id`) | no | `fk_snapshot_observation_enrollment` to enrollment and bounded identity history |
| `ix_snapshot_observation_offer_history` | (`supplier_sku_hash`, `snapshot_generation_id`) | no | bounded offer-hash history in generation order |

Both observation foreign keys use `RESTRICT`.

### Existing `import_histories` additive range index

A new future additive migration must create non-unique
`ix_import_history_supplier_id` on (`supplier_id`, `id`). It supports
`WHERE supplier_id = ? AND id > ? AND id <= ? ORDER BY id` for supplier-scoped
generation-gap traversal. The historical
`2026_06_07_121751_3_create_import_histories_table.php` migration must not be
edited. Its existing (`supplier_id`, `created_at`) index does not replace the
required (`supplier_id`, `id`) left-prefix/range access pattern.

Exact range reads must be supplier/source bounded, ImportHistory ordered, and
limited by generation count, observation count, and encoded output bytes. The
producer must never execute an unbounded all-history read. The retention index
supports later candidate reporting only; this phase authorizes no deletion.

Supplier and source columns have low-to-moderate cardinality and lead bounded
scope/range indexes; monotonic ImportHistory IDs then provide ordering. Offer
hashes have high cardinality and lead identity-history lookup, while generation
ID leads the high-fan-out traversal of all cohort observations. Retry
idempotency comes from the unique logical execution claim, not from global
ImportHistory or `import_job_id` uniqueness. The predecessor and every parent
column have an explicit left-prefix index supporting their `RESTRICT` foreign
key.

A later migration PR must include MySQL migration tests and representative
`EXPLAIN` assertions for every access pattern on empty, small and populated
synthetic databases. The expected named key must be selected; access type must
be `const`, `eq_ref`, `ref` or bounded `range`; no unbounded full-table scan is
accepted; estimated rows must remain bounded by the selected
supplier/feed/generation interval; and any filesort or temporary table must be
explicitly justified and bounded.

The migration must add `CHECK` constraints for closed codes, boolean domains,
fingerprint shapes, timestamps, count reconciliation, baseline/comparable
implications, and the null semantics of absent observations. Application
validation and reader reconciliation remain mandatory because cross-row set
equality cannot be expressed completely in one row constraint.

## Source Identity Contract

The persistence source identity is an application-owned opaque logical-source
identifier. It is not a feed URL, filesystem path, container path, credential,
or supplier-provided label.

The future snapshot-specific value object must first call the existing
`OperationalSupplierSourceIdentity::validate()` unchanged, then enforce this
stricter exact grammar without trimming, case-folding, Unicode normalization,
or replacement:

```text
^snapshot-source-v1:[a-z0-9]+(?:[._-][a-z0-9]+)*(?::[a-z0-9]+(?:[._-][a-z0-9]+)*)*$
```

The identity is ASCII and at most 128 bytes. Therefore its byte and code-point
limits are identical. Valid examples are:

```text
snapshot-source-v1:apcom:primary-stock-price
snapshot-source-v1:synthetic:fixture-a
```

Invalid forms include empty components, whitespace, control characters,
slashes, backslashes, drive prefixes, URI scheme separators, leading/trailing
punctuation, uppercase characters, and values longer than 128 bytes. Invalid
input blocks capture before any evidence row is inserted. The existing broader
V1 source-identity validator and its callers are not changed by this design.

Non-sensitive invalid examples are:

```text
snapshot-source-v1:
snapshot-source-v1:feed data
snapshot-source-v1:Uppercase
snapshot-source-v1:trailing-
[invalid: contains URI scheme separator]
[invalid: contains ASCII SOLIDUS U+002F]
[invalid: contains ASCII REVERSE SOLIDUS U+005C]
[invalid: begins with a drive designator]
[invalid: begins with a UNC prefix]
```

## Cryptographic Contract

The design reuses `OperationalSupplierOfferIdentityHasher` and
`CanonicalOnboardingData`; it does not invent a second identity algorithm.

- Supplier keys use the existing lowercase/trim behavior.
- Supplier SKU identity is exactly SHA-256 of
  `supplier-offer-lifecycle-operational-preview-v1|supplier_sku|<supplier>|<sku>`
  through `OperationalSupplierOfferIdentityHasher::supplierSku()`.
- A product reference uses the existing `product()` domain only where the
  current evidence contract requires it.
- Observation fingerprints use `sample()` with bucket
  `snapshot_observation_v1` and canonical semantic fields, including physical
  `present`, excluding storage keys and timestamps.
- Enrollment fingerprints use `sample()` with bucket
  `snapshot_enrollment_v1` and canonical supplier key, source identity, offer
  hash, effective ImportHistory ID, and provenance code.
- Cohort fingerprints use `sample()` with bucket `snapshot_cohort_v1` and the
  ordered enrollment hashes effective for the generation.
- Observation-set fingerprints use `sample()` with bucket
  `snapshot_observation_set_v1` and ordered observation fingerprints.
- Evidence `snapshot_id` uses `sample()` with bucket `snapshot_generation_v1`
  and canonical supplier key plus ImportHistory ID.
- Generation fingerprints use `sample()` with bucket
  `snapshot_generation_header_v1` and every canonical final header field,
  including policy keys, high-level state, sorted reason-code list, counts,
  chronology, cohort fingerprint and observation-set fingerprint, while
  excluding storage ID, `generation_fingerprint`, and `created_at`.
- Source fingerprints are lowercase SHA-256 of exact bytes in the downloaded
  temporary source file. They are never derived from source identity, path, or
  URL.

APCOM V4 keeps `reliable_manufacturer_mpn_hash` null because there is no
approved MPN domain. A future supplier requiring MPN evidence needs a separate
versioned contract. The hashes are pseudonymous, not anonymous, and remain
restricted operational data. No secret or keyed hash is introduced.

Any equal offer hash with non-identical canonical identity input, or equal row
fingerprint with non-identical canonical row input, is an integrity conflict.
The generation freezes. Raw values must not be logged to diagnose it.

## Append-only Enforcement

The future migration and models must enforce:

- no UPDATE or DELETE path for any of the three tables;
- MySQL `BEFORE UPDATE` and `BEFORE DELETE` guards tested independently of
  model behavior;
- no mass-assignment mutation surface;
- insert methods available only through the immutable capture repository;
- model delete, force-delete, increment, decrement, and touch rejected;
- no `CASCADE` or `SET NULL`; all parent relationships use `RESTRICT`;
- one final generation per execution claim;
- one final generation per ImportHistory;
- one first enrollment per supplier/source/offer hash;
- one physical observation per generation/offer hash;
- complete final rows committed in one transaction;
- no state transition represented by updating a header;
- no automatic prune, retention job, or admin mutation resource.

Direct query-builder writes remain an implementation-review concern. Where
deployment permits table-level grants, the runtime database user should not
receive ordinary UPDATE or DELETE grants for these tables.

## Bounded Capture And Temporary State

The future integration is an additive observer of one authorized import. It is
not a second feed request and does not retain raw source data.

1. The dispatch boundary has already persisted the stable execution claim and
   serialized its logical key with the parent job.
2. The common supplier import execution coordinator acquires the supplier lock,
   locks and claims that exact row, and checks terminal state before any import
   allocation or source work.
3. The coordinator reuses the claim's bound ImportJob and ImportHistory or calls
   `ImportHistory::startForImport()` exactly once and binds the resulting
   generation to the claim. A separate default-off gate determines whether
   snapshot capture is attempted.
4. `SsrfProtectionService::downloadToTemporaryFile()` downloads once to its
   restricted system temporary file.
5. The process opens the mode-0600 file without following links, records its
   file identity and size, and hashes its exact bytes incrementally.
6. A behavior-equivalent streaming XML parser traverses that same restricted
   file while its identity is held. Before deletion, a second bounded local
   hash pass must reproduce the first digest and the file identity/size must be
   unchanged. Any mismatch freezes capture. This is not a second fetch or
   download and proves that parsing and the stored fingerprint refer to the
   same downloaded bytes.
7. The streaming parser consumes the file without a complete XML tree;
   the existing complete SimpleXML tree and extracted row array must not remain
   in the capture-enabled path.
8. Existing mapping, validation, and staging writes execute for each streamed
   row exactly as before.
9. The observer writes only fixed-size privacy-safe canonical records to a
   mode-0600 system temporary spool. It never writes raw identifiers, XML,
   credentials, URLs, paths, names, or payloads.
10. The spool has explicit row and byte ceilings derived from the approved
   maximum import row count and canonical observation size. It introduces no
   smaller source-file limit than the authorized importer. Exceeding a capture
   ceiling yields
   `overflow`; no prefix is represented as complete.
11. Finalization uses bounded external sort/deduplication and a streamed merge
   with the immutable enrollment query. Memory remains bounded by configured
   chunk size, not source or cohort size.
12. One database transaction inserts new enrollments, the final header, and
    deterministic chunks of exhaustive physical observations, then performs
    the owner-checked terminal claim transition.
13. Both source temporary file and privacy-safe spool are removed in `finally`
    on success, failure, signal-driven worker termination where PHP cleanup
    runs, and repository retry. A startup janitor may remove only stale files
    bearing a capture-specific random prefix and correct owner/mode; it is not
    evidence and must never inspect or log contents.

The observer does not use Laravel cache, session, queue payloads, application
storage, or logs as temporary evidence. It does not hold the complete source,
cohort, or observation set in memory. It performs no second HTTP request and no
second source download.

Capture-only failures must be caught after staging semantics are known. They
must not convert an otherwise successful supplier staging import into failure.
The result is a frozen header when final facts are safe, or a missing-header
gap when they are not. Partial observations are never committed.

## Supplier Concurrency Contract

The existing orchestration lock does not cover `ProcessXmlSupplierFeed` and is
not a sufficient boundary. The future implementation must introduce one
`SupplierImportExecutionCoordinator` used by both job handlers before either
caller enters the orchestrator or XML engine. The orchestrator's current lock
ownership moves into that coordinator; its internal
`SupplierImportOrchestrator::execute()` body must not reacquire the same lock.

`RunSupplierImportJob::handle()` loads the run/supplier and calls the
coordinator around a lock-already-held orchestrator execution method.
`ProcessXmlSupplierFeed::handle()` loads the ImportJob/supplier and calls the
same coordinator around `XmlImportEngine::import()`. Direct calls to either
lock-already-held method are prohibited outside the coordinator and covered by
call-site tests. The existing `SupplierImportExecutionLock` is the one owner
wrapper; no second lock object is nested.

The common Laravel cache-lock key reuses the project's existing supplier import
namespace:

```text
supplier_import:<supplier_id>
```

The coordinator acquires `Cache::lock(key, 4200)->get()` before
`ImportHistory::startForImport()` and holds the returned owner token until
snapshot finalization and the import terminal transition are complete. The
4,200-second lease is the maximum 3,600-second import job timeout plus a
600-second termination grace. It releases only through owner-checked
`release()` in `finally`; `forceRelease()` is prohibited. The cache backend must
be the deployment's atomic Redis lock store. Capture enablement fails closed on
another backend or unavailable ownership checks. The implementation verifies
`isOwnedByCurrentProcess()` before each staging chunk and again before snapshot
commit. Loss or unknown ownership aborts before further writes and prevents a
qualified header.

Lock contention does not authorize an overlapping import or capture. The
orchestrated path retains its stable skipped-run report; the legacy queued path
throws a stable retryable lock-unavailable failure. Neither path creates an
ImportHistory or performs staging, snapshot, Product, or Catalog Sync writes.
`force=true` may bypass the existing dispatch pre-check but may not release or
bypass a lock owned by another process. Before source download, the coordinator
also requires the existing ImportHistory activity inspector to report no other
active or unknown attempt for that supplier. This covers a stale
pre-coordinator worker conservatively. Capture activation is prohibited until
an implementation audit proves that all real XML callers use this boundary.

A normal exit releases the owner lock. A worker crash leaves the lock held only
until the 4,200-second lease expires, which is longer than the enforced job
timeout. A queue retry cannot enter while the old lease is owned. After expiry,
the same serialized logical key may take over the active claim and reuse its
bound ImportJob and ImportHistory under the source-fingerprint recovery rules;
it may not start a second generation. A genuinely new authorized execution has
a new key and may allocate the next ImportHistory.

Ordering is by `import_histories.id`, never completion timestamp. A comparable
generation may reference only the immediately preceding accounted generation
in the same supplier/source sequence. Every intervening ImportHistory ID must
have one terminal capture header. A terminal duplicate delivery is a no-op and
does not create an intervening generation. Missing headers after terminal
failure, unrecoverable worker crashes, lost lock ownership, unknown activity,
and any pre-coordinator overlap break the sequence. The next structurally
complete uncontended generation becomes a new baseline. With the common lock
intact, same-supplier completion order cannot differ from ImportHistory order.

The lock permits imports for different suppliers to proceed concurrently. It
does not call Catalog Sync, scheduler activation, or Product mutation. The
existing activity inspector remains an evaluation-time guard in addition to,
not instead of, the capture lock.

## Qualification State And Reason Projection

The persisted high-level state is deterministic:

```text
qualification_reason_codes is non-empty -> frozen
no reasons and no usable predecessor     -> qualified_baseline
no reasons and passing predecessor       -> qualified_comparable
```

There is no precedence among failures. The repository stores the sorted unique
set of every applicable known lowercase snake-case reason as a canonical JSON
array with no insignificant whitespace. The capture-integrity policy owns
capture and cohort reasons. The existing V4 qualification policy owns its
current snapshot reasons. Their union is covered by the immutable generation
fingerprint.

Required capture reason codes include:

```text
capture_overflow
capture_truncated
capture_invalid_observation
capture_rejected_observation
capture_identity_conflict
capture_duplicate_conflict
capture_cohort_incomplete
capture_cohort_changed
capture_generation_gap
capture_source_identity_invalid
capture_source_fingerprint_invalid
capture_observation_fingerprint_conflict
capture_concurrent_import_activity
capture_unknown_activity
capture_persistence_failure
```

The exact current V4 policy reason codes remain owned by
`SupplierSnapshotQualificationPolicy`; this design does not rename them.
Zero invalid and rejected observations are capture-integrity requirements for
exhaustive absence, not a claim that V4 already defines those counters.
They are deliberately stricter than V4 because one rejected source row could
be the supposedly absent offer. Either non-zero counter freezes readiness under
`supplier-snapshot-capture-integrity-policy-v1`; it does not change the V4
missing threshold.

An unknown reason code can never be stored on a qualified row. The repository
maps it to the known privacy-safe `capture_unknown_integrity_reason` and freezes
the generation without persisting the unknown value. A reason-code allowlist,
policy key, and projection test are required before implementation.

The evidence adapter recomputes V4 qualification from stored primitive fields
through the existing policy and requires agreement with the stored result. It
does not project capture-only counters as invented V1 fields. A
`qualified_baseline` is an integrity-qualified persistence baseline but is not
emitted as a qualified V1 lifecycle snapshot because the current V1 contract
requires `comparable=true`. Only `qualified_comparable` generations map to V1
qualification booleans that may participate in missing/reappearance tracking.

## Baseline, Comparison, And Gap Rules

The first structurally complete generation in a new supplier/source/cohort
epoch is `qualified_baseline` when all non-comparative gates pass:

- `predecessor_snapshot_generation_id` is null;
- `comparable=false`;
- `product_drop_percent=null`;
- the complete physical cohort and both set fingerprints are present;
- minimum-count and all non-comparative integrity checks pass.

The next generation is comparable only when:

- no ImportHistory generation is missing between it and the baseline or prior
  comparable generation;
- source identity, producer/schema/policy semantics, and cohort fingerprint
  are exactly equal;
- the predecessor is `qualified_baseline` or `qualified_comparable`;
- current and predecessor counts are non-zero and reconciled; and
- `max(0, ((previous_count - current_count) / previous_count) * 100)` rounded
  to the policy's six-decimal contract does not exceed the stored threshold.

A cohort expansion, source identity change, policy-semantic change, failed or
frozen generation, missing header, overlap, chronology ambiguity, or
fingerprint conflict ends the epoch. The next complete generation is a new
baseline. A gap is never skipped and never interpreted as absence.

V4's runtime rule still says a frozen snapshot neither increments nor resets a
lifecycle state. PRE.A applies a stricter evidence-readiness boundary: it keeps
all prior facts immutable but refuses to bridge an unprovable capture or cohort
gap into a new operational evidence candidate. Requiring a new baseline after
the gap is therefore a versioned capture-integrity decision, not a changed V4
missing counter or an invented extra absence.

## Projection Into The Existing V1 Evidence Contract

The later adapter must project a bounded selection into the exact
`supplier-offer-lifecycle-operational-evidence-v1` schema. It must not change
that reader silently.

| V1 field | Immutable source |
| --- | --- |
| `snapshot_id` | `sample('snapshot_generation_v1', CanonicalOnboardingData::encode([...]))` using stored supplier key and ImportHistory ID |
| `supplier` | stored canonical `supplier_key` |
| `source_identity` | exact stored opaque identity |
| `captured_at` | stored `captured_at` |
| `authoritative_snapshot_at` | stored authoritative timestamp |
| `fingerprint` | stored exact-byte `source_fingerprint` |
| `status` | stored `capture_outcome` |
| qualification booleans | stored primitives, permitted only from `qualified_comparable` |
| `product_count` | stored `valid_observation_count` |
| count/drop thresholds | stored threshold and comparison values |
| observations | stored physical observations in supplier-SKU-hash order |

The selected generations must all be `qualified_comparable`, have identical
supplier, source identity, schema/producer/policy versions, freshness contract,
and cohort fingerprint, and contain exactly the same physical offer-hash set.
The adapter verifies each set fingerprint and count before emitting data. It
never forms a union that invents pre-enrollment history and never fills a gap
from mutable staging.

An absent physical row in a supposedly complete generation is
`capture_cohort_incomplete`, not implicit `present=false`. Optional V1
`product_lifecycle_evidence` remains a separately fingerprinted read-only
catalog boundary and never creates a Product foreign key or mutation here.

## Exact V4 Window Counting

V4, `SupplierOfferLifecyclePolicy`, and
`OperationalSupplierOfferLifecyclePreviewService` require exactly three
consecutive qualified snapshots in which the same offer is absent, plus at
least 48 elapsed hours from the first qualified absence. This design does not
change that threshold.

Because the current V1 contract requires `comparable=true`, the persistence
baseline is not one of those three V4 snapshots. The minimum sequence is:

```text
qualified_baseline
qualified_comparable absence 1  <- starts the 48-hour clock
qualified_comparable absence 2
qualified_comparable absence 3  <- recommendation eligible only if >=48h
```

This is baseline plus three, not four V4 absences. The baseline is the required
comparison anchor and is not counted by missing tracking. `captured_at` is the
chronology used by the current lifecycle service; `authoritative_snapshot_at`
is independently required for freshness and must not replace missing-duration
chronology.

An identity enrolled in the baseline has no history before that baseline. If
it is physically absent there, that fact is retained but does not start the V4
counter. An identity enrolled in a later generation changes the cohort epoch;
that generation becomes the next baseline, and three later comparable absences
are required. No mutable current row or pre-enrollment timestamp can shorten
the sequence.

## Multi-supplier And Alternative-offer Boundary

Enrollment and readiness are evaluated independently for each canonical
supplier/source scope. One ready APCOM sequence does not prove stability of an
alternative supplier offer.

The later producer may emit an offer-level APCOM candidate only for the exact
APCOM identity with a ready immutable window. A product-level lifecycle
recommendation remains blocked unless every supplier identity required by the
current product-level preview has its own compatible ready window and the
existing alternative-supplier stability checks pass. Mutable staging cannot
stand in for missing history from another supplier.

## Explicitly Prohibited Data And Writes

The three tables may not contain raw supplier SKU, EAN/GTIN, MPN, product name,
description, raw source record, XML, feed URL, credential, token, host path,
container path, SEO, category, attribute, image, or application secret.
Exception messages and log prose are not evidence fields.

Capture writes only the three new append-only tables in addition to the
importer's pre-existing staging behavior. It does not:

- write or read-modify-write a Product;
- execute Catalog Sync CREATE or UPDATE;
- link, unlink, publish, hide, deactivate, or archive anything;
- mutate `supplier_products`, `product_supplier_offers`, mappings, categories,
  attributes, images, prices, or stock beyond the existing importer behavior;
- dispatch a job, change a schedule, or enable APCOM;
- use evidence production as import authorization.

Required Catalog Sync defaults remain:

```text
CATALOG_SYNC_CREATE_ENABLED=true
CATALOG_SYNC_UPDATE_ENABLED=false
CATALOG_SYNC_SYNC_ALL_ENABLED=false
CATALOG_SYNC_AUTO_ENABLED=false
```

## No Backfill And Readiness State Machine

The tables start empty. There is no conversion from staging, catalog offers,
ImportHistory context, SupplierImportRun reports, feed items, logs, caches, or
raw files.

Readiness per supplier/source/cohort epoch is:

```text
capture_disabled
-> awaiting_first_generation
-> qualified_baseline_only
-> one_qualified_comparable_snapshot
-> two_qualified_comparable_snapshots
-> three_snapshot_window_ready
```

The ready state permits evidence preparation only. A confirmed-missing preview
recommendation additionally needs three consecutive physical absences and at
least 48 elapsed hours. A gap changes state to
`history_gap_requires_new_baseline`. A cohort expansion changes state to
`cohort_changed_requires_new_baseline`. Neither condition is skipped.

## Retention And Capacity

The current planning policy retains raw snapshots and detailed technical logs
for 90 days and summarized import runs for 24 months. These immutable hashed
lifecycle facts are neither raw snapshots nor ordinary logs. They must retain
at least the longest approved lifecycle evaluation horizon plus a separately
reviewed safety margin. Because no cleanup margin is approved here, initial
retention is indefinite and no automatic deletion is authorized.

Estimated storage is:

```text
generation headers
+ first-enrollment rows
+ sum(physical cohort observations per generation)
+ indexes
```

The implementation phase must benchmark row and index sizes with synthetic
bounded fixtures, never real VPS data. It must test the exact indexes above,
stream reads, and enforce generation, observation, and output-byte limits.

Any archival or deletion requires a later dry-run-first design, explicit
privacy/legal/audit scope, protection of referenced closeouts, and approval.
Rollback must never delete already captured history.

## Future Rollout, Failure, And Rollback

The rollout has exactly twelve non-combinable gates:

1. **Persistence design approval and documentation merge.** Input: this complete
   design and a fresh independent review. Result: an approved documentation PR
   merged into `main`. Authorization permits documentation merge only. Failure
   keeps PRE.A unapproved and permits no schema work. Next permitted gate: 2.
2. **Additive schema implementation.** Input: a separate explicit schema
   authorization and the merged design. Result: future additive migrations for
   the claim and three evidence tables, exact named indexes, checks, `RESTRICT`
   keys and mutation guards, plus synthetic MySQL tests. Authorization permits
   schema/repository implementation only. Failure leaves capture absent and
   disabled. Next permitted gate: 3 after schema validation passes.
3. **Capture and idempotency implementation.** Input: separate explicit
   implementation authorization and validated schema. Result: stable-key
   dispatch, common coordinator, claim state machine, behavior-equivalent
   streaming parser, bounded spool and capture tests, all disabled by default.
   Authorization does not permit enablement or an import. Failure keeps capture
   disabled. Next permitted gate: 4.
4. **Independent implementation review and merge.** Input: complete schema and
   capture diff with passing MySQL, concurrency, crash, retry, gap, cohort,
   no-backfill and zero-mutation tests. Result: independent database, Security
   and Catalog Sync Safety approval and merge into `main`. Authorization permits
   review and merge only. Failure blocks merge. Next permitted gate: 5.
5. **Disabled staging deployment and verification.** Input: exact merged
   implementation plus separate deployment approval. Result: staging schema and
   code deployed with capture still disabled, followed by importer, staging,
   Product, Catalog Sync flag, Super Admin, queue and scheduler verification.
   Authorization does not enable capture. Failure rolls back application state
   safely while retaining additive schema. Next permitted gate: 6.
6. **APCOM capture enablement.** Input: successful disabled deployment evidence
   and separate explicit enablement authorization. Result: the APCOM-specific
   default-off capture gate is explicitly enabled. Enablement does not authorize
   an import or schedule. Failure disables capture. Next permitted gate: 7.
7. **Individually authorized future APCOM imports.** Input: one explicit manual
   operator authorization per import while the APCOM schedule remains disabled.
   Result: at most one claimed immutable generation for that logical execution.
   A failed/frozen run is retained or represented as a gap and is never
   automatically rerun or backfilled. Next permitted gate: another separately
   authorized gate 7 import or gate 8 when enough history exists.
8. **Immutable warm-up/readiness.** Input: future captured generations only.
   Result: read-only proof of one baseline plus three later comparable absences
   in one unchanged cohort epoch and at least 48 hours from the first comparable
   absence. Failure waits for separately authorized future imports; it cannot
   backfill or skip gaps. Next permitted gate: 9.
9. **Read-only evidence producer.** Input: successful readiness proof and a
   separate implementation authorization. Result: the exact V1 producer is
   implemented, independently reviewed, merged and deployed read-only.
   Authorization does not prepare a candidate or run a preview. Failure blocks
   evidence preparation. Next permitted gate: 10.
10. **Exact candidate preparation and human approval.** Input: the deployed
    producer and separate candidate-preparation authorization. Result: one
    pinned privacy-safe evidence candidate with exact hash and evaluation time,
    then explicit human approval of that exact candidate. Preparation is not
    preview authorization. Failure destroys or rejects the candidate. Next
    permitted gate: 11.
11. **One controlled operational preview.** Input: exact approved candidate and
    separate one-run authorization. Result: exactly one read-only C3D.1 preview
    with zero-mutation evidence. Authorization permits neither lifecycle writes
    nor closeout. Failure stops without rerun unless separately authorized.
    Next permitted gate: 12.
12. **Independent result review and documentation closeout.** Input: the exact
    preview report and zero-mutation evidence. Result: independent review and a
    documentation-only closeout. Authorization does not permit lifecycle,
    Product, offer, retention or Catalog Sync writes. Failure leaves C3D.1 open;
    no later supplier phase is permitted.

Review is not merge; merge is not deployment; deployment is not enablement;
enablement is not import; evidence creation is not approval; evidence approval
is not preview; and preview is not closeout.

Deployment is not capture activation. Capture activation is not import
authorization. Import completion is not evidence approval.

Rollback disables only the capture gate. It does not remove schema or captured
rows, rewrite history, modify staging, touch Products, or change Catalog Sync.
Failure before commit rolls back all three evidence-table inserts and removes
temporary capture state. Failure after commit requires a forward fix because
the committed generation is immutable.

## Future Implementation Map

Proposed later files, subject to separate implementation review:

```text
database/migrations/*_create_supplier_import_execution_claims_table.php
database/migrations/*_create_supplier_offer_snapshot_generations_table.php
database/migrations/*_create_supplier_offer_snapshot_enrollments_table.php
database/migrations/*_create_supplier_offer_snapshot_observations_table.php
database/migrations/*_add_supplier_id_id_index_to_import_histories_table.php
app/Models/SupplierImportExecutionClaim.php
app/Models/SupplierOfferSnapshotGeneration.php
app/Models/SupplierOfferSnapshotEnrollment.php
app/Models/SupplierOfferSnapshotObservation.php
app/Data/Suppliers/Onboarding/SnapshotSourceIdentity.php
app/Repositories/Suppliers/ImmutableSupplierOfferSnapshotRepository.php
app/Repositories/Suppliers/SupplierImportExecutionClaimRepository.php
app/Services/Suppliers/SupplierImportExecutionLock.php
app/Services/Suppliers/SupplierImportExecutionCoordinator.php
app/Services/Suppliers/Snapshots/SupplierOfferSnapshotCollector.php
app/Services/Suppliers/Snapshots/SupplierOfferSnapshotCaptureService.php
app/Services/Suppliers/Snapshots/ImportHistorySnapshotSourceAdapter.php
app/Services/Suppliers/Onboarding/OperationalSupplierOfferEvidenceProducer.php
app/Console/Commands/PrepareOperationalSupplierOfferLifecycleEvidence.php
config/supplier_snapshot_capture.php
tests/Feature/SupplierOfferSnapshotPersistenceTest.php
tests/Feature/SupplierOfferSnapshotCaptureTest.php
tests/Feature/SupplierOfferSnapshotConcurrencyTest.php
tests/Feature/SupplierImportExecutionIdempotencyTest.php
tests/Feature/OperationalSupplierOfferEvidenceProducerTest.php
tests/Unit/Suppliers/SupplierOfferSnapshotFingerprintTest.php
tests/Feature/SupplierOfferLifecycleDocumentationContractTest.php
```

Implementation remains split by the twelve gates above. Migration, capture,
review/merge, deployment, enablement, import, warm-up, evidence production,
candidate approval, preview, and closeout must not share one authorization.

## Non-approval Boundary

This design does not authorize a migration, model, parser refactor, producer,
import hook, feature flag, real evidence candidate, supplier import, APCOM
schedule change, Catalog Sync action, Product mutation, retention cleanup,
deployment, or C3D.1 operational preview. Supplier #3 selection must not begin
while this prerequisite remains unresolved.

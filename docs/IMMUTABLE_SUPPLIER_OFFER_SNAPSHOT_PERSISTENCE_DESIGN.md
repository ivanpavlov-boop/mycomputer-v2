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
C3D.1 remains blocked and Supplier #3 remains unstarted.

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

The future implementation adds two narrowly scoped mutable coordination tables,
three append-only evidence tables, and reuses `import_histories.id` as the
attempt sequence marker:

1. `supplier_import_execution_claims` owns one stable logical execution across
   queue retry and redelivery. It coordinates an attempt but is not evidence.
2. `supplier_import_dispatch_outbox` durably owns exactly one initial queue
   publication for the authorized claim. It is mutable coordination state, not
   lifecycle evidence and not import authorization.
3. `supplier_offer_snapshot_generations` stores one immutable final capture
   header for one ImportHistory generation.
4. `supplier_offer_snapshot_enrollments` stores the first immutable enrollment
   of every hashed offer identity in a supplier/source cohort.
5. `supplier_offer_snapshot_observations` stores one physical `present=true` or
   `present=false` observation for every identity enrolled for that generation.

The third enrollment layer is mandatory. Mutable staging can identify cohort
membership at a capture boundary, but it cannot preserve that membership after
a row is removed. An enrolled identity therefore remains in every later
generation in the same supplier/source cohort, even after it disappears from
`supplier_products` or `product_supplier_offers`.

There is no mutable current-snapshot row. A complete header, newly discovered
enrollments, all observations, the terminal ImportHistory transition, and the
terminal execution-claim transition are committed atomically after source
traversal. A failed capture may persist one final frozen header without
observations only when its complete final facts are safe. If final persistence
fails, no evidence or terminal transition commits. The abandoned processing
recovery below then closes the ImportHistory and claim as failed without a
header. A missing header is a sequence gap and never means absence.

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
initial dispatch. Its exact DDL is
`CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL`, with the global
unique constraint and lowercase-hexadecimal `CHECK` defined below. It contains
no supplier key, database identifier, feed URL, credential, path, token or raw
supplier identifier and is not an authentication capability.

For the orchestrated path, `SupplierImportOrchestrator::dispatch()` creates the
`SupplierImportRun`, `pending_dispatch` execution claim, and exactly one pending
initial-dispatch outbox row in one database transaction. For every legacy XML
entry point, the explicit operator or scheduler action creates its ImportJob,
`pending_dispatch` claim, and exactly one pending initial-dispatch outbox row in
one transaction. If either transaction rolls back, none of its parent, claim,
or outbox rows exists.

Only after commit may an immediate outbox publisher attempt Redis publication.
The committed outbox row, not Redis and not
`queue.connections.redis.after_commit`, is the durable handoff. Every publish
or republish serializes the original claim ID and exact logical key. Queue retry
and redelivery preserve them. Requeuing an ImportJob as a genuinely new,
separately authorized operator action creates a new claim, outbox row, and key;
no publisher, reconciler, or `handle()` method generates a key.

### Claim and attempt ownership

Both handlers must enter the common owner-checked
`SupplierImportExecutionCoordinator` before the orchestrator or XML engine.
After the coordinator owns `supplier_import:<supplier_id>`, it locks the claim
row with `SELECT ... FOR UPDATE`, verifies the serialized key, supplier, path
and parent references, and performs one compare-and-set transition. Each
`handle()` invocation creates a separate random attempt token and stores only
its SHA-256 hash. The raw attempt token stays in process memory. The active
attempt may proceed only while it owns both the claim token and the Redis lock;
both are rechecked before the non-repeatable mutation boundary and final
snapshot commit.

The exact claim states are `pending_dispatch`, `queued`, `processing`,
`terminal_qualified`, `terminal_frozen`, and `terminal_failed`:

- creation writes `pending_dispatch` only when the parent and pending outbox row
  commit in the same transaction;
- successful Redis publication, or handler adoption of a publication that won
  the race with publisher acknowledgement, compare-and-sets
  `pending_dispatch -> queued` and the outbox row to `published` together;
- `queued -> queued` may replace attempt ownership only after the old owner and
  supplier lock are no longer valid and before the non-repeatable boundary;
- `queued -> processing` occurs exactly once, immediately before the first
  staging counter, failure-row insert, staging mutation, or other
  non-transactionally repeatable importer side effect;
- `queued -> terminal_frozen|terminal_failed` is permitted only for a verified
  pre-mutation outcome and closes an already-created ImportHistory in the same
  transaction when one exists;
- `processing -> terminal_qualified|terminal_frozen|terminal_failed` is
  owner-checked and permitted exactly once; and
- no terminal state has an outgoing transition.

Terminal state, terminal reason, bound source fingerprint, ImportJob and
ImportHistory are write-once. A database compare-and-set requires the exact
prior state, expected attempt ownership, and one affected row. Outbox delivery
may advance only `pending_dispatch -> queued`; it cannot create a claim,
ImportJob, ImportHistory, or generation.

Attempt ownership uses the same fixed 4,200-second upper bound as the supplier
Redis lock. `attempt_lease_expires_at` is set to acquisition time plus 4,200
seconds and is not extended. Ownership replacement is forbidden while either
the Redis lock or database attempt lease may still be valid.

Claim behavior is deterministic:

- **Unseen key at authorization:** insert one `pending_dispatch` claim and one
  pending outbox row with the parent. A handler receiving a key without that
  committed pair fails closed before download or import.
- **Committed but unpublished:** the original row remains recoverable by the
  bounded outbox reconciler; no new key or claim is created.
- **Published:** the claim is `queued`; duplicate publication or delivery uses
  the same claim and key.
- **Queued claim owned by another attempt:** Redis-lock acquisition or claim
  token comparison fails; the delivery is retryable and creates no ImportJob,
  ImportHistory, snapshot, staging, Product or Catalog Sync write.
- **Retry before non-repeatable processing:** after the old lease is no longer
  owned, the same logical key may replace attempt ownership, redownload if
  needed, and reuse the same bound ImportJob and ImportHistory.
- **Retry after `processing`:** importer replay is prohibited. An expired or
  crashed attempt is closed by the separately authorized abandoned-processing
  recovery as terminal failed and a history gap; another import requires a new
  operator-authorized key.
- **Unknown key at delivery:** a handler receiving a
  key without that committed claim fails closed before download or import.
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
streamed row processing begins. While the claim remains `queued`, interruption
before the non-repeatable boundary may safely reacquire ownership, redownload,
bind or verify the first fingerprint, and continue with the same ImportJob and
ImportHistory. A different digest under the same key before mutation atomically
closes the claim and any started ImportHistory as frozen with
`capture_source_fingerprint_conflict`; it creates no generation or absence
evidence.

Immediately before the first staging counter, `FailedImport` row, attribute
delete/recreate, `supplier_products` mutation, or equivalent importer side
effect, the coordinator owner-checks the supplier lock and compare-and-sets
`queued -> processing`. This is the non-repeatable mutation boundary. The
current importer is not idempotent merely because the source fingerprint is
equal: it increments `processed_rows`/`failed_rows`, inserts failure rows, and
mutates staging incrementally. After `processing` begins, no queue retry,
redelivery, publisher, or reconciler may call `XmlImportEngine` again for that
logical key. Partial staging remains failed-import state under existing importer
semantics; counters and failure rows are neither reset nor duplicated by replay.

An abandoned `processing` claim becomes a visible fail-closed gap. Under the
supplier lock and claim row lock, the manual recovery procedure verifies lease
expiry, absence of a terminal generation, expected non-terminal ImportHistory,
and claim ownership. One transaction compare-and-sets ImportHistory to failed
and the claim to `terminal_failed` with
`capture_processing_abandoned`. It creates no header, enrollment, observation,
absence, Product write, Catalog Sync action, or automatic replacement import.
Only a new explicit operator authorization may create a new logical key.

### Execution-claim data dictionary

Proposed additive coordination table: `supplier_import_execution_claims`.
Unlike the three evidence tables, this row is deliberately mutable only through
the owner-checked state machine above.

| Column | Type | Null/default | Purpose and invariant | Privacy |
| --- | --- | --- | --- | --- |
| `id` | unsigned bigint | not null | Surrogate claim key | internal |
| `logical_execution_key` | `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin` | not null | Random stable logical execution identity; globally unique | internal |
| `supplier_id` | unsigned bigint | not null | Exact supplier owner | internal |
| `supplier_feed_id` | unsigned bigint | nullable while orchestrated claim is pending | Bound once before download | sensitive metadata |
| `supplier_import_run_id` | unsigned bigint | nullable | Required orchestrated-path parent | internal |
| `import_job_id` | unsigned bigint | nullable before orchestrated allocation | Legacy parent at dispatch; bound once on orchestrated execution | internal |
| `import_history_id` | unsigned bigint | nullable before generation allocation | The only ImportHistory for this logical execution | internal |
| `execution_path` | varchar(32) ASCII | not null | `orchestrated` or `legacy_xml` | public contract |
| `state` | varchar(32) ASCII | `pending_dispatch` | Closed state machine above | public contract |
| `active_attempt_token_hash` | `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin` | nullable | SHA-256 of current in-memory attempt token | pseudonymous |
| `source_fingerprint` | `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin` | nullable | First accepted exact-byte digest; immutable once set | pseudonymous |
| `terminal_reason_code` | varchar(96) ASCII | nullable | Stable allowlisted reason for terminal frozen/failed | public contract |
| `claimed_at` | char(25) ASCII | nullable | Canonical current-attempt claim instant | operational metadata |
| `attempt_lease_expires_at` | timestamp(6) | nullable | Upper bound for current queued/processing attempt ownership | operational metadata |
| `processing_started_at` | timestamp(6) | nullable | Write-once non-repeatable mutation boundary | operational metadata |
| `terminal_at` | char(25) ASCII | nullable | Canonical terminal instant | operational metadata |
| `created_at`, `updated_at` | timestamps | database managed | Coordination audit only | operational metadata |

An orchestrated claim requires `supplier_import_run_id`; a legacy claim forbids
that parent and requires `import_job_id` at dispatch. A `queued` or `processing`
claim requires a feed and ImportJob. `processing` requires a bound ImportHistory,
source fingerprint, owner token hash, lease expiry, and write-once
`processing_started_at`. A claim may bind at most one ImportHistory. Terminal
qualified/frozen requires that history; terminal failure before source work may
have no history. The implementation must enforce these implications with
database checks plus application validation.

Database uniqueness is deliberately narrow: the logical key is globally
unique; `supplier_import_execution_claims.import_history_id` is unique when
non-null; generation claim and ImportHistory references are each unique; and a
claim row can reach terminal state once. `import_histories.import_job_id` is not
globally unique because the current event/history model and explicit legacy
re-execution may legitimately associate multiple histories with one ImportJob.

Claim rows have no DELETE, prune, reuse, or key-rotation path in this phase.
Their retention is indefinite while any outbox, ImportHistory, or immutable
generation reference exists, and every parent/child foreign key is `RESTRICT`.
A later retention design must be dry-run-first and may not erase the durable
identity needed to explain a published or terminal execution.

The later implementation is not acceptable without focused tests proving:

- two sequential deliveries with one key create one claim, ImportHistory and
  final header;
- concurrent deliveries with one key permit one active owner and one final
  header;
- delivery after terminal qualified, frozen and failed states is a deterministic
  no-op/terminal result with no download or importer call;
- identical bytes after interruption before `processing` reuse the same claim,
  ImportJob and ImportHistory without allocating a generation;
- interruption during or after `processing` never reruns the importer and is
  closed as a visible failed gap;
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

### Dispatch-outbox data dictionary

Proposed additive coordination table: `supplier_import_dispatch_outbox`.
It is mutable only through owner-checked publishing/recovery transitions. It is
not evidence, a schedule, or authorization for another import.

| Column | Type | Null/default | Purpose and invariant | Privacy |
| --- | --- | --- | --- | --- |
| `id` | unsigned bigint | not null | Surrogate outbox key | internal |
| `supplier_import_execution_claim_id` | unsigned bigint | not null | Exact authorized claim | internal |
| `logical_execution_key` | `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin` | not null | Exact key copied from and constrained with the claim | internal |
| `event_type` | varchar(48) ASCII | `initial_dispatch` | Only authorized event in this phase | public contract |
| `job_type` | varchar(48) ASCII | not null | `run_supplier_import` or `process_xml_supplier_feed` | public contract |
| `dispatch_payload` | JSON | not null | Canonical allowlist described below | restricted operational metadata |
| `dispatch_payload_hash` | `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin` | not null | SHA-256 of canonical dispatch payload | pseudonymous |
| `state` | varchar(32) ASCII | `pending` | `pending`, `leased`, `published`, or `terminal_failed` | public contract |
| `attempt_count` | unsigned smallint | `0` | Bounded publication attempts; maximum 8 | aggregate |
| `lease_owner_key` | varchar(96) CHARACTER SET ascii COLLATE ascii_bin | nullable | Random per-invocation owner label; no host/user name | pseudonymous |
| `lease_token_hash` | `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin` | nullable | SHA-256 of in-memory lease token | pseudonymous |
| `leased_at` | timestamp(6) | nullable | Lease acquisition instant | operational metadata |
| `lease_expires_at` | timestamp(6) | nullable | Stale-lease recovery boundary | operational metadata |
| `next_attempt_at` | timestamp(6) | nullable | Deterministic retry eligibility | operational metadata |
| `published_at` | timestamp(6) | nullable | First acknowledged Redis publication | operational metadata |
| `terminal_failure_reason_code` | varchar(96) ASCII | nullable | Stable allowlisted terminal reason | public contract |
| `created_at`, `updated_at` | timestamp(6) | database managed | Mutable coordination audit | operational metadata |

`dispatch_payload` contains exactly `schema_version`, claim ID, logical key,
parent type, parent ID, and the existing boolean `force` intent where required
by the authorized parent action. It contains no supplier ID, feed URL,
credential, XML, observation, source identity, source path, raw supplier
identifier, or arbitrary job data. Consumers load every other value from the
claim and its constrained parent.

One composite foreign key
(`supplier_import_execution_claim_id`, `logical_execution_key`) references the
matching claim pair and uses `RESTRICT`. The unique
(`supplier_import_execution_claim_id`, `event_type`) relationship permits one
initial-dispatch event per logical execution. No publisher or reconciler may
insert a replacement event.

The exact outbox transitions are `pending -> leased`, owner-checked
`leased -> published`, handler-adopted `pending|leased -> published`, expired
owner-checked `leased -> leased`, and `pending|leased -> terminal_failed` after
the bounded terminal rules below. `published` and `terminal_failed` have no
outgoing transition. Lease fields are present only in `leased`; publication and
terminal transitions clear them. `published_at` is write-once. State changes
require the expected state, token where applicable, and exactly one affected
row. No DELETE or pruning is authorized. Outbox retention is indefinite until a
later dry-run-first retention design defines protection for linked claims and
audit evidence; parent deletion remains blocked by `RESTRICT`.

### Outbox publisher and manual recovery

After the authorization transaction commits, an immediate publisher may lease
the pending row and publish the original serialized job to Redis. Publication
success is acknowledged by one owner-token-checked transaction that changes the
outbox from `leased` (or `pending` when a fast handler adopts it) to `published`
and the claim from `pending_dispatch` to `queued`. A crash before publication
leaves the row recoverable. A crash after Redis accepted the job but before
acknowledgement leaves it eligible for duplicate publication; claim uniqueness
and terminal checks make that duplicate harmless. Neither case creates a key.

The only future recovery interface is CLI-only:

```text
php artisan suppliers:reconcile-import-dispatch-outbox --dry-run --limit=25
php artisan suppliers:reconcile-import-dispatch-outbox --apply --limit=25
```

It is absent in this phase. A Release/Operations operator with separate
one-run authorization may invoke it on a trusted application host. Dry-run is
the default; `--apply` is mandatory for publication; `--limit` is required,
defaults to 25, and rejects values outside 1 through 50. There is no scheduler,
HTTP route, Filament action, queue self-dispatch, or automatic invocation.

The reconciler reads only due `pending` rows or `leased` rows whose lease has
expired. MySQL 8.4 workers claim a bounded page through one transaction using
`SELECT ... FOR UPDATE SKIP LOCKED`, a random owner key and hashed token, and a
five-minute lease. It validates the original claim/key/parent and refuses every
terminal claim. Attempt delays are deterministic: 1, 5, 15, 30, 60, 120, 240,
then 480 minutes, capped at eight attempts. Safe output contains only row IDs,
states, counts, and allowlisted reason codes.

At eight failed attempts, or on an irreconcilable parent/key mismatch, one
transaction moves the outbox to `terminal_failed` and a still
`pending_dispatch` claim to `terminal_failed` with a privacy-safe reason such as
`dispatch_attempts_exhausted`, and marks the still-pending parent ImportJob or
SupplierImportRun failed with that allowlisted reason; no ImportHistory exists
and no import ran. This prevents a failed dispatch from remaining a false
running import. A
terminal claim encountered after an ambiguous successful publication changes
only the outbox to `published` when the original delivery is proven, otherwise
to `terminal_failed`; it never republishes. Stale leases may be replaced only
after expiry with an owner-checked compare-and-set. Recovery never changes a
schedule, calls Catalog Sync, or authorizes a new execution.

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
| `source_fingerprint` | `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin` | not null | SHA-256 of exact downloaded bytes consumed | pseudonymous |
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
| `cohort_fingerprint` | `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin` | nullable | Hash of sorted enrolled identities effective here | pseudonymous |
| `observation_set_fingerprint` | `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin` | nullable | Hash of sorted physical observation fingerprints | pseudonymous |
| `generation_fingerprint` | `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin` | not null | Hash of the complete canonical final header contract | pseudonymous |
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
| `supplier_sku_hash` | `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin` | not null | Domain-separated offer identity | pseudonymous |
| `effective_import_history_id` | unsigned bigint | not null | First generation where membership is valid | internal |
| `enrollment_source` | varchar(48) ASCII | not null | Closed provenance code described above | public contract |
| `enrollment_fingerprint` | `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin` | not null | Hash of canonical privacy-safe enrollment fields | pseudonymous |
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
| `supplier_sku_hash` | `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin` | not null | Enrolled offer identity | pseudonymous |
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
| `reliable_manufacturer_mpn_hash` | `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin` | nullable | Reserved V1 field; null for APCOM V4 | pseudonymous |
| `observation_fingerprint` | `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin` | not null | Hash of canonical observation fields | pseudonymous |
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
| `uq_import_execution_claim_id_key` | (`id`, `logical_execution_key`) | yes | exact composite parent for the outbox claim/key pair |
| `ix_import_execution_claim_supplier` | (`supplier_id`) | no | `fk_import_execution_claim_supplier` to `suppliers.id` |
| `ix_import_execution_claim_feed` | (`supplier_feed_id`) | no | `fk_import_execution_claim_feed` to `supplier_feeds.id` |
| `ix_import_execution_claim_run` | (`supplier_import_run_id`) | no | `fk_import_execution_claim_run` to `supplier_import_runs.id` |
| `ix_import_execution_claim_job` | (`import_job_id`) | no | `fk_import_execution_claim_job` to `import_jobs.id`; multiple explicitly authorized claims may reference one reusable legacy job |
| `uq_import_execution_claim_history` | (`import_history_id`) | yes | `fk_import_execution_claim_history` to `import_histories.id` and at most one claim per history |
| `ix_import_execution_claim_scope_state` | (`supplier_id`, `supplier_feed_id`, `state`, `id`) | no | bounded active/terminal claim inspection for one supplier/feed |

All five claim parent foreign keys use `RESTRICT`. The logical-key query is
`WHERE logical_execution_key = ?`. Active-state inspection is
`WHERE supplier_id = ? AND supplier_feed_id = ? AND state IN (...) ORDER BY id`.

### `supplier_import_dispatch_outbox`

| Index | Ordered columns | Unique | Supported boundary |
| --- | --- | --- | --- |
| `PRIMARY` | (`id`) | yes | outbox identity |
| `uq_import_dispatch_outbox_claim_event` | (`supplier_import_execution_claim_id`, `event_type`) | yes | one initial dispatch event per claim and left-prefix support for `fk_import_dispatch_outbox_claim` |
| `uq_import_dispatch_outbox_claim_key` | (`supplier_import_execution_claim_id`, `logical_execution_key`) | yes | `fk_import_dispatch_outbox_claim_key` to the exact claim/key pair |
| `ix_import_dispatch_outbox_due` | (`state`, `next_attempt_at`, `id`) | no | bounded pending recovery page |
| `ix_import_dispatch_outbox_lease` | (`state`, `lease_expires_at`, `id`) | no | bounded stale-lease recovery page |

Both outbox foreign keys use `RESTRICT`. The two claim references are
deliberately redundant: the simple relationship supports ownership traversal,
while the composite relationship prevents a claim ID and logical key from
different executions being paired. The implementation must name both
constraints explicitly and must not rely on an implicit MySQL index.

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

### Exact hexadecimal storage contract

Every textual 64-character digest/key column uses exactly:

```sql
CHAR(64) CHARACTER SET ascii COLLATE ascii_bin
```

No database-default character set or collation is accepted. A non-null column
has a named MySQL 8.4-compatible check equivalent to:

```sql
CHECK (
    OCTET_LENGTH(column_name) = 64
    AND REGEXP_LIKE(column_name, _ascii'^[0-9a-f]{64}$', 'c')
)
```

A nullable column has a named check equivalent to:

```sql
CHECK (
    column_name IS NULL
    OR (
        OCTET_LENGTH(column_name) = 64
        AND REGEXP_LIKE(column_name, _ascii'^[0-9a-f]{64}$', 'c')
    )
)
```

The exact affected proposed columns are:

| Table | Non-null lowercase hexadecimal columns | Nullable lowercase hexadecimal columns |
| --- | --- | --- |
| `supplier_import_execution_claims` | `logical_execution_key` | `active_attempt_token_hash`, `source_fingerprint` |
| `supplier_import_dispatch_outbox` | `logical_execution_key`, `dispatch_payload_hash` | `lease_token_hash` |
| `supplier_offer_snapshot_generations` | `source_fingerprint`, `generation_fingerprint` | `cohort_fingerprint`, `observation_set_fingerprint` |
| `supplier_offer_snapshot_enrollments` | `supplier_sku_hash`, `enrollment_fingerprint` | none |
| `supplier_offer_snapshot_observations` | `supplier_sku_hash`, `observation_fingerprint` | `reliable_manufacturer_mpn_hash` |

No listed field uses `BINARY(32)` in this design. A later implementation may
not substitute binary storage without a separately reviewed design amendment
that updates every equality, foreign-key, JSON, and evidence-encoding boundary
consistently. APCOM still stores
`reliable_manufacturer_mpn_hash = null` because no MPN domain is approved.

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

1. The dispatch boundary has already committed the parent, stable execution
   claim, and one pending outbox row atomically. The outbox publication has
   advanced the exact claim to `queued` without generating a new key.
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
8. Immediately before existing mapping, validation, counters, failure inserts,
   or staging writes can mutate state, the coordinator owner-checks the lock and
   claim and compare-and-sets `queued -> processing`. Existing importer behavior
   then executes for each streamed row exactly as before. It cannot be replayed
   for this logical key after that transition.
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
12. One database transaction on the import connection inserts new enrollments,
    the final header, deterministic chunks of exhaustive physical observations,
    all final privacy-safe fingerprints/counts, the terminal ImportHistory
    compare-and-set, the current importer-equivalent terminal ImportJob/feed
    fields, and the owner-checked terminal claim compare-and-set.
13. Both source temporary file and privacy-safe spool are removed in `finally`
    on success, failure, signal-driven worker termination where PHP cleanup
    runs, and repository retry. A startup janitor may remove only stale files
    bearing a capture-specific random prefix and correct owner/mode; it is not
    evidence and must never inspect or log contents.

The observer does not use Laravel cache, session, queue payloads, application
storage, or logs as temporary evidence. It does not hold the complete source,
cohort, or observation set in memory. It performs no second HTTP request and no
second source download.

Capture-only failures must be caught after staging semantics are known. A
complete successful importer result may close with a frozen header when final
facts are safe. Otherwise the final transaction rolls back and the execution is
later closed by the abandoned-processing recovery as a missing-header failed
gap. Partial observations are never committed. The design does not claim one
transaction around incremental staging and final evidence.

### Atomic terminal transition service

The current `ImportHistory::transitionForImport()` owns its own transaction and
therefore is not the future finalization API. The implementation must introduce
a transaction-aware repository/service method that accepts the caller's active
database connection and transaction. It locks the expected ImportHistory and
claim rows, requires `ImportHistory.event = started`, requires the exact claim
state and owner token, and performs both compare-and-set updates inside the same
transaction as evidence insertion.

That finalization transaction also applies the current importer's terminal
ImportJob and SupplierFeed status/timestamp fields from the already-computed
result. `terminal_qualified` maps ImportHistory to `finished`.
`terminal_frozen` maps it to `finished` when staging completed but capture
qualification froze, and to `failed` when the importer never crossed into a
valid completed result. `terminal_failed` maps it to `failed`. The outer
SupplierImportRun remains a derived report; terminal redelivery may rebuild that
report from the stored terminal result without rerunning the importer.

Each terminal compare-and-set must affect exactly one row or throw so the whole
transaction rolls back. A terminal claim with `ImportHistory=started`, or a
terminal ImportHistory without its matching terminal claim outcome, is
forbidden. On rollback no generation, enrollment, observation, terminal
ImportHistory, terminal claim, or final fingerprint/count becomes visible.
Because rollback occurs after `processing`, automatic importer replay remains
forbidden; manual abandoned-processing recovery closes the pair as failed.

### Crash and recovery matrix

`IH` below means the one bound ImportHistory. `None` under evidence means no
committed generation, enrollment, or observation. Every recovery query is
bounded and owner-checked.

| Boundary | Durable database state | Permitted retry/reconciliation | Download | Importer | ImportHistory | Claim | Outbox | Evidence | Required operator action | Why no false absence |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1. Before claim/outbox transaction | no parent/claim/outbox | repeat the still-authorized creation request | no | no | none | none | none | none | repeat original authorization action | no generation exists |
| 2. During claim/outbox transaction | all three rows commit or all roll back | retry transaction only after rollback is confirmed | no | no | none | none | none | none | repeat original authorization action | atomic creation exposes no partial authority |
| 3. Commit before Redis publish | parent plus `pending_dispatch`; pending outbox | manual outbox reconciler republishes original key | no until queued | no | none | `pending_dispatch` | `pending` | none | authorize one reconciler run | no source work occurred |
| 4. Publish before acknowledgement | Redis may hold job; database remains pending/leased | handler adopts publication or reconciler republishes same key | only after adoption to queued | only after queued | none before handler | `pending_dispatch` until adoption | `pending`/`leased` until adoption | none | authorize reconciler only if handler does not adopt | duplicate delivery shares one claim |
| 5. Delivery before supplier lock | queued claim and published outbox | ordinary delivery retry with same payload | no | no | none | `queued` | `published` | none | none unless queue is operationally failed | lock prevents overlap and no source ran |
| 6. Lock acquired before processing | queued owner; IH may be bound started | same-key takeover only after lock/attempt lease expiry | yes | no | none or `started` | `queued` | `published` | none | none for ordinary queue retry | no importer side effect exists |
| 7. During download | queued owner; IH started; no accepted fingerprint required | same-key redownload after lease expiry | yes | no | `started` | `queued` | `published` | none | none for ordinary queue retry | downloaded bytes are not evidence |
| 8. Fingerprint bound before parser/staging | queued owner and immutable fingerprint; IH started | identical bytes may continue; different bytes close frozen | yes | not before `processing` CAS | `started`, then terminal with conflict | `queued`, then terminal frozen on conflict | `published` | none on conflict | new authorization only for different bytes | conflict creates no generation |
| 9. During parser/staging mutation | partial staging/counters/failures possible | never replay importer; abandoned recovery only | no retry download | no replay | `started` | `processing` | `published` | none committed | authorize recovery, then separately authorize any new import | partial staging is never a snapshot |
| 10. After partial staging writes | same as row 9 with visible partial importer state | fail-closed recovery only | no | no replay | `started` | `processing` | `published` | none | recovery plus new import authorization if desired | gap explicitly breaks chronology |
| 11. Importer complete before final evidence transaction | completed staging; local bounded final facts may exist | live owner may finalize once; after crash use failed recovery, never importer replay | no | no replay | `started` | `processing` | `published` | none committed | recovery after crash | uncommitted spool/facts cannot prove absence |
| 12. During final evidence transaction | either all final rows/transitions commit or none do | on rollback use abandoned-processing recovery | no | no replay | terminal only on commit, otherwise `started` | terminal only on commit, otherwise `processing` | `published` | all final evidence or none | recovery after rollback/crash | one transaction prevents qualified partial state |
| 13. Final commit before queue acknowledgement | complete terminal pair and zero/one final header | duplicate delivery returns stored terminal result | no | no | terminal | terminal | `published` | immutable final set | none | terminal check occurs before source work |
| 14. Duplicate after any terminal state | unchanged terminal rows | deterministic no-op only | no | no | unchanged | terminal | published/terminal failed | unchanged | new authorization only for a new import | terminal state has no outgoing transition |
| 15. Stale outbox or attempt lease | pending/leased outbox, queued claim, or processing claim | outbox lease may be replaced; queued owner may be replaced; processing uses failed recovery | only queued may redownload | never for stale processing | none/started | state-specific | state-specific | none unless already terminal | separately authorize relevant reconciler | processing cannot be replayed into evidence |
| 16. Different fingerprint under same key | first digest remains write-once | queued conflict closes IH/claim frozen; processing never redownloads | only pre-processing verification | no | terminal frozen when bound | terminal frozen | published | no generation | new explicit logical execution required | conflicting bytes cannot establish chronology |

The separately designed future abandoned-processing command contract is:

```text
php artisan suppliers:reconcile-abandoned-import-executions --dry-run --limit=25
php artisan suppliers:reconcile-abandoned-import-executions --apply --limit=25
```

It is absent and unscheduled in this phase. Only a Release/Operations operator
with explicit one-run recovery authorization may use it. Dry-run is default;
`--apply` is mandatory; limits outside 1 through 50 fail closed. Under the
supplier lock and `FOR UPDATE` claim/history locks, it accepts only expired
`processing` leases, verifies no terminal generation, and atomically changes
the expected `started` ImportHistory to failed and claim to `terminal_failed`
with `capture_processing_abandoned`, marks the bound ImportJob failed, applies
the existing safe failed-feed fields, and marks an applicable still-running
SupplierImportRun failed. Mismatch affects zero rows and fails the whole
transaction. Output is limited to IDs, counts, states, and allowlisted reason
codes. It creates no outbox row, job, import, generation, schedule, or Catalog
Sync action.

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
qualified header. Before `processing`, the queued execution may later reacquire
and continue. At or after `processing`, the same key may not rerun the importer
and must use abandoned-processing recovery.

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
the same serialized key may replace ownership only while the claim is `queued`
and `processing_started_at` is null. It reuses its bound ImportJob and
ImportHistory and may not allocate a second generation. An expired `processing`
claim cannot be taken over for importer execution; the manual recovery closes
it fail-closed. A genuinely new authorized execution has a new key and may
allocate the next ImportHistory only after separate operator authorization.

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

All five proposed tables may not contain raw supplier SKU, EAN/GTIN, MPN,
product name, description, raw source record, XML, feed URL, credential, raw
token, host path, container path, SEO, category, attribute, image, or
application secret. Hashed attempt/lease tokens and approved pseudonymous
digests follow the exact hexadecimal contract. Exception messages and log prose
are not evidence fields.

Dispatch coordination writes only the claim/outbox state machine. Capture
writes only the three new append-only evidence tables in addition to the
importer's pre-existing staging behavior. Neither path:

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

### Fine-grained rollout checkpoints

This is the canonical 49-row fine-grained checkpoint matrix. Every
authorization row records an
explicit human/repository-owner decision and performs no technical action.
Every action row permits only its named action. Review is not push/PR; review is
not merge; merge is not deployment; deployment is not enablement; enablement is
not import; candidate creation is not approval; approval is not preview; result
review is not closeout. A failed row blocks every later row.

| # | Checkpoint | Prerequisite | Separately responsible authorization | Permitted action | Result/artifact | Failure behavior | Next |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | Local design independent approval | complete local four-commit design candidate | independent Security, Database and Catalog Sync Safety reviewers | review only | `APPROVED` verdict for exact diff | remediate locally; no push | 2 |
| 2 | Authorize design push/Draft PR | checkpoint 1 approval | repository owner | authorize only the exact reviewed commit | recorded one-workflow authorization | remain local | 3 |
| 3 | Create design Draft PR | checkpoint 2 authorization | Release/DevOps operator | push exact branch and open Draft PR | Draft PR with pinned head/base | stop; do not broaden scope | 4 |
| 4 | Design PR CI/review approval | checkpoint 3 Draft PR | CI plus independent reviewers | checks and review only | green required checks and approval | remediate in a separately reviewed commit | 5 |
| 5 | Authorize design merge | checkpoint 4 evidence | repository owner | authorize merge only | explicit merge authorization | PR remains open | 6 |
| 6 | Merge design PR | checkpoint 5 authorization | Release/DevOps operator | merge exact approved PR | merge commit in `main` | stop without schema work | 7 |
| 7 | Authorize schema implementation | checkpoint 6 merged design | repository owner with Database/Security scope | authorize additive schema work only | scoped implementation authorization | schema remains absent | 8 |
| 8 | Implement/validate schema locally | checkpoint 7 authorization | implementation owner | add claim, outbox, evidence migrations/repositories and MySQL tests | local validated schema commit | keep capture absent/disabled | 9 |
| 9 | Authorize capture/idempotency/outbox implementation | checkpoint 8 validated schema | repository owner with Security/Catalog Sync Safety scope | authorize runtime implementation only | scoped implementation authorization | runtime remains absent | 10 |
| 10 | Implement/validate capture locally | checkpoint 9 authorization | implementation owner | implement disabled stable-key/outbox/coordinator/streaming/capture behavior and tests | local validated implementation commit | keep feature disabled | 11 |
| 11 | Independent implementation review | checkpoint 10 exact diff and test evidence | independent Database, Security and Catalog Sync Safety reviewers | review only | approval or findings | remediate; no push | 12 |
| 12 | Authorize implementation push/Draft PR | checkpoint 11 approval | repository owner | authorize exact reviewed commit only | recorded authorization | remain local | 13 |
| 13 | Create implementation Draft PR | checkpoint 12 authorization | Release/DevOps operator | push/open Draft PR | pinned Draft PR | stop; no deployment | 14 |
| 14 | Implementation PR CI/review approval | checkpoint 13 PR | CI plus independent reviewers | checks/review only | green MySQL/concurrency/crash/privacy tests and approval | remediate through review | 15 |
| 15 | Authorize implementation merge | checkpoint 14 evidence | repository owner | authorize merge only | explicit merge authorization | PR remains open | 16 |
| 16 | Merge implementation PR | checkpoint 15 authorization | Release/DevOps operator | merge exact approved PR | merge commit in `main` | stop; capture remains disabled | 17 |
| 17 | Authorize staging deployment | checkpoint 16 merge and deploy plan | repository owner | authorize exact merged commit deployment only | deployment authorization | no VPS action | 18 |
| 18 | Deploy implementation disabled | checkpoint 17 authorization | Release/DevOps operator | deploy exact `origin/main` with capture/reconcilers disabled | staging deployment evidence | rollback application state safely | 19 |
| 19 | Independent post-deployment verification | checkpoint 18 deployment | independent Release/QA reviewer | read-only staging verification | containers/schema/flags/importer/Super Admin evidence | capture remains disabled | 20 |
| 20 | Authorize APCOM capture enablement | checkpoint 19 successful verification | repository owner with Catalog Sync Safety approval | authorize enablement only | one enablement authorization | capture stays disabled | 21 |
| 21 | Enable and verify capture | checkpoint 20 authorization | Release/Operations operator | enable APCOM-specific gate and verify; do not import | enabled, verified default-off-schedule state | disable capture | 22 |
| 22 | Authorize one future APCOM import | checkpoint 21 or prior verified import | repository owner/operator for one named execution | authorize exactly one manual import | pinned one-import authorization | no import | 23 |
| 23 | Execute/verify authorized import | checkpoint 22 authorization | Supplier Import operator | run exactly one import and verify claim/outbox/generation | one qualified/frozen/failed generation or gap | no automatic retry; recover fail-closed | 22 or 24 |
| 24 | Verify warm-up/readiness | sufficient checkpoint 23 generations | independent Product Data Quality/Catalog Sync Safety reviewer | read-only readiness evaluation | baseline plus three comparable absences and 48-hour proof, or not-ready result | wait for separately authorized imports | 25 |
| 25 | Authorize evidence-producer implementation | checkpoint 24 ready evidence | repository owner | authorize producer code only | scoped authorization | producer remains absent | 26 |
| 26 | Implement/validate producer locally | checkpoint 25 authorization | implementation owner | implement bounded read-only V1 producer and tests | local validated producer commit | no candidate | 27 |
| 27 | Independent producer review | checkpoint 26 exact diff | independent Security/Product Data Quality/Catalog Sync Safety reviewers | review only | approval or findings | remediate; no push | 28 |
| 28 | Authorize producer push/Draft PR | checkpoint 27 approval | repository owner | authorize exact reviewed commit | recorded authorization | remain local | 29 |
| 29 | Create producer Draft PR | checkpoint 28 authorization | Release/DevOps operator | push/open Draft PR | pinned Draft PR | stop | 30 |
| 30 | Producer PR CI/review approval | checkpoint 29 PR | CI plus independent reviewers | checks/review only | green checks and approval | remediate through review | 31 |
| 31 | Authorize producer merge | checkpoint 30 evidence | repository owner | authorize merge only | merge authorization | PR remains open | 32 |
| 32 | Merge producer PR | checkpoint 31 authorization | Release/DevOps operator | merge exact approved PR | merge commit in `main` | stop | 33 |
| 33 | Authorize producer staging deployment | checkpoint 32 merge | repository owner | authorize exact deployment only | deployment authorization | no VPS action | 34 |
| 34 | Deploy producer | checkpoint 33 authorization | Release/DevOps operator | deploy read-only producer from exact `origin/main` | deployment evidence | rollback application state | 35 |
| 35 | Producer post-deployment verification | checkpoint 34 deployment | independent Release/QA reviewer | read-only verification | bounded/read-only/zero-mutation proof | block candidate work | 36 |
| 36 | Authorize evidence-candidate preparation | checkpoint 35 proof | repository owner/human decision owner | authorize one candidate preparation only | candidate-preparation authorization | no candidate | 37 |
| 37 | Prepare exact candidate | checkpoint 36 authorization | authorized evidence operator | create one pinned privacy-safe candidate | path, SHA-256 and evaluation timestamp | destroy/reject invalid candidate | 38 |
| 38 | Human approval of exact candidate | checkpoint 37 artifact | named human decision owner | approve exact path/hash/timestamp only | recorded exact-candidate approval | reject/destroy candidate | 39 |
| 39 | Authorize operational preview | checkpoint 38 approval | repository owner | authorize exactly one preview run | one-run authorization | no preview | 40 |
| 40 | Execute one operational preview | checkpoint 39 authorization | authorized operator | run exactly one read-only C3D.1 preview | report and zero-mutation evidence | stop; rerun needs new authorization | 41 |
| 41 | Independent operational-result review | checkpoint 40 report | independent Security/Product Data Quality/Catalog Sync Safety reviewers | review results only | approved result or findings | C3D.1 remains open | 42 |
| 42 | Authorize documentation closeout | checkpoint 41 approval | repository owner | authorize documentation edits only | closeout authorization | no edits | 43 |
| 43 | Implement closeout documentation | checkpoint 42 authorization | Documentation owner | update status/evidence docs only | local closeout commit | C3D.1 remains open | 44 |
| 44 | Independent closeout review | checkpoint 43 exact diff | independent Documentation/Safety reviewers | review only | approval or findings | remediate; no push | 45 |
| 45 | Authorize closeout push/Draft PR | checkpoint 44 approval | repository owner | authorize exact commit | recorded authorization | remain local | 46 |
| 46 | Create closeout Draft PR | checkpoint 45 authorization | Release/DevOps operator | push/open Draft PR | pinned Draft PR | stop | 47 |
| 47 | Closeout PR CI/review approval | checkpoint 46 PR | CI plus independent reviewers | checks/review only | green checks and approval | remediate through review | 48 |
| 48 | Authorize closeout merge | checkpoint 47 evidence | repository owner | authorize merge only | merge authorization | PR remains open | 49 |
| 49 | Merge closeout PR | checkpoint 48 authorization | Release/DevOps operator | merge exact approved documentation PR | closeout merge in `main` | C3D.1 remains open if merge fails | no later supplier phase without separate authorization |

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
database/migrations/*_create_supplier_import_dispatch_outbox_table.php
database/migrations/*_create_supplier_offer_snapshot_generations_table.php
database/migrations/*_create_supplier_offer_snapshot_enrollments_table.php
database/migrations/*_create_supplier_offer_snapshot_observations_table.php
database/migrations/*_add_supplier_id_id_index_to_import_histories_table.php
app/Models/SupplierImportExecutionClaim.php
app/Models/SupplierImportDispatchOutbox.php
app/Models/SupplierOfferSnapshotGeneration.php
app/Models/SupplierOfferSnapshotEnrollment.php
app/Models/SupplierOfferSnapshotObservation.php
app/Data/Suppliers/Onboarding/SnapshotSourceIdentity.php
app/Repositories/Suppliers/ImmutableSupplierOfferSnapshotRepository.php
app/Repositories/Suppliers/SupplierImportExecutionClaimRepository.php
app/Repositories/Suppliers/SupplierImportDispatchOutboxRepository.php
app/Repositories/Imports/TransactionalImportTerminalRepository.php
app/Services/Suppliers/SupplierImportExecutionLock.php
app/Services/Suppliers/SupplierImportExecutionCoordinator.php
app/Services/Suppliers/SupplierImportDispatchOutboxPublisher.php
app/Services/Suppliers/Snapshots/SupplierOfferSnapshotCollector.php
app/Services/Suppliers/Snapshots/SupplierOfferSnapshotCaptureService.php
app/Services/Suppliers/Snapshots/ImportHistorySnapshotSourceAdapter.php
app/Services/Suppliers/Onboarding/OperationalSupplierOfferEvidenceProducer.php
app/Console/Commands/PrepareOperationalSupplierOfferLifecycleEvidence.php
app/Console/Commands/ReconcileSupplierImportDispatchOutbox.php
app/Console/Commands/ReconcileAbandonedSupplierImportExecutions.php
config/supplier_snapshot_capture.php
tests/Feature/SupplierOfferSnapshotPersistenceTest.php
tests/Feature/SupplierOfferSnapshotCaptureTest.php
tests/Feature/SupplierOfferSnapshotConcurrencyTest.php
tests/Feature/SupplierImportExecutionIdempotencyTest.php
tests/Feature/SupplierImportDispatchOutboxTest.php
tests/Feature/SupplierImportCrashRecoveryTest.php
tests/Feature/OperationalSupplierOfferEvidenceProducerTest.php
tests/Unit/Suppliers/SupplierOfferSnapshotFingerprintTest.php
tests/Feature/SupplierOfferLifecycleDocumentationContractTest.php
```

Implementation remains split by the 49 checkpoints above. Review, push/PR,
merge, deployment, enablement, import, candidate preparation, candidate
approval, preview, result review, and closeout never share one authorization.

## Non-approval Boundary

This design does not authorize a migration, model, parser refactor, producer,
import hook, feature flag, real evidence candidate, supplier import, APCOM
schedule change, Catalog Sync action, Product mutation, retention cleanup,
deployment, or C3D.1 operational preview. Supplier #3 selection must not begin
while this prerequisite remains unresolved.

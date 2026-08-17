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
`SupplierImportRun`, pair-null `pending_dispatch` execution claim, and exactly
one pending initial-dispatch outbox row in one database transaction. It does not
resolve a feed or create an ImportJob. For every legacy XML entry point, the
explicit operator or scheduler action creates its already-known ImportJob,
pair-bound `pending_dispatch` claim, and exactly one pending initial-dispatch
outbox row in one transaction through the shared allocation repository. If
either transaction rolls back, none of its parent, claim, or outbox rows
exists.

Only after commit may an immediate outbox publisher attempt Redis publication.
The committed outbox row, not Redis and not
`queue.connections.redis.after_commit`, is the durable handoff. Every publish
or republish serializes the original claim ID and exact logical key. Queue retry
and redelivery preserve them. A genuinely new, separately authorized legacy
operator action creates a fresh ImportJob, claim, outbox row, and key; no
publisher, reconciler, or `handle()` method generates a key.

### Claim and attempt ownership

Both handlers must enter the common owner-checked
`SupplierImportExecutionCoordinator` before the orchestrator or XML engine.
Each `handle()` invocation creates a separate random attempt token, acquires
the owner-token Redis lock `supplier_import:<supplier_id>` with a 4,320-second
relative TTL, and stores only the token's SHA-256 hash. The raw attempt token
and lock object remain only in that active `handle()` process and are never
serialized, persisted, or logged.

Within at most 60 seconds after Redis acquisition, the coordinator performs one
owner-checked database compare-and-set. The statement verifies the serialized
key, supplier, path, parent references, exact prior state, and all-null prior
ownership tuple, then writes:

```sql
active_attempt_token_hash = :lowercase_sha256,
claimed_at = UTC_TIMESTAMP(6),
attempt_lease_expires_at = TIMESTAMPADD(SECOND, 4200, UTC_TIMESTAMP(6))
```

MySQL statement time is stable, so both timestamps derive from one database
clock. No allocation, source download, parsing, ImportHistory start, staging,
or other work begins until this CAS commits and affects exactly one row. Zero
affected rows, an exception, deadlock, timeout, rollback, or a bootstrap window
greater than 60 seconds requires owner-checked Redis `release()` and immediate
return with no work. Redis and MySQL wall clocks are never compared: Redis owns
only the relative lock TTL, while MySQL UTC is authoritative for durable lease
and recovery decisions.

The active attempt may proceed only while it owns both the complete database
ownership tuple and the Redis lock. Both are rechecked before the
non-repeatable mutation boundary and final snapshot commit.

The exact claim states are `pending_dispatch`, `queued`, `processing`,
`terminal_qualified`, `terminal_frozen`, and `terminal_failed`:

- creation writes `pending_dispatch` only when the parent and pending outbox row
  commit in the same transaction;
- successful Redis publication, or handler adoption of a publication that won
  the race with publisher acknowledgement, compare-and-sets
  `pending_dispatch -> queued` and the outbox row to `published` together;
- an orchestrated `queued` claim may remain pair-null until its worker completes
  the owner-checked allocation transaction below; publication never resolves a
  feed or creates an ImportJob;
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

### Pair-null allocation contract

`supplier_feed_id`, `import_job_id`, and `allocated_at` form one indivisible
allocation tuple. They are either all null or all non-null. A half-bound claim
is invalid. Pair-null is permitted only for an orchestrated `pending_dispatch`
or `queued` claim, and for `terminal_failed` when no allocation transaction ever
committed. The legacy authorization transaction is pair-bound before dispatch.

Successful allocation is mandatory before source download, parsing,
ImportHistory creation, or `queued -> processing`. Every
`terminal_qualified` or `terminal_frozen` claim is pair-bound. A
`terminal_failed` claim is pair-bound whenever `allocated_at`,
`import_history_id`, `source_fingerprint`, or `processing_started_at` is
present. An allocation transaction that rolls back never sets `allocated_at`
and therefore remains a legitimate pair-null pre-allocation outcome.

The future MySQL 8.4 migration uses these exact named checks, with the full
pair-bound and ownership expressions repeated rather than hidden in
application code. The ownership columns are exactly
`active_attempt_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin
NULL`, `claimed_at TIMESTAMP(6) NULL`, and
`attempt_lease_expires_at TIMESTAMP(6) NULL`; `processing_started_at` remains a
separate `TIMESTAMP(6) NULL` marker:

```sql
CONSTRAINT chk_import_execution_claim_allocation_pair CHECK (
    (supplier_feed_id IS NULL AND import_job_id IS NULL AND allocated_at IS NULL)
    OR
    (supplier_feed_id IS NOT NULL AND import_job_id IS NOT NULL AND allocated_at IS NOT NULL)
),
CONSTRAINT chk_import_execution_claim_history_allocation CHECK (
    import_history_id IS NULL
    OR (supplier_feed_id IS NOT NULL AND import_job_id IS NOT NULL AND allocated_at IS NOT NULL)
),
CONSTRAINT chk_import_execution_claim_fingerprint_allocation CHECK (
    source_fingerprint IS NULL
    OR (supplier_feed_id IS NOT NULL AND import_job_id IS NOT NULL AND allocated_at IS NOT NULL)
),
CONSTRAINT chk_import_execution_claim_processing_allocation CHECK (
    state <> 'processing'
    OR (
        supplier_feed_id IS NOT NULL
        AND import_job_id IS NOT NULL
        AND allocated_at IS NOT NULL
        AND import_history_id IS NOT NULL
        AND source_fingerprint IS NOT NULL
        AND processing_started_at IS NOT NULL
    )
),
CONSTRAINT chk_import_execution_claim_terminal_evidence_allocation CHECK (
    state NOT IN ('terminal_qualified', 'terminal_frozen')
    OR (
        supplier_feed_id IS NOT NULL
        AND import_job_id IS NOT NULL
        AND allocated_at IS NOT NULL
        AND import_history_id IS NOT NULL
    )
),
CONSTRAINT chk_import_claim_state CHECK (
    state IN (
        'pending_dispatch', 'queued', 'processing',
        'terminal_qualified', 'terminal_frozen', 'terminal_failed'
    )
),
CONSTRAINT chk_import_claim_attempt_tuple CHECK (
    (
        active_attempt_token_hash IS NULL
        AND claimed_at IS NULL
        AND attempt_lease_expires_at IS NULL
    )
    OR
    (
        active_attempt_token_hash IS NOT NULL
        AND claimed_at IS NOT NULL
        AND attempt_lease_expires_at IS NOT NULL
    )
),
CONSTRAINT chk_import_claim_attempt_hash CHECK (
    active_attempt_token_hash IS NULL
    OR (
        OCTET_LENGTH(active_attempt_token_hash) = 64
        AND REGEXP_LIKE(
            active_attempt_token_hash,
            _ascii'^[0-9a-f]{64}$',
            'c'
        )
    )
),
CONSTRAINT chk_import_claim_processing_owner CHECK (
    state <> 'processing'
    OR (
        supplier_feed_id IS NOT NULL
        AND import_job_id IS NOT NULL
        AND allocated_at IS NOT NULL
        AND import_history_id IS NOT NULL
        AND source_fingerprint IS NOT NULL
        AND processing_started_at IS NOT NULL
        AND active_attempt_token_hash IS NOT NULL
        AND claimed_at IS NOT NULL
        AND attempt_lease_expires_at IS NOT NULL
    )
),
CONSTRAINT chk_import_claim_terminal_owner_clear CHECK (
    state NOT IN ('terminal_qualified', 'terminal_frozen', 'terminal_failed')
    OR (
        active_attempt_token_hash IS NULL
        AND claimed_at IS NULL
        AND attempt_lease_expires_at IS NULL
    )
),
CONSTRAINT chk_import_claim_terminal_fields CHECK (
    (
        state = 'terminal_qualified'
        AND terminal_at IS NOT NULL
        AND terminal_reason_code IS NULL
    )
    OR (
        state IN ('terminal_frozen', 'terminal_failed')
        AND terminal_at IS NOT NULL
        AND terminal_reason_code IS NOT NULL
    )
    OR (
        state NOT IN ('terminal_qualified', 'terminal_frozen', 'terminal_failed')
        AND terminal_at IS NULL
        AND terminal_reason_code IS NULL
    )
),
CONSTRAINT chk_import_claim_attempt_time_order CHECK (
    claimed_at IS NULL OR claimed_at < attempt_lease_expires_at
),
CONSTRAINT chk_import_claim_processing_marker CHECK (
    processing_started_at IS NULL
    OR state IN (
        'processing', 'terminal_qualified', 'terminal_frozen', 'terminal_failed'
    )
),
CONSTRAINT chk_import_claim_processing_time_order CHECK (
    processing_started_at IS NULL
    OR state IN ('terminal_qualified', 'terminal_frozen', 'terminal_failed')
    OR (
        claimed_at IS NOT NULL
        AND processing_started_at >= claimed_at
        AND processing_started_at < attempt_lease_expires_at
    )
)
```

Application validation additionally requires a pair-bound `terminal_failed`
claim whenever any allocation/processing marker is present; the checks above
enforce each persisted marker directly without incorrectly forbidding a
pair-null pre-allocation terminal failure. A queued claim may contain either no
ownership tuple or the complete tuple, never a partial tuple. Every terminal
transaction clears the complete ownership tuple in the same statement that
writes its canonical terminal timestamp and outcome/reason.
`chk_import_claim_processing_time_order` enforces ordering while ownership is
active in `processing`; the only later state is an owner-checked terminal
transition from that already-valid row. Terminal clearing deliberately removes
the lease tuple while retaining the write-once processing marker.

### Owner-checked allocation transaction

Only the delivered worker allocates the orchestrated path, after Redis
delivery, common supplier-lock acquisition, claim-row `FOR UPDATE`, exact
`state = queued`, logical-key verification, and current attempt-token
verification. One transaction then:

1. locks the applicable `SupplierImportRun` and supplier;
2. resolves one active feed deterministically by the current XML-before-CSV
   priority followed by ascending feed ID and locks that feed;
3. verifies that the claim allocation tuple is still pair-null;
4. creates exactly one pending ImportJob for that supplier/feed;
5. compare-and-sets the claim from pair-null to that feed, ImportJob, and
   database `UTC_TIMESTAMP(6)` `allocated_at` while leaving state `queued`;
6. binds the same feed and ImportJob to the `SupplierImportRun`; and
7. requires every insert/update compare-and-set to affect exactly one row.

The transaction writes no fingerprint, performs no download or parsing, calls
no importer, creates no ImportHistory or snapshot, and does not enter
`processing`. Rollback removes the ImportJob and run binding and leaves the
claim pair-null/queued, so the same logical key may repeat allocation safely.
After commit, duplicate delivery reuses the existing tuple only when claim,
supplier, feed, ImportJob, run, and logical key all agree. The deterministic
resolver is rerun under lock; a different result or any half/conflicting
binding closes the pre-processing claim and applicable run/job/history
atomically as `terminal_failed` with
`capture_allocation_binding_conflict`, without replacement allocation or
evidence.

No suitable active feed closes a still pair-null claim and its
`SupplierImportRun` atomically as `terminal_failed` with
`capture_allocation_feed_unavailable`. It creates no ImportJob, ImportHistory,
generation, or absence evidence. A genuinely different feed requires a new
operator-authorized logical execution.

The legacy XML path deliberately preserves early allocation. Its authorization
transaction creates the known pending ImportJob, uses the same allocation
repository to bind the claim/feed/job tuple and `allocated_at`, then creates
the outbox row. Its worker never allocates again; it only verifies the committed
tuple under the same claim-to-ImportJob uniqueness and ownership checks used by
the orchestrated path. `processing` is impossible for either path until the
tuple is bound.

After successful allocation and before source download, one transaction locks
the claim, ImportJob, feed, and applicable run, creates exactly one
`ImportHistory(event=started)`, binds it to the claim, sets ImportJob to
`running`, and sets the orchestrated run to `running`. It replaces the current
self-transactional history helper with a transaction-aware repository method.
Rollback leaves no history and all parent records pre-start; retry reuses the
same allocation.

### Queue timing and attempt ownership

The future capture transport uses one dedicated Laravel queue connection and
one dedicated queue. The implementation choice is closed:

```text
Laravel connection                    = redis_supplier_import
queue                                 = supplier-imports
retry-after environment setting       = SUPPLIER_IMPORT_QUEUE_RETRY_AFTER
```

`redis_supplier_import` uses the Redis driver and the same approved Redis
backend as the current application unless a later infrastructure review
requires a different backend. Its queue is `supplier-imports`, its
`retry_after` is
`env('SUPPLIER_IMPORT_QUEUE_RETRY_AFTER', 3900)`, and its `block_for` and
`after_commit` behavior must be copied explicitly from the reviewed current
Redis conventions. Both `RunSupplierImportJob` and every
`ProcessXmlSupplierFeed` participating in this capture contract dispatch with
`onConnection('redis_supplier_import')->onQueue('supplier-imports')`. The
transactional outbox records and publishes to that exact connection and queue.

The current shared `redis` connection, `imports` queue, multi-queue worker, and
`REDIS_QUEUE_RETRY_AFTER=1300` remain unchanged for unrelated work. The current
worker must not consume `supplier-imports`, and the dedicated worker must not
consume `default`, `emails`, `loyalty`, `imports`, `exports`, `sync`,
`analytics`, `search`, or `erp`. The future service shape is equivalent to:

```text
php artisan queue:work redis_supplier_import \
    --queue=supplier-imports --sleep=3 --tries=8 --timeout=3600
```

The worker/service, healthcheck, restart behavior, and deployment validation
require their own implementation and Release/Operations review. Adding this
worker does not enable a supplier schedule or authorize an import.

The complete future timing invariant is exact:

```text
maximum supplier job/worker timeout = 3600 seconds
dedicated Redis retry_after          = 3900 seconds
database ownership lease            = 4200 seconds
Redis supplier-lock TTL              = 4320 seconds
required ordering                    = 3600 < 3900 < 4200 < 4320
```

The 300-second reservation margin prevents a second delivery from becoming
available before the maximum job timeout. The next 300 seconds are the durable
database ownership margin. The Redis lock adds a further 120 seconds; at most
60 seconds may elapse from successful Redis acquisition to committed database
ownership, leaving at least 60 seconds after database lease expiry and at least
30 seconds after the required lease-expiry-plus-30 contention delay. The
database lease and Redis lock are fixed and are not extended.

`RunSupplierImportJob` resolves to exactly 3600 seconds. A legacy
`ProcessXmlSupplierFeed` may retain its stricter current 1200-second per-job
timeout, but it runs on the same dedicated worker and may never exceed the
3600-second contract maximum. Startup/config validation fails closed unless
the dedicated connection, exact queue routing, worker timeout, retry-after,
database lease, Redis TTL, and complete inequality are proven. Tests must also
prove that unrelated jobs retain the shared connection and 1300-second
retry-after.

Future queue transport settings are explicit for both job payloads:

```text
$tries = 8
$backoff = [120, 600, 1800, 3600]
retryUntil = authorization or manual-republication time + 24 hours UTC
```

The canonical UTC transport deadline is serialized in the outbox payload and
cannot be regenerated by a queue retry. A separately authorized manual outbox
republication may set a new 24-hour deadline while retaining the same claim ID
and logical key. Queue delivery attempts, the single logical transition into
`processing`, and the outbox's maximum eight publication attempts are three
independent counters. Only the successful `queued -> processing` CAS counts as
the logical processing attempt.

Claim behavior is deterministic:

- **Unseen key at authorization:** insert one `pending_dispatch` claim and one
  pending outbox row with the parent. A handler receiving a key without that
  committed pair fails closed before download or import.
- **Committed but unpublished:** the original row remains recoverable by the
  bounded outbox reconciler; no new key or claim is created.
- **Published:** the claim is `queued`; duplicate publication or delivery uses
  the same claim and key.
- **Queued claim owned by another attempt:** Redis-lock acquisition or claim
  token comparison fails; the duplicate follows the lease-aware release
  contract below and creates no ImportJob, ImportHistory, snapshot, staging,
  Product or Catalog Sync write.
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

### Lock-contention release contract

A Redis redelivery that cannot acquire the supplier lock performs only a
bounded read of the durable claim and database clock. It never changes the
active owner, allocates, downloads, invokes the importer, calls
`forceRelease()`, creates a key/job/outbox event, or terminally fails the claim
because of contention.

The authoritative clock is MySQL `UTC_TIMESTAMP(6)`. For a valid lease whose
expiry is no later than `claimed_at + INTERVAL 4200 SECOND`, the job calculates:

```text
remaining_seconds = ceil(max(0,
    TIMESTAMPDIFF(MICROSECOND, UTC_TIMESTAMP(6), attempt_lease_expires_at)
) / 1000000)

release_delay_seconds = min(4230, max(30, remaining_seconds + 30))
```

It calls Laravel `release(release_delay_seconds)` and returns. The next delivery
therefore cannot be eligible earlier than 30 seconds after the recorded lease
expiry. A missing, malformed, or overlong lease is not guessed: the delivery
returns without work and requests the owner-independent transport recovery
path. It does not change or clear the active owner.

### Transport exhaustion and failed callbacks

Queue delivery attempts do not decide logical execution correctness. Ownership
closeout belongs inside the active `handle()` invocation because only that
scope contains the raw attempt token and original Redis lock object. The common
coordinator wraps allocation, download, processing, and finalization in one
`try/catch/finally` boundary after the Redis lock and database ownership CAS
succeed. Its `catch` classifies the durable boundary and its `finally` calls
only owner-checked `release()` when the lock is still owned. `forceRelease()` is
never permitted.

In-handle exception behavior is exact:

| Exception boundary | Required owner proof and durable result | Importer/evidence rule |
| --- | --- | --- |
| before allocation | current raw token, Redis lock and `queued` CAS clear the ownership tuple; outbox becomes `recovery_required` with `handle_pre_allocation_failed` | no allocation, download, importer or evidence |
| after allocation but before source download | same proof; retain the write-once allocation, clear ownership, keep claim `queued`, and set outbox `recovery_required` with `handle_pre_download_failed` | no download, importer or evidence |
| during download | same proof; retain allocation/history, clear ownership, keep claim `queued`, and set outbox `recovery_required` with `handle_download_failed` | discard temporary bytes; no importer or evidence |
| after fingerprint but before `processing` | same proof; retain the first fingerprint, clear ownership, keep claim `queued`, and set outbox `recovery_required` with `handle_pre_processing_failed` | no staging replay and no evidence |
| deterministic source fingerprint conflict before `processing` | same proof; atomically close applicable parents/history and claim as `terminal_frozen` with `capture_source_fingerprint_conflict`; clear ownership | no importer and no generation |
| after `processing` | current raw token, Redis lock, exact processing state and parent locks atomically close applicable run/job/history and claim as `terminal_failed`; clear ownership | never replay; no qualified snapshot |
| during partial staging mutation | same processing closeout with `capture_processing_failed`; preserve partial staging as failed-import state | no cleanup/replay and no qualified snapshot |
| during final transaction | first prove that the transaction rolled back and no terminal state/generation committed, then use processing closeout with `capture_finalization_failed`; if the terminal transaction committed, return its stored result | no partial evidence repair or replay |
| owner-token mismatch | no claim, parent, outbox or evidence mutation | wait for lease-expiry recovery |
| Redis lock loss or unknown ownership | no claim, parent, outbox or evidence mutation | wait for lease-expiry recovery |

All reason codes are fixed, privacy-safe values. Before `processing`, the
recoverable transaction may run only with the original token and lock and must
clear the complete ownership tuple atomically. After `processing`, the handler
never replays; with both proofs it closes the authoritative parent records and
claim atomically as failed/frozen, creates no qualified snapshot, and preserves
partial staging. Without both proofs it performs no closeout. An unknown final
transaction commit outcome is treated as lost proof: the handler performs no
second terminal write and lease-expiry recovery becomes authoritative.

Laravel invokes `failed(Throwable)` on a newly deserialized payload. It is
therefore a transport-only recovery hook and never claims to possess the raw
attempt token or original lock. It cannot release that lock, terminally close a
`processing` claim, replay the importer, clear/replace an active owner, or
create/rewrite terminal evidence. It uses only serialized durable claim/outbox
identifiers and one narrowly allowed owner-independent outbox CAS:

| Durable claim/outbox case | Transport-only `failed()` result |
| --- | --- |
| terminal claim in any canonical terminal state | no-op; preserve claim, outbox, parents and evidence |
| `pending_dispatch` with outbox `pending`, or `leased` whose outbox lease is expired by MySQL UTC | CAS outbox to `recovery_required`, clear its lease tuple, write `transport_attempts_exhausted` and timestamp; do not change claim/parents |
| any claim with an unexpired outbox `leased` owner | no-op; preserve the publisher owner and request later inspection |
| `queued` with outbox `published` and no active ownership tuple | CAS outbox to `recovery_required` with the same reason; do not change claim/parents |
| `queued` with a complete unexpired ownership tuple | no-op; preserve active owner and request later operator inspection |
| `queued` with a complete expired ownership tuple | no-op; do not clear an owner from a deserialized callback; request supplier-locked stale-owner reconciliation |
| `processing` with any non-terminal outbox | no-op; preserve active owner and request abandoned-processing recovery after verified expiry |
| outbox already `recovery_required` or `terminal_failed` | no-op |
| owner tuple, key, parent or cross-state mismatch | fail closed with no mutation and request explicit reconciliation |

The separately authorized manual outbox reconciler may lease the same
`recovery_required` event and publish a fresh payload with the same claim ID/key
and a new bounded transport deadline. It creates no claim, ImportJob,
authorization, or replacement outbox event. Queue-delivery,
logical-processing, and outbox-publication attempt accounting remain separate.

Timeout kill, OOM, host termination, worker crash, or another hard process
termination may bypass both `catch` and `finally`. `failed()` is not assumed to
hold ownership proof in that case. The Redis TTL and database ownership lease
must expire; only the separately authorized abandoned-processing recovery may
then acquire the supplier lock and row locks, prove expiry/current state, and
perform terminal recovery without importer replay.

Outbox publication attempts remain separate. Attempt eight is terminal and
operator-visible. For pair-null `pending_dispatch`, one transaction marks the
outbox and claim `terminal_failed` and the orchestrated run failed; legacy is
normally pair-bound and closes its pending ImportJob too. For a
`recovery_required` queued claim, the same supplier-locked transaction closes
the claim, bound ImportJob, any started ImportHistory, and authoritative
SupplierImportRun fields as `terminal_failed` with
`dispatch_attempts_exhausted`. No source/import/evidence work runs. A mismatch
rolls back everything and requires the explicit terminal-resolution command;
it is never left silently pending or queued. Any later import requires a new
operator-authorized key.

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
| `supplier_feed_id` | unsigned bigint | nullable for orchestrated pre-allocation only | Pair-null/pair-bound tuple; bound once before download | sensitive metadata |
| `supplier_import_run_id` | unsigned bigint | nullable | Required orchestrated-path parent | internal |
| `import_job_id` | unsigned bigint | nullable for orchestrated pre-allocation only | Pair-null/pair-bound tuple; unique across claims | internal |
| `allocated_at` | timestamp(6) | nullable for orchestrated pre-allocation only | Database UTC time written atomically with the pair; write-once | operational metadata |
| `import_history_id` | unsigned bigint | nullable before generation allocation | The only ImportHistory for this logical execution | internal |
| `execution_path` | varchar(32) ASCII | not null | `orchestrated` or `legacy_xml` | public contract |
| `state` | varchar(32) ASCII | `pending_dispatch` | Closed state machine above | public contract |
| `active_attempt_token_hash` | `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin` | nullable | SHA-256 of current in-memory attempt token | pseudonymous |
| `source_fingerprint` | `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin` | nullable | First accepted exact-byte digest; immutable once set | pseudonymous |
| `terminal_reason_code` | varchar(96) ASCII | nullable | Stable allowlisted reason for terminal frozen/failed | public contract |
| `claimed_at` | timestamp(6) | nullable | MySQL UTC current-attempt claim instant written in the owner CAS | operational metadata |
| `attempt_lease_expires_at` | timestamp(6) | nullable | Upper bound for current queued/processing attempt ownership | operational metadata |
| `processing_started_at` | timestamp(6) | nullable | Write-once non-repeatable mutation boundary | operational metadata |
| `terminal_at` | timestamp(6) | nullable | Canonical MySQL UTC terminal instant | operational metadata |
| `created_at`, `updated_at` | timestamps | database managed | Coordination audit only | operational metadata |

An orchestrated claim requires `supplier_import_run_id`; a legacy claim forbids
that parent and is pair-bound at dispatch. An orchestrated `pending_dispatch` or
`queued` claim may be pair-null. Every processing claim requires the complete
allocation tuple, a bound ImportHistory, source fingerprint, all three
ownership fields, and write-once `processing_started_at`. A claim may bind at most
one ImportHistory. Terminal qualified/frozen requires that history; terminal
failure before successful allocation may have neither allocation nor history.
The exact checks and owner-checked application validation above enforce these
implications.

Database uniqueness is deliberately narrow: the logical key is globally
unique; `supplier_import_execution_claims.import_job_id` and
`supplier_import_execution_claims.import_history_id` are each unique when
non-null; generation claim and ImportHistory references are each unique; and a
claim row can reach terminal state once. A separately authorized legacy
re-execution creates a fresh ImportJob rather than sharing one across claims.

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

Allocation and transport acceptance is not complete without MySQL 8.4 and
Redis integration tests proving all of these exact cases:

1. unrelated queues retain `retry_after=1300`;
2. only `redis_supplier_import` / `supplier-imports` uses
   `retry_after=3900`;
3. both import paths dispatch to the dedicated connection and queue;
4. the dedicated worker cannot consume unrelated queues;
5. the current general worker cannot consume `supplier-imports`;
6. in-handle exception closeout has the raw attempt token and Redis lock proof;
7. newly deserialized `failed()` cannot perform processing-owner closeout;
8. timeout kill, OOM, host termination and worker crash use lease-expiry
   recovery without importer replay;
9. every partial ownership tuple is rejected;
10. `processing` requires the full allocation, history, fingerprint,
    processing-marker and ownership tuples;
11. every terminal transition clears the ownership tuple;
12. `claimed_at` and lease expiry are generated from one MySQL CAS statement;
13. zero-row, failed, deadlocked, timed-out or rolled-back ownership CAS releases
    the Redis lock owner-checkingly and performs no work;
14. a bootstrap duration above 60 seconds aborts safely before work;
15. Redis TTL remains beyond DB lease expiry and the lease-expiry-plus-30
    contention delay under the worst permitted bootstrap;
16. every canonical outbox check rejects invalid state/field combinations;
17. `recovery_required` is accepted only as an outbox state;
18. outbox publication attempt nine is rejected;
19. every one of the 33 crash rows uses canonical claim/outbox/parent states;
20. every claim/outbox cross-record mismatch fails closed.

The same suite retains the previously specified allocation rollback, unique
ImportJob, duplicate binding, parent closeout, zero-qualified-evidence and
legacy/orchestrated idempotency assertions.

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
| `dispatch_payload` | JSON | not null | Canonical allowlist including fixed UTC transport deadline | restricted operational metadata |
| `dispatch_payload_hash` | `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin` | not null | SHA-256 of canonical dispatch payload | pseudonymous |
| `state` | varchar(32) ASCII | `pending` | `pending`, `leased`, `published`, `recovery_required`, or `terminal_failed` | public contract |
| `attempt_count` | unsigned smallint | `0` | Bounded publication attempts; maximum 8 | aggregate |
| `lease_owner_key` | varchar(96) CHARACTER SET ascii COLLATE ascii_bin | nullable | Random per-invocation owner label; no host/user name | pseudonymous |
| `lease_token_hash` | `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin` | nullable | SHA-256 of in-memory lease token | pseudonymous |
| `leased_at` | timestamp(6) | nullable | Lease acquisition instant | operational metadata |
| `lease_expires_at` | timestamp(6) | nullable | Stale-lease recovery boundary | operational metadata |
| `next_attempt_at` | timestamp(6) | nullable | Deterministic retry eligibility | operational metadata |
| `published_at` | timestamp(6) | nullable | First acknowledged Redis publication; write-once | operational metadata |
| `last_published_at` | timestamp(6) | nullable | Most recent acknowledged publication of the same event/key | operational metadata |
| `recovery_required_at` | timestamp(6) | nullable | Transport exhaustion/manual intervention boundary | operational metadata |
| `recovery_reason_code` | varchar(96) ASCII | nullable | Allowlisted recoverable transport reason | public contract |
| `terminal_at` | timestamp(6) | nullable | Canonical terminal-failure instant | operational metadata |
| `terminal_failure_reason_code` | varchar(96) ASCII | nullable | Stable allowlisted terminal reason | public contract |
| `created_at`, `updated_at` | timestamp(6) | database managed | Mutable coordination audit | operational metadata |

`dispatch_payload` contains exactly `schema_version`, claim ID, logical key,
parent type, parent ID, canonical UTC `transport_deadline_at`, and the existing
boolean `force` intent where required by the authorized parent action. It
contains no supplier ID, feed URL, credential, XML, observation, source
identity, source path, raw supplier identifier, or arbitrary job data.
Consumers load every other value from the claim and its constrained parent.

One composite foreign key
(`supplier_import_execution_claim_id`, `logical_execution_key`) references the
matching claim pair and uses `RESTRICT`. The unique
(`supplier_import_execution_claim_id`, `event_type`) relationship permits one
initial-dispatch event per logical execution. No publisher or reconciler may
insert a replacement event.

The canonical state set is exactly `pending`, `leased`, `published`,
`recovery_required`, and `terminal_failed`. `recovery_required` is an outbox
state only and is never an execution-claim state. The complete permitted
transition table is:

| Prior state | Next state | Only permitted cause |
| --- | --- | --- |
| `pending` | `leased` | owner-checked publisher lease |
| `pending` | `published` | fast handler adoption after proven publication |
| `pending` | `recovery_required` | in-handle pre-processing closeout or transport-only `failed()` |
| `pending` | `terminal_failed` | bounded terminal dispatch resolution |
| `leased` | `leased` | owner-checked replacement after lease expiry |
| `leased` | `published` | owner-checked publication acknowledgement or handler adoption |
| `leased` | `recovery_required` | in-handle pre-processing closeout or transport-only `failed()` |
| `leased` | `terminal_failed` | bounded terminal dispatch resolution |
| `published` | `recovery_required` | in-handle pre-processing closeout or transport-only `failed()` |
| `recovery_required` | `leased` | separately authorized manual reconciler |
| `leased` | `published` | acknowledgement of that same reconciled event/key |
| `recovery_required` | `terminal_failed` | bounded terminal dispatch resolution |

No other transition is valid. `terminal_failed` has no outgoing transition.
Lease fields are present only in `leased`; publication, recovery-required, and
terminal transitions clear them. `published_at` records the first publication
and is write-once; `last_published_at` advances only after another acknowledged
publication of the same event/key. Recovery fields are present only in
`recovery_required`. State changes require the expected state, token where
applicable, and exactly one affected row. No DELETE or pruning is authorized.
Outbox retention is indefinite until a later dry-run-first retention design
defines protection for linked claims and audit evidence; parent deletion
remains blocked by `RESTRICT`.

The future MySQL 8.4 migration uses these exact single-table checks:

```sql
CONSTRAINT chk_import_outbox_state CHECK (
    state IN ('pending', 'leased', 'published', 'recovery_required', 'terminal_failed')
),
CONSTRAINT chk_import_outbox_attempt_bound CHECK (
    attempt_count >= 0 AND attempt_count <= 8
),
CONSTRAINT chk_import_outbox_lease_tuple CHECK (
    (
        lease_owner_key IS NULL
        AND lease_token_hash IS NULL
        AND leased_at IS NULL
        AND lease_expires_at IS NULL
    )
    OR
    (
        lease_owner_key IS NOT NULL
        AND lease_token_hash IS NOT NULL
        AND leased_at IS NOT NULL
        AND lease_expires_at IS NOT NULL
    )
),
CONSTRAINT chk_import_outbox_publish_tuple CHECK (
    (published_at IS NULL AND last_published_at IS NULL)
    OR (published_at IS NOT NULL AND last_published_at IS NOT NULL)
),
CONSTRAINT chk_import_outbox_state_fields CHECK (
    (
        state = 'pending'
        AND lease_owner_key IS NULL
        AND lease_token_hash IS NULL
        AND leased_at IS NULL
        AND lease_expires_at IS NULL
        AND published_at IS NULL
        AND last_published_at IS NULL
        AND recovery_required_at IS NULL
        AND recovery_reason_code IS NULL
        AND terminal_at IS NULL
        AND terminal_failure_reason_code IS NULL
    )
    OR (
        state = 'leased'
        AND lease_owner_key IS NOT NULL
        AND lease_token_hash IS NOT NULL
        AND leased_at IS NOT NULL
        AND lease_expires_at IS NOT NULL
        AND recovery_required_at IS NULL
        AND recovery_reason_code IS NULL
        AND terminal_at IS NULL
        AND terminal_failure_reason_code IS NULL
    )
    OR (
        state = 'published'
        AND lease_owner_key IS NULL
        AND lease_token_hash IS NULL
        AND leased_at IS NULL
        AND lease_expires_at IS NULL
        AND published_at IS NOT NULL
        AND last_published_at IS NOT NULL
        AND recovery_required_at IS NULL
        AND recovery_reason_code IS NULL
        AND terminal_at IS NULL
        AND terminal_failure_reason_code IS NULL
    )
    OR (
        state = 'recovery_required'
        AND lease_owner_key IS NULL
        AND lease_token_hash IS NULL
        AND leased_at IS NULL
        AND lease_expires_at IS NULL
        AND recovery_required_at IS NOT NULL
        AND recovery_reason_code IS NOT NULL
        AND terminal_at IS NULL
        AND terminal_failure_reason_code IS NULL
    )
    OR (
        state = 'terminal_failed'
        AND lease_owner_key IS NULL
        AND lease_token_hash IS NULL
        AND leased_at IS NULL
        AND lease_expires_at IS NULL
        AND recovery_required_at IS NULL
        AND recovery_reason_code IS NULL
        AND terminal_at IS NOT NULL
        AND terminal_failure_reason_code IS NOT NULL
    )
),
CONSTRAINT chk_import_outbox_terminal_attempt CHECK (
    terminal_failure_reason_code <> 'dispatch_attempts_exhausted'
    OR attempt_count = 8
),
CONSTRAINT chk_import_outbox_timestamp_order CHECK (
    (leased_at IS NULL OR (leased_at >= created_at AND leased_at < lease_expires_at))
    AND (next_attempt_at IS NULL OR next_attempt_at >= created_at)
    AND (published_at IS NULL OR published_at >= created_at)
    AND (last_published_at IS NULL OR last_published_at >= published_at)
    AND (recovery_required_at IS NULL OR recovery_required_at >= created_at)
    AND (terminal_at IS NULL OR terminal_at >= created_at)
)
```

The application and repository allowlists additionally restrict recovery and
terminal reason codes. Attempt nine is rejected before mutation by
`chk_import_outbox_attempt_bound`. A terminal failure caused specifically by
publication exhaustion is valid only with
`terminal_failure_reason_code = 'dispatch_attempts_exhausted'` and
`attempt_count = 8`; irreconcilable parent/key failures may close earlier under
their separate allowlisted reason. A successful eighth publication may remain
`published` with `attempt_count = 8`.

Cross-record invariants cannot be delegated to single-table checks. They are
enforced under supplier lock and `FOR UPDATE` locks by the named future
repository/service transactions and proved by
`SupplierImportMysqlRedisRecoveryTest`:

| Cross-record case | Required invariant | Transaction owner and integration assertion |
| --- | --- | --- |
| terminal claim with duplicate published delivery | preserve the terminal claim/parents/evidence; outbox remains or is CAS-adopted to `published`; never republish or reopen | `SupplierImportDispatchOutboxPublisher` through `SupplierImportDispatchOutboxRepository`; duplicate terminal delivery is a zero-work no-op |
| `recovery_required` outbox with active `processing` claim | preserve claim owner and outbox; neither transport hook nor reconciler may lease/republish | `SupplierImportTransportFailureService` and outbox reconciler; active processing owner blocks recovery mutation |
| pre-processing transport exhaustion | non-terminal claim remains `pending_dispatch` or `queued`; only its existing outbox becomes `recovery_required`; parent state is preserved | `SupplierImportTransportFailureService`; no terminal claim/parent/evidence write |
| terminal outbox failure | outbox `terminal_failed`, claim `terminal_failed`, complete ownership tuple cleared, and every applicable parent closed in one transaction | `SupplierImportDispatchOutboxRepository` plus `TransactionalImportTerminalRepository`; mismatch rolls back all rows |
| terminal claim no-op | all terminal deliveries preserve canonical terminal state, parent state, generation/gap and outbox | `SupplierImportExecutionCoordinator`; no source, importer, parent, outbox or evidence mutation |
| published outbox after final claim | final claim may be `terminal_qualified`, `terminal_frozen`, or `terminal_failed` while the acknowledged outbox remains `published`; this does not reopen execution | coordinator terminal repository plus publisher adoption path; exact canonical combination is accepted and immutable |
| abandoned processing | expired `processing` claim is not republished; manual recovery alone closes claim/parents as `terminal_failed`, clears ownership, and creates no qualified generation | `ReconcileAbandonedSupplierImportExecutions` through `TransactionalImportTerminalRepository`; expiry and zero-replay are proven |

Any other claim/outbox combination fails closed, affects zero rows, and requires
explicit operator reconciliation. No cross-state repair creates a replacement
claim, outbox event, ImportJob, execution authorization, or importer replay.

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

The reconciler reads only due `pending` or `recovery_required` rows, or `leased`
rows whose lease has expired. MySQL 8.4 workers claim a bounded page through one
transaction using `SELECT ... FOR UPDATE SKIP LOCKED`, a random owner key and
hashed token, and a five-minute lease. It validates the original
claim/key/parent and refuses every terminal claim. A `recovery_required` event
must carry an allowlisted transport-only `failed()` or in-handle pre-processing
reason and a non-processing claim.
Attempt delays are deterministic: 1, 5, 15, 30, 60, 120, 240, then 480 minutes,
capped at eight outbox publication attempts. Safe output contains only row IDs,
states, counts, and allowlisted reason codes.

At outbox attempt eight, or on an irreconcilable parent/key mismatch, the
reconciler acquires the supplier lock and locks outbox, claim, and every bound
parent. For `pending_dispatch`, one transaction moves outbox and claim to
`terminal_failed`, closes the pending orchestrated run, and closes the legacy
ImportJob when present. For `recovery_required` with a queued pre-processing
claim, it additionally closes the bound ImportJob, any started ImportHistory,
and authoritative orchestrated run fields. The reason is the privacy-safe
`dispatch_attempts_exhausted`; no importer ran and no evidence is created.
Mismatch rolls back the whole terminal transaction and requires the same
CLI's explicit terminal-resolution path on a separately authorized run. It
never leaves a silently stranded `pending_dispatch` or `queued` claim.

A terminal claim encountered after an ambiguous successful publication changes
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
| `uq_import_execution_claim_job` | (`import_job_id`) | yes | left-prefix support for `fk_import_execution_claim_job_scope`; one ImportJob may belong to only one execution claim |
| `uq_import_execution_claim_history` | (`import_history_id`) | yes | `fk_import_execution_claim_history` to `import_histories.id` and at most one claim per history |
| `ix_import_execution_claim_scope_state` | (`supplier_id`, `supplier_feed_id`, `state`, `id`) | no | bounded active/terminal claim inspection for one supplier/feed |

All five claim parent foreign keys use `RESTRICT`. The logical-key query is
`WHERE logical_execution_key = ?`. Active-state inspection is
`WHERE supplier_id = ? AND supplier_feed_id = ? AND state IN (...) ORDER BY id`.
The exact composite ownership foreign key
`fk_import_execution_claim_job_scope` uses
(`import_job_id`, `supplier_id`, `supplier_feed_id`) and references the future
unique `import_jobs` key with those columns in the same order. It guarantees
that a bound job belongs to the claim's supplier and feed.

### Existing `import_jobs` additive ownership index

| Index | Ordered columns | Unique | Supported boundary |
| --- | --- | --- | --- |
| `uq_import_job_id_supplier_feed` | (`id`, `supplier_id`, `supplier_feed_id`) | yes | referenced parent for `fk_import_execution_claim_job_scope`; deterministic claim/job/feed ownership |

The primary key already makes `id` unique, but MySQL requires the exact
referenced column sequence for the composite ownership relationship. This
additive index does not change ImportJob behavior.

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
   advanced the exact claim to `queued` without generating a new key, feed, or
   ImportJob. The orchestrated path may still be pair-null; legacy is already
   pair-bound.
2. The common supplier import execution coordinator acquires the supplier lock,
   locks and claims that exact row, and checks terminal state before any import
   allocation or source work.
3. The owner-checked allocation transaction creates and binds exactly one
   orchestrated ImportJob, or verifies the legacy/existing tuple. It commits the
   pair and `allocated_at` before any source operation.
4. The transaction-aware generation-start repository creates exactly one
   ImportHistory, binds it to the claim, and marks ImportJob and applicable run
   running. A separate default-off gate determines whether snapshot capture is
   attempted.
5. `SsrfProtectionService::downloadToTemporaryFile()` downloads once to its
   restricted system temporary file.
6. The process opens the mode-0600 file without following links, records its
   file identity and size, and hashes its exact bytes incrementally.
7. A behavior-equivalent streaming XML parser traverses that same restricted
   file while its identity is held. Before deletion, a second bounded local
   hash pass must reproduce the first digest and the file identity/size must be
   unchanged. Any mismatch freezes capture. This is not a second fetch or
   download and proves that parsing and the stored fingerprint refer to the
   same downloaded bytes.
8. The streaming parser consumes the file without a complete XML tree;
   the existing complete SimpleXML tree and extracted row array must not remain
   in the capture-enabled path.
9. Immediately before existing mapping, validation, counters, failure inserts,
   or staging writes can mutate state, the coordinator owner-checks the lock and
   claim and compare-and-sets `queued -> processing`. Existing importer behavior
   then executes for each streamed row exactly as before. It cannot be replayed
   for this logical key after that transition.
10. The observer writes only fixed-size privacy-safe canonical records to a
   mode-0600 system temporary spool. It never writes raw identifiers, XML,
   credentials, URLs, paths, names, or payloads.
11. The spool has explicit row and byte ceilings derived from the approved
    maximum import row count and canonical observation size. It introduces no
    smaller source-file limit than the authorized importer. Exceeding a capture
    ceiling yields
    `overflow`; no prefix is represented as complete.
12. Finalization uses bounded external sort/deduplication and a streamed merge
    with the immutable enrollment query. Memory remains bounded by configured
    chunk size, not source or cohort size.
13. One database transaction on the import connection inserts new enrollments,
    the final header, deterministic chunks of exhaustive physical observations,
    all final privacy-safe fingerprints/counts, the terminal ImportHistory
    compare-and-set, the current importer-equivalent terminal ImportJob/feed
    fields, the authoritative terminal SupplierImportRun fields when applicable,
    and the owner-checked terminal claim compare-and-set.
14. Both source temporary file and privacy-safe spool are removed in `finally`
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
result. For the orchestrated path it atomically writes the authoritative
SupplierImportRun status, bound feed/job, `started_at`, `finished_at`,
`duration_seconds`, `products_seen`, `products_failed`, warning/error counts,
and allowlisted terminal reason. `terminal_qualified` maps ImportHistory to
`finished`. `terminal_frozen` maps it to `finished` when staging completed but
capture qualification froze, and to `failed` when the importer never crossed
into a valid completed result. `terminal_failed` maps it to `failed`.

Only the non-authoritative detailed `SupplierImportRun.report` projection and
derived secondary aggregates may be rebuilt idempotently after commit from the
stored terminal result. Rebuilding may not alter authoritative terminal status,
timestamps, parent bindings, critical counts, or reasons and never reruns the
importer. The legacy path has no SupplierImportRun.

Each terminal compare-and-set must affect exactly one row or throw so the whole
transaction rolls back. A terminal claim with `ImportHistory=started`, or a
terminal ImportHistory without its matching terminal claim outcome, is
forbidden. On rollback no generation, enrollment, observation, terminal
ImportHistory, terminal claim, or final fingerprint/count becomes visible.
Because rollback occurs after `processing`, automatic importer replay remains
forbidden; manual abandoned-processing recovery closes the pair as failed.

### Crash and recovery matrix

`IH` below means the one bound ImportHistory. `None` under evidence means no
committed generation, enrollment, or observation.
`N/A — legacy path has no SupplierImportRun` is abbreviated in cells as
`legacy: N/A`. Every recovery query is bounded and owner-checked.

| Boundary | Path | SupplierImportRun | ImportJob | ImportHistory | Claim | Outbox | Evidence | Allowed recovery | Prohibited actions | Required operator action |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1. Before authorization transaction | both | orchestrated: absent; legacy: N/A | absent | absent | absent | absent | none | repeat the still-valid authorization request | download, importer, or inferred evidence | repeat only the original authorized action |
| 2. Orchestrated authorization transaction rolls back | orchestrated | absent after rollback | absent | absent | absent | absent | none | retry the whole authorization transaction after rollback is confirmed | partial parent, job, claim, or outbox retention | repeat only the original authorized action |
| 3. Legacy authorization/allocation transaction rolls back | legacy | orchestrated: N/A; legacy: N/A | absent after rollback | absent | absent | absent | none | retry the whole authorization/allocation transaction after rollback is confirmed | retaining an orphan ImportJob or pair-null legacy claim | repeat only the original authorized action |
| 4. Authorization commit before Redis publish | both | orchestrated: `pending`; legacy: N/A | orchestrated: absent; legacy: `pending` | absent | `pending_dispatch`; orchestrated pair-null, legacy pair-bound | `pending` | none | publisher or authorized reconciler republishes the same key and deadline | new claim, new legacy ImportJob, download, or importer | none for normal publisher; authorize one reconciler run if needed |
| 5. Redis publish before database acknowledgement | both | orchestrated: `pending`; legacy: N/A | orchestrated: absent; legacy: `pending` | absent | `pending_dispatch` until handler adoption; original allocation unchanged | `leased` or `pending` until adoption | none | handler adopts publication or reconciler republishes the same payload | creating another claim or legacy ImportJob | authorize reconciler only if adoption does not occur |
| 6. Queued delivery before supplier lock | both | orchestrated: `pending`; legacy: N/A | orchestrated: absent; legacy: `pending` | absent | `queued`; orchestrated pair-null, legacy pair-bound | `published` | none | same delivery retries within the fixed transport deadline | source access, importer, or second allocation before lock | none unless transport is exhausted |
| 7. Lock contention | both | orchestrated: `pending`; legacy: N/A | orchestrated: absent or allocated `pending`; legacy: `pending` | absent | unchanged `queued`; pair-null or pair-bound | `published` | none | release the same delivery with the database-clock lease-aware delay | terminal skip, new claim/job, `forceRelease()`, source access, or importer | none while ordinary transport retries remain |
| 8. Lock acquired before orchestrated allocation | orchestrated | `pending` and pair-null | absent | absent | owner-held `queued`, pair-null | `published` | none | owner may run the one allocation transaction | download, ImportHistory start, or processing CAS before allocation | none |
| 9. Active feed resolution fails | orchestrated | terminal `failed` with allowlisted allocation reason | absent | absent | pair-null `terminal_failed` with `capture_allocation_feed_unavailable` | `published` | none | no replay; a new execution requires new authorization | manufacturing a feed/job pair or reusing the terminal key | review feed configuration, then separately authorize a new execution |
| 10. Crash inside orchestrated allocation transaction | orchestrated | `pending` and still pair-null after rollback | absent after rollback | absent | owner-held `queued`, pair-null after rollback | `published` | none | same owner attempt may retry, or same-key retry may allocate after lease expiry | orphan ImportJob, partial pair, source access, or ImportHistory | none unless repeated failure exhausts transport |
| 11. Allocation transaction commits | orchestrated | `pending` and bound to the exact feed/job | `pending`, bound to same supplier/feed | absent | owner-held `queued`, pair-bound with `allocated_at` | `published` | none | same owner proceeds; duplicate delivery reuses the exact pair | second ImportJob, rebinding, or changing `allocated_at` | none |
| 12. Legacy delivery after early allocation | legacy | orchestrated: N/A; legacy: N/A | `pending`, bound at authorization | absent | owner-held `queued`, pair-bound with `allocated_at` | `published` | none | same owner proceeds; duplicate delivery reuses the exact pair | second ImportJob or claim rebinding | none |
| 13. Transaction-aware ImportHistory start rolls back | both | orchestrated: `pending`; legacy: N/A | `pending` | absent after rollback | owner-held `queued`, pair-bound | `published` | none | retry only the start transaction under the same owner | source access, processing CAS, or second history | none |
| 14. ImportHistory start commits before source access | both | orchestrated: `running` with `started_at`; legacy: N/A | `running` | `started`, bound to exact job | owner-held `queued`, pair-bound | `published` | none | same owner proceeds; same-key retry may reuse after valid lease expiry | second history or importer before fingerprint/processing gate | none |
| 15. During source download | both | orchestrated: `running`; legacy: N/A | `running` | `started` | owner-held `queued`, pair-bound | `published` | none | same-key redownload only before `processing` and after valid lease recovery | treating downloaded bytes as evidence or starting a new job | none for ordinary retry |
| 16. Fingerprint bound before parser/staging | both | orchestrated: `running`; legacy: N/A | `running` | `started` | owner-held `queued` with immutable fingerprint | `published` | none | identical bytes may continue to processing CAS | fingerprint replacement, second download after processing, or conflicting importer run | none unless bytes conflict |
| 17. Processing CAS commits | both | orchestrated: `running`; legacy: N/A | `running` | `started` | owner-held `processing`, pair-bound | `published` | none | live owner continues once; crash requires failed recovery | all automatic importer replay and new allocation | authorize abandoned-processing recovery after expiry if owner is lost |
| 18. During first or later staging mutation | both | orchestrated: `running`; legacy: N/A | `running` | `started` | owner-held `processing` | `published` | none committed | live owner continues; otherwise failed recovery only | download retry, importer replay, or partial evidence commit | authorize recovery, then separately authorize any new execution |
| 19. Importer completes before finalization | both | orchestrated: `running`; legacy: N/A | `running`; computed terminal result exists only in bounded local facts | `started` | owner-held `processing` | `published` | none committed | live owner finalizes once; crash uses failed recovery | importer replay or evidence reconstructed from mutable staging | authorize recovery only after owner loss |
| 20. Finalization transaction rolls back | both | orchestrated: `running`; legacy: N/A | `running` | `started` | `processing` | `published` | none committed | abandoned-processing recovery only after rollback and owner loss | importer replay or partial terminal repair | authorize bounded recovery |
| 21. Finalization commits before queue acknowledgement | both | orchestrated: authoritative terminal `completed`, `completed_with_warnings`, or `failed`; legacy: N/A | authoritative terminal `completed`, `completed_with_errors`, or `failed` | `finished` or `failed` | exactly `terminal_qualified`, `terminal_frozen`, or `terminal_failed` according to the committed outcome | `published` | exact qualified set, frozen header-only outcome, or zero-header failed/fingerprint-conflict gap defined below | duplicate delivery returns stored terminal result | any rerun, rebinding, or terminal mutation | none |
| 22. Duplicate after any terminal state | both | orchestrated: unchanged `completed`, `completed_with_warnings`, or `failed`; legacy: N/A | unchanged `completed`, `completed_with_errors`, or `failed` | unchanged `finished` or `failed` | unchanged `terminal_qualified`, `terminal_frozen`, or `terminal_failed` | unchanged `published` or `terminal_failed` | unchanged qualified set, frozen header-only outcome, or zero-header failed/fingerprint-conflict gap | deterministic no-op | source access, importer, new evidence, or terminal rewrite | new authorization only for a genuinely new execution |
| 23. Transport exhaustion while pair-null queued | orchestrated | `pending` | absent | absent | recoverable `queued`, pair-null | `recovery_required` | none | authenticated manual republish of same key and original deadline policy | terminal failure, new claim/job, or source access | authorize one bounded outbox recovery |
| 24. Transport exhaustion while pair-bound queued without history | both | orchestrated: `pending`; legacy: N/A | `pending` | absent | recoverable `queued`, pair-bound | `recovery_required` | none | authenticated manual republish of same key | second ImportJob, terminal skip, or direct importer invocation | authorize one bounded outbox recovery |
| 25. Transport exhaustion while pair-bound queued with started history | both | orchestrated: `running`; legacy: N/A | `running` | `started` | recoverable `queued`, pair-bound | `recovery_required` | none | authenticated manual republish of same key while lease checks pass | second history/job or processing without lock ownership | authorize one bounded outbox recovery |
| 26. Transport-only `failed()` sees queued claim without active ownership | both | orchestrated: unchanged `pending` before history or `running` after history; legacy: N/A | unchanged absent, `pending`, or `running` according to allocation/history boundary | unchanged absent or `started` | unchanged `queued` with all-null ownership tuple | `recovery_required` | none | outbox recovery under the same stable key | claiming original ownership or marking terminal solely because queue delivery exhausted | authorize one bounded outbox recovery |
| 27. In-handle exception while current attempt owns `processing` | both | orchestrated: atomically `failed`; legacy: N/A | atomically `failed` | atomically `failed` | atomically `terminal_failed` with `capture_processing_failed` and cleared ownership | unchanged `published` | none | no replay; later execution needs new authorization | importer replay, republish, or inferred evidence | inspect failure, then separately authorize any new execution |
| 28. Transport-only `failed()` sees `processing` | both | orchestrated: unchanged `running`; legacy: N/A | unchanged `running` | unchanged `started` | unchanged `processing` with complete original ownership tuple | unchanged `published` or already `recovery_required` | none | bounded abandoned-processing recovery after independent owner/lease proof | claiming ownership, direct terminal updates, lock release, or importer replay | authorize the abandoned-processing reconciler after verified expiry |
| 29. Outbox publication attempt 8 for `pending_dispatch` | both | orchestrated: atomically `failed`; legacy: N/A | orchestrated: absent; legacy: atomically `failed` | absent | atomically `terminal_failed` with publication reason | atomically `terminal_failed` | none | no automatic retry; new execution requires new authorization | leaving parent pending or creating an untracked queue job | inspect queue transport, then separately authorize a new execution |
| 30. Outbox publication attempt 8 for recoverable queued claim | both | orchestrated: atomically `failed`; legacy: N/A | pair-bound job atomically `failed`; pair-null orchestrated job remains absent | absent or atomically `failed` if already started | atomically `terminal_failed` with publication-exhausted reason | atomically `terminal_failed` | none | no automatic retry; new execution requires new authorization | stranded `queued` state, new job under old key, or importer replay | inspect queue transport, then separately authorize a new execution |
| 31. Stale queued attempt lease | both | orchestrated: unchanged `pending` before history or `running` after history; legacy: N/A | unchanged absent, `pending`, or `running` according to allocation/history boundary | unchanged absent or `started` | expired `queued`; owner may be replaced only after Redis and database lease proof | unchanged `published` or `recovery_required` | none | same key may replace attempt ownership and continue pre-processing | live-owner takeover, `forceRelease()`, second parent, or second history | none for ordinary retry; bounded outbox recovery if marked required |
| 32. Stale processing attempt lease | both | orchestrated: unchanged `running`; legacy: N/A | unchanged `running` | unchanged `started` | expired `processing` | unchanged `published` or `recovery_required` | none | abandoned-processing recovery only | owner replacement, download, importer replay, or direct terminal repair | authorize bounded abandoned-processing recovery |
| 33. Different source fingerprint under same key | both | orchestrated: atomically `failed`; legacy: N/A | atomically `failed` without importer replay | atomically `failed` when already started | atomically `terminal_frozen` with first digest retained | unchanged `published` | no generation | no replay; new logical execution requires new authorization | digest replacement, parser/staging, or evidence generation | investigate source identity, then authorize a new execution if appropriate |

The matrix uses only canonical states. Its terminal outcome mapping is exact:

- `terminal_qualified` requires one qualified immutable generation, its complete
  enrollment and observation set, successful/warning parent outcomes allowed by
  the qualification policy, and no terminal reason code;
- `terminal_frozen` permits no qualified generation. When importer staging
  completed and final capture facts are safe, it has exactly one immutable
  header with `qualification_state=frozen`, its exact reason codes, zero new
  enrollment rows, and zero observation rows. For the allowlisted pre-mutation
  fingerprint-conflict outcome it has zero generation rows. In both cases its
  applicable parents/history are terminal and its exact frozen reason is
  persisted; and
- `terminal_failed` permits no qualified generation. It requires the applicable
  failed parent/history state and exact allowlisted failure/gap reason; partial
  staging may remain only as failed-import state and is never promoted to
  evidence.

Rows 21 and 22 apply that mapping literally. Descriptive placeholders are not
persisted states and cannot be used by implementation or recovery code.

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

The coordinator acquires `Cache::lock(key, 4320)->get()` and completes the
database ownership CAS within 60 seconds before
`ImportHistory::startForImport()`. It holds the returned owner token until
snapshot finalization and the import terminal transition are complete. The
4,320-second relative Redis TTL exceeds the 4,200-second MySQL ownership lease;
neither is extended. It releases only through owner-checked
`release()` in `finally`; `forceRelease()` is prohibited. The cache backend must
be the deployment's atomic Redis lock store. Capture enablement fails closed on
another backend or unavailable ownership checks. The implementation verifies
`isOwnedByCurrentProcess()` before each staging chunk and again before snapshot
commit. Loss or unknown ownership aborts before further writes and prevents a
qualified header. Before `processing`, the queued execution may later reacquire
and continue. At or after `processing`, the same key may not rerun the importer
and must use abandoned-processing recovery.

Lock contention does not authorize an overlapping import, a terminal skipped
run, or capture. Both paths keep the same claim and allocation state unchanged
and release the current queue delivery with the database-clock lease-aware
delay defined above. Neither path creates another ImportJob or ImportHistory or
performs source, staging, snapshot, Product, or Catalog Sync writes. The
transport-only `failed()` hook must then apply only its owner-independent
outbox-state matrix above if transport is exhausted.
`force=true` may bypass only the dispatch pre-check; it may not release or
bypass a lock owned by another process. Before source download, the coordinator
also requires the existing ImportHistory activity inspector to report no other
active or unknown attempt for that supplier. This covers a stale
pre-coordinator worker conservatively. Capture activation is prohibited until
an implementation audit proves that all real XML callers use this boundary.

A normal exit releases the owner lock. A worker crash leaves the lock held only
until its 4,320-second relative TTL expires. The dedicated Redis
`retry_after` is exactly 3,900 seconds, strictly longer than the 3,600-second
effective maximum job timeout and shorter than the 4,200-second database lease
and 4,320-second lock TTL. At the worst permitted 60-second bootstrap, the DB
lease expires no later than 4,260 seconds after Redis acquisition; its required
plus-30 contention delay ends no later than 4,290 seconds, leaving at least 30
seconds of Redis-lock padding. A queue retry cannot enter while the old lease
is owned. After expiry,
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
| 1 | Local design independent approval | complete local six-commit design candidate | independent Security, Database and Catalog Sync Safety reviewers | review only | `APPROVED` verdict for exact diff | remediate locally; no push | 2 |
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
database/migrations/*_add_supplier_feed_allocation_constraints_to_import_jobs_table.php
.env.example
config/queue.php
docker-compose.yml
app/Jobs/RunSupplierImportJob.php
app/Jobs/ProcessXmlSupplierFeed.php
app/Models/SupplierImportExecutionClaim.php
app/Models/SupplierImportDispatchOutbox.php
app/Models/SupplierOfferSnapshotGeneration.php
app/Models/SupplierOfferSnapshotEnrollment.php
app/Models/SupplierOfferSnapshotObservation.php
app/Data/Suppliers/Onboarding/SnapshotSourceIdentity.php
app/Repositories/Suppliers/ImmutableSupplierOfferSnapshotRepository.php
app/Repositories/Suppliers/SupplierImportExecutionClaimRepository.php
app/Repositories/Suppliers/SupplierImportDispatchOutboxRepository.php
app/Repositories/Suppliers/SupplierImportAllocationRepository.php
app/Repositories/Suppliers/SupplierImportStateInvariantRepository.php
app/Repositories/Imports/TransactionalImportGenerationStartRepository.php
app/Repositories/Imports/TransactionalImportTerminalRepository.php
app/Services/Suppliers/SupplierImportExecutionLock.php
app/Services/Suppliers/SupplierImportExecutionCoordinator.php
app/Services/Suppliers/SupplierImportInHandleFailureService.php
app/Services/Suppliers/SupplierImportDispatchOutboxPublisher.php
app/Services/Suppliers/SupplierImportTransportFailureService.php
app/Services/Suppliers/SupplierImportQueueTimingValidator.php
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
tests/Feature/SupplierImportAllocationTest.php
tests/Feature/SupplierImportQueueTimingTest.php
tests/Feature/SupplierImportFailedCallbackTest.php
tests/Feature/SupplierImportMysqlRedisRecoveryTest.php
tests/Feature/OperationalSupplierOfferEvidenceProducerTest.php
tests/Unit/Suppliers/SupplierOfferSnapshotFingerprintTest.php
tests/Feature/SupplierOfferLifecycleDocumentationContractTest.php
```

The future schema migration must add `allocated_at`, the exact pair-null,
pair-bound, ownership-tuple and outbox checks, the unique claim-to-ImportJob
allocation, the composite ImportJob supplier/feed ownership index, and the
outbox recovery/terminal fields defined above. The owner repository must use
one MySQL-generated timestamp CAS for the 4,200-second database lease and fail
closed before work on every unsuccessful CAS.

The later runtime implementation must add the dedicated
`redis_supplier_import` connection, `supplier-imports` queue,
`SUPPLIER_IMPORT_QUEUE_RETRY_AFTER=3900`, and dedicated Docker worker while
leaving the shared `REDIS_QUEUE_RETRY_AFTER=1300` and unrelated worker queues
unchanged. Both job paths and outbox payload publication must route explicitly
to that connection/queue. Startup validation must prove the exact
`3600 < 3900 < 4200 < 4320` hierarchy, 60-second bootstrap bound, and queue
isolation before capture can be enabled.

The coordinator and in-handle failure service own the raw-token/Redis-lock
`try/catch/finally` closeout. `SupplierImportTransportFailureService` is used by
newly deserialized `failed()` only for owner-independent outbox transport
recovery. The state-invariant repository owns cross-record claim/outbox/parent
transactions; the outbox and terminal repositories enforce canonical state
checks and terminal ownership clearing. MySQL/Redis integration tests must
prove every 20-item acceptance criterion above. These are planned
implementation requirements only; this documentation commit changes no
runtime, queue, Docker, environment, schema, worker, or feature-flag value.

Implementation remains split by the 49 checkpoints above. Review, push/PR,
merge, deployment, enablement, import, candidate preparation, candidate
approval, preview, result review, and closeout never share one authorization.

## Non-approval Boundary

This design does not authorize a migration, model, parser refactor, producer,
import hook, feature flag, real evidence candidate, supplier import, APCOM
schedule change, Catalog Sync action, Product mutation, retention cleanup,
deployment, or C3D.1 operational preview. Supplier #3 selection must not begin
while this prerequisite remains unresolved.

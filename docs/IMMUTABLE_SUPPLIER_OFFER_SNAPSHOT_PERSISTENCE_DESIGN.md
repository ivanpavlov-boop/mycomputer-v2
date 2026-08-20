# Immutable Supplier Offer Snapshot Persistence Design

## Status And Scope

Phase 9C.6.5C.3D.1-PRE.A is a documentation-only prerequisite. It resolves the
architecture questions behind `BLOCKED_HISTORICAL_SOURCE_CONTRACT_REQUIRED`,
but it does not add a migration, model, parser, import hook, feature flag,
evidence file, or operational preview. No existing data is qualified by this
design. No evidence candidate exists and no operational preview is authorized.

The design is supplier-generic where the existing importer already provides a
supplier and feed boundary. APCOM is the first bounded consumer. V1 through V3
remain historical contracts. V4 remains the current semantic authority.

The read-only C3D preview implementation was merged through PR #210 and
deployed at `c22fc9a8dddf3c6778ab0b88e5a50cbc02fe3f21`. This persistence design
is a local documentation-only eleven-commit follow-up pending fresh independent
complete-branch review. Its
migration, parser/capture implementation, evidence preparation, operational
execution, and closeout are not approved or implemented.
C3D.1 remains blocked and Supplier #3 remains unselected and unstarted.

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
three append-only authorization/audit tables, three append-only evidence tables,
and reuses `import_histories.id` as the attempt sequence marker:

1. `supplier_import_execution_claims` owns one stable logical execution across
   queue retry and redelivery. It coordinates an attempt but is not evidence.
2. `supplier_import_dispatch_outbox` durably owns exactly one initial queue
   publication for the authorized claim. It is mutable coordination state, not
   lifecycle evidence and not import authorization.
3. `supplier_import_dispatch_recovery_authorizations` stores one immutable,
   human-approved recovery action for one exact due claim/outbox state.
4. `supplier_import_dispatch_recovery_results` stores the immutable consumption
   result without mutating the authorization row.
5. `supplier_import_cohort_authorization_members` stores the immutable,
   privacy-safe hashed seed set authorized from one capture-start MySQL
   snapshot. It is coordination proof, not lifecycle evidence.
6. `supplier_offer_snapshot_generations` stores one immutable final capture
   header for one ImportHistory generation.
7. `supplier_offer_snapshot_enrollments` stores the first immutable enrollment
   of every hashed offer identity in a supplier/source cohort.
8. `supplier_offer_snapshot_observations` stores one physical `present=true` or
   `present=false` observation for every identity enrolled for that generation.

The immutable enrollment layer is mandatory. Mutable staging can identify cohort
membership at a capture boundary, but it cannot preserve that membership after
a row is removed. An enrolled identity therefore remains in every later
generation in the same supplier/source cohort, even after it disappears from
`supplier_products` or `product_supplier_offers`.

There is no mutable current-snapshot row. Before source work, one immutable
capture-start authorization header on the claim and its complete hashed seed
membership commit atomically. A complete header, newly discovered enrollments,
all observations, the terminal ImportHistory transition, and the terminal
execution-claim transition are then committed atomically after source
traversal. A failed capture may retain the authorization audit but persist one
final frozen header without observations only when its complete final facts are
safe. If final persistence fails, no evidence or terminal transition commits.
The abandoned-processing recovery below then closes the ImportHistory and claim
as failed without a header. A missing header is a sequence gap and never means
absence.

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
one pending initial-dispatch outbox row in one database transaction. That
transaction derives `created_at` from MySQL `UTC_TIMESTAMP(6)` and writes the
outbox's immutable `transport_deadline_at` as exactly
`TIMESTAMPADD(HOUR, 24, UTC_TIMESTAMP(6))` from the same statement time. It does
not resolve a feed or create an ImportJob. For every legacy XML entry point,
the explicit operator or scheduler action creates its already-known ImportJob,
pair-bound `pending_dispatch` claim, and exactly one pending initial-dispatch
outbox row with the same database-clock deadline contract in one transaction
through the shared allocation repository. If either transaction rolls back,
none of its parent, claim, or outbox rows exists.

Only after commit may an immediate outbox publisher attempt Redis publication.
The committed outbox row, not Redis and not
`queue.connections.redis.after_commit`, is the durable handoff. Every publish
or republish serializes the original claim ID and exact logical key. Queue retry
and redelivery preserve them. A genuinely new, separately authorized legacy
operator action creates a fresh ImportJob, claim, outbox row, and key; no
publisher, reconciler, or `handle()` method generates a key.

### Claim and attempt ownership

Both handlers must first pass the durable delivery-admission transaction
defined below and then enter the common owner-checked
`SupplierImportExecutionCoordinator` before the orchestrator or XML engine.
Each admitted `handle()` invocation creates a separate random attempt token,
acquires the owner-token Redis lock `supplier_import:<supplier_id>` with a
4,320-second relative TTL, and stores only the token's SHA-256 hash. The raw
attempt token and lock object remain only in that active `handle()` process and
are never serialized, persisted, or logged.

Within at most 60 seconds after Redis acquisition, the coordinator opens one
short owner-acquisition transaction. Every transaction touching both records
locks the canonical outbox row first and the claim row second; parent rows, when
needed, follow in their documented order. The transaction verifies the
serialized key, supplier, path, parent references, exact prior
`claim.state = queued`, exact `outbox.state = published`, allocation
preconditions, and all-null prior ownership tuple. Only then does its
owner-checked claim compare-and-set write:

```sql
active_attempt_token_hash = :lowercase_sha256,
claimed_at = UTC_TIMESTAMP(6),
attempt_lease_expires_at = TIMESTAMPADD(SECOND, 4200, UTC_TIMESTAMP(6))
```

MySQL statement time is stable, so both timestamps derive from one database
clock. No allocation, source download, parsing, ImportHistory start, staging,
or other work begins until this transaction commits and the claim CAS affects
exactly one row. An outbox in `recovery_required`, `leased`, or
`terminal_failed`, zero affected rows, an exception, deadlock, timeout,
rollback, or a bootstrap window greater than 60 seconds requires owner-checked
Redis `release()` and immediate return with no work. Redis and MySQL wall clocks
are never compared: Redis owns only the relative lock TTL, while MySQL UTC is
authoritative for durable lease and recovery decisions.

The active attempt may proceed only while it owns the complete database
ownership tuple and Redis lock and the canonical outbox remains `published`.
All three conditions are rechecked under the fixed outbox-then-claim lock order
before the non-repeatable mutation boundary and final snapshot commit. A stale
handler encountering `recovery_required` exits without allocation, download,
importer execution, claim termination, or evidence creation.

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
CONSTRAINT chk_import_execution_claim_path_parent CHECK (
    (
        BINARY execution_path = BINARY _ascii'orchestrated'
        AND OCTET_LENGTH(execution_path) = 12
        AND supplier_import_run_id IS NOT NULL
    )
    OR
    (
        BINARY execution_path = BINARY _ascii'legacy_xml'
        AND OCTET_LENGTH(execution_path) = 10
        AND supplier_import_run_id IS NULL
        AND supplier_feed_id IS NOT NULL
        AND import_job_id IS NOT NULL
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
CONSTRAINT chk_import_claim_cohort_authorization_tuple CHECK (
    (
        cohort_authorization_version IS NULL
        AND cohort_authorized_at IS NULL
        AND cohort_seed_count IS NULL
        AND cohort_seed_fingerprint IS NULL
    )
    OR
    (
        cohort_authorization_version IS NOT NULL
        AND cohort_authorized_at IS NOT NULL
        AND cohort_seed_count IS NOT NULL
        AND cohort_seed_fingerprint IS NOT NULL
    )
),
CONSTRAINT chk_import_claim_cohort_seed_hash CHECK (
    cohort_seed_fingerprint IS NULL
    OR (
        OCTET_LENGTH(cohort_seed_fingerprint) = 64
        AND REGEXP_LIKE(
            cohort_seed_fingerprint,
            _ascii'^[0-9a-f]{64}$',
            'c'
        )
    )
),
CONSTRAINT chk_import_claim_cohort_auth_version CHECK (
    cohort_authorization_version IS NULL
    OR cohort_authorization_version = 'supplier_offer_cohort_v1'
),
CONSTRAINT chk_import_claim_cohort_auth_time CHECK (
    cohort_authorized_at IS NULL
    OR (
        allocated_at IS NOT NULL
        AND cohort_authorized_at >= allocated_at
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
        AND cohort_authorization_version IS NOT NULL
        AND cohort_authorized_at IS NOT NULL
        AND cohort_seed_count IS NOT NULL
        AND cohort_seed_fingerprint IS NOT NULL
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

Application validation additionally requires
`cohort_authorization_version = 'supplier_offer_cohort_v1'`, exact equality
between `cohort_seed_count` and the immutable authorization-member count, and
the recomputed sorted-member fingerprint before source work or `processing`.
The four authorization fields are write-once as one tuple. It also requires a
pair-bound `terminal_failed`
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
retryUntil() = absent; the jobs must not define or return it
```

The installed Laravel Worker gives a non-null `retryUntil()` precedence over
`$tries`; the two are therefore not used as cumulative limits. `$tries = 8`
remains Laravel's queue-payload backstop precisely because `retryUntil()` is
absent. The 24-hour limit is instead the immutable application invariant
`supplier_import_dispatch_outbox.transport_deadline_at`. The original
authorization transaction derives it from MySQL UTC as described above. Every
serialized or republished payload copies that timestamp only as a
non-authoritative reference. Queue retry, `release()`, reconciliation, and
manual republication must never extend, regenerate, replace, or reset it.

Before supplier-lock acquisition, allocation, download, importer execution, or
staging mutation, the beginning of `handle()` calls one delivery-admission
service. In the fixed outbox-then-claim lock order, its short transaction loads
the canonical rows, verifies the payload/key/parent binding, reads MySQL
`UTC_TIMESTAMP(6)`, and increments the durable outbox
`delivery_attempt_count` exactly once for that dequeued invocation. Merely
observing a canonical `queued/published` delivery never refreshes
`delivery_watchdog_at`; only a later successfully acknowledged publication may
re-establish it. Supplier-lock contention changes neither the watchdog nor the
immutable transport deadline, any counter beyond the one admission increment,
the database ownership lease, or the Redis lock TTL. Counts one
through seven may proceed only when the deadline is still in the future and the
claim/outbox pair is otherwise eligible. The eighth cumulative logical
delivery, or any delivery at or after `transport_deadline_at`, is irrecoverable
transport exhaustion before the supplier lock and performs no source or
importer work. For a pre-processing `queued/published` pair with an all-null
ownership tuple, the delivery-admission transaction locks outbox, claim, and
applicable ImportHistory, ImportJob, and SupplierImportRun parents in that
fixed order. It atomically changes both rows to
`terminal_failed/terminal_failed`, closes applicable parents as failed, and
records `transport_delivery_budget_exhausted` for delivery eight or
`transport_deadline_expired` for deadline expiry. It creates no generation,
enrollment, observation, absence evidence, staging mutation, or importer side
effect. A `processing` or terminal claim is never replayed or regressed. A
delivery that sees a complete expired pre-processing owner must not return
normally into an unbounded state: while holding a newly acquired owner-checked
supplier lock it may invoke
`ExpiredQueuedImportTerminalRepository::resolveExpiredQueuedOwnership()`, or it
must leave the durable watchdog candidate intact for the CLI reconciler. It
cannot fabricate the lost raw attempt token.

A lock-contention `release()` consumes the delivery count already recorded for
that invocation. A fresh queue payload created by separately authorized
republication retains the original deadline and remaining cumulative delivery
budget. `recovery_required` is recoverable only while both boundaries remain
valid. For this decision, remaining delivery budget means
`delivery_attempt_count <= 6`, so at least one future delivery can increment to
an admissible count from one through seven; count seven has no future
work-admissible delivery because the next invocation is terminal delivery eight.
`MaxAttemptsExceededException` reaches the transport-only `failed()`
matrix below and cannot close processing. Queue-delivery attempts, the single
logical transition into `processing`, and the outbox's maximum eight
publication attempts are three independent durable counters. Only the
successful `queued -> processing` CAS counts as the logical processing attempt.

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

The authoritative clock is MySQL `UTC_TIMESTAMP(6)`. Delivery admission has
already consumed one durable delivery attempt and verified the immutable
transport deadline; contention never resets either boundary. For a valid lease whose
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
| before allocation | current raw token, Redis lock, `claim.state = queued`, and `outbox.state = published` prove the fixed-order transaction may clear ownership and set only the outbox to `recovery_required` with `handle_pre_allocation_failed` | no allocation, download, importer or evidence |
| after allocation but before source download | same proof; retain the write-once allocation, clear ownership, keep claim `queued`, and change outbox `published -> recovery_required` with `handle_pre_download_failed` | no download, importer or evidence |
| during download | same proof; retain allocation/history, clear ownership, keep claim `queued`, and change outbox `published -> recovery_required` with `handle_download_failed` | discard temporary bytes; no importer or evidence |
| after fingerprint but before `processing` | same proof; retain the first fingerprint, clear ownership, keep claim `queued`, and change outbox `published -> recovery_required` with `handle_pre_processing_failed` | no staging replay and no evidence |
| deterministic source fingerprint conflict before `processing` | same proof; atomically close applicable parents/history and claim as `terminal_frozen` with `capture_source_fingerprint_conflict`; clear ownership | no importer and no generation |
| after `processing` | current raw token, Redis lock, exact processing state and parent locks atomically close applicable run/job/history and claim as `terminal_failed`; clear ownership | never replay; no qualified snapshot |
| during partial staging mutation | same processing closeout with `capture_processing_failed`; preserve partial staging as failed-import state | no cleanup/replay and no qualified snapshot |
| during final transaction | first prove that the transaction rolled back and no terminal state/generation committed, then use processing closeout with `capture_finalization_failed`; if the terminal transaction committed, return its stored result | no partial evidence repair or replay |
| owner-token mismatch | no claim, parent, outbox or evidence mutation | wait for lease-expiry recovery |
| Redis lock loss or unknown ownership | no claim, parent, outbox or evidence mutation | wait for lease-expiry recovery |

All reason codes are fixed, privacy-safe values. Before `processing`, the
recoverable transaction may run only with the original token and lock, only
while delivery budget and deadline remain valid, and must clear the complete
ownership tuple atomically. If either boundary is already exhausted, the same
fixed-order transaction uses `transport_delivery_budget_exhausted` or
`transport_deadline_expired` and closes claim, outbox, and applicable parents
as terminal failed instead of creating `recovery_required`. After `processing`, the handler
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
| terminal claim in any canonical terminal state | no-op; preserve the canonical terminal claim/outbox mapping, parents and evidence |
| outbox `pending` or `leased` | no-op; no acknowledged payload may claim execution, and publisher/reconciler lease recovery remains authoritative |
| `queued` with outbox `published` and no active ownership tuple while deadline remains valid and `delivery_attempt_count <= 6` | under the fixed outbox-then-claim locks, CAS outbox `published -> recovery_required`, write `transport_callback_recovery_required` and timestamp, and preserve claim/parents |
| `queued` with outbox `published` and no active ownership tuple after deadline or delivery-budget exhaustion | under the full outbox-claim-parent lock order, atomically close claim, outbox, and applicable parents with `transport_deadline_expired` or `transport_delivery_budget_exhausted` |
| `queued` with a complete unexpired ownership tuple and outbox `published` | no-op; preserve active owner and published outbox and request later operator inspection |
| `queued` with a complete expired ownership tuple and outbox `published` | no-op in the deserialized callback; preserve the tuple and durable watchdog candidate for the supplier-locked expired-owner repository |
| `processing` with outbox `published` | no-op; preserve both records and request abandoned-processing recovery after verified expiry |
| outbox already `recovery_required` or `terminal_failed` | no-op; no stale payload may acquire ownership or mutate the claim |
| owner tuple, key, parent or cross-state mismatch | fail closed with no mutation and request explicit reconciliation |

The future owner-independent pre-processing contract is
`ExpiredQueuedImportTerminalRepository::resolveExpiredQueuedOwnership()`. It
never accepts or fabricates the lost raw attempt token. The caller first
acquires a new owner-checked supplier Redis lock; the repository then reads
MySQL `UTC_TIMESTAMP(6)` and transactionally locks `outbox -> claim ->
applicable parents`. It accepts only literal `queued/published`, a complete
ownership tuple whose `attempt_lease_expires_at < UTC_TIMESTAMP(6)`, no
generation or evidence, authoritative parent bindings, and no conflicting
terminal state. Its compare-and-set includes the complete persisted expired
tuple; every state, token-hash, timestamp, key, parent, evidence, or lock
mismatch affects zero rows.

With a future deadline and `delivery_attempt_count <= 6`, the repository clears
the expired ownership tuple, changes `published -> recovery_required`, keeps the
claim `queued`, clears `delivery_watchdog_at`, and records
`queued_ownership_lease_expired`. The existing bounded same-key recovery path
may then republish. If delivery eight has been consumed or the immutable
deadline has expired, the same locked transaction clears ownership and changes
claim and outbox to `terminal_failed`, closes every applicable
SupplierImportRun, ImportJob, and ImportHistory as failed, and records
`transport_delivery_budget_exhausted` or `transport_deadline_expired`. Neither
outcome downloads, runs the importer, mutates staging, creates a generation,
enrollment, observation, absence fact, or recommendation.

Race outcomes are exact. A complete unexpired owner is active and rejects the
repository. Normal live-owner finalization wins by changing state or tuple, so
the expired-owner CAS affects zero rows. `processing/published` belongs only to
`AbandonedSupplierImportTerminalRepository::failExpiredProcessing()` and is
never handled here. Another duplicate delivery or stale-payload reconciler must
win the same supplier lock and complete-tuple CAS; the loser re-reads the
canonical result and performs no work. A payload arriving after terminalization
returns the stored terminal no-op before source access.

The separately authorized manual outbox reconciler may lease the same
`recovery_required` event and publish a fresh payload with the same claim ID/key,
the same immutable `transport_deadline_at`, and the remaining durable delivery
budget only while the deadline remains future and
`delivery_attempt_count <= 6`. It creates no claim,
ImportJob, authorization, or replacement outbox event. It must complete
`recovery_required -> leased -> published` before the newly published handler
may pass delivery admission or acquire ownership. If the deadline expires or
the delivery budget becomes exhausted after `recovery_required` was committed,
the reconciler instead locks outbox, claim, and applicable parents and
atomically changes `queued/recovery_required` to
`terminal_failed/terminal_failed` with the exact transport reason. There is no
state for which republication is forbidden but terminal resolution is absent.
Queue-delivery, logical-processing, and outbox-publication attempt accounting
remain separate and none resets another.

Timeout kill, OOM, host termination, worker crash, or another hard process
termination may bypass both `catch` and `finally`. `failed()` is not assumed to
hold ownership proof in that case. The Redis TTL and database ownership lease
must expire; only the separately authorized abandoned-processing recovery may
then acquire the supplier lock and row locks, prove expiry/current state, and
perform terminal recovery without importer replay.

Outbox publication attempts remain separate. Attempts one through seven may
publish successfully or remain eligible for bounded retry. Publication attempt
eight may also succeed and acknowledge `published`. Only a failed eighth
publication attempt is terminal and operator-visible. For pair-null
`pending_dispatch`, one transaction then marks outbox and claim
`terminal_failed` and the orchestrated run failed; legacy is normally
pair-bound and closes its pending ImportJob too. For a `recovery_required`
queued claim, the same supplier-locked transaction closes the claim, bound
ImportJob, any started ImportHistory, and authoritative SupplierImportRun fields
as `terminal_failed` with `dispatch_publication_attempts_exhausted`. A stale
eighth lease whose publication cannot be proved is a failed eighth attempt and
terminalizes; any payload actually accepted by Redis later observes the
terminal claim and no-ops. An irreconcilable claim/key/parent acknowledgement
mismatch uses the separate `dispatch_publication_mismatch` reason. No ninth
publication attempt or source/import/evidence work is permitted. A mismatch in
the terminal transaction rolls back everything and requires the explicit
terminal-resolution command; it is never left silently pending or queued. Any
later import requires a new operator-authorized key.

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
effect, the coordinator owner-checks the supplier lock, locks outbox then claim,
requires `outbox.state = published`, and compare-and-sets `queued -> processing`.
This is the non-repeatable mutation boundary. A stale handler that observes
`recovery_required`, `leased`, or `terminal_failed` exits without changing
either row. The current importer is not idempotent merely because the source
fingerprint is equal: it increments `processed_rows`/`failed_rows`, inserts
failure rows, and mutates staging incrementally. After `processing` begins, no
queue retry, redelivery, publisher, or reconciler may call `XmlImportEngine`
again for that logical key. Partial staging remains failed-import state under
existing importer semantics; counters and failure rows are neither reset nor
duplicated by replay.

An abandoned `processing` claim becomes a visible fail-closed gap. The CLI-only
abandoned-owner API acquires a new owner-checked supplier Redis lock; it never
reuses the live-owner terminal API and never requires or bypasses the lost raw
attempt token. Using MySQL `UTC_TIMESTAMP(6)`, one transaction locks outbox,
claim, and applicable parents in that order and requires literal
`processing/published`, a complete persisted ownership tuple,
`attempt_lease_expires_at < UTC_TIMESTAMP(6)`, no terminal generation/header,
and exact parent/fingerprint bindings. It compare-and-sets the complete expired
persisted tuple, clears ownership, marks applicable SupplierImportRun,
ImportJob, ImportHistory, and claim failed, leaves the canonical outbox
`published`, and records `processing_lease_abandoned`. Any state, lease,
fingerprint, parent, generation, outbox, or supplier-lock mismatch affects zero
rows. It creates no header, enrollment, observation, absence, Product write,
Catalog Sync action, or automatic replacement import and never replays
`XmlImportEngine`. Only a new explicit operator authorization may create a new
logical key.

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
| `execution_path` | `VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin` | not null | Byte-exact `orchestrated` or `legacy_xml`; write-once | public contract |
| `state` | varchar(32) ASCII | `pending_dispatch` | Closed state machine above | public contract |
| `active_attempt_token_hash` | `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin` | nullable | SHA-256 of current in-memory attempt token | pseudonymous |
| `source_fingerprint` | `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin` | nullable | First accepted exact-byte digest; immutable once set | pseudonymous |
| `cohort_authorization_version` | varchar(32) ASCII | nullable before capture authorization | Exact allowlisted capture-start policy, initially `supplier_offer_cohort_v1`; write-once | public contract |
| `cohort_authorized_at` | timestamp(6) | nullable before capture authorization | MySQL UTC instant of the committed consistent-snapshot authorization; write-once | operational metadata |
| `cohort_seed_count` | unsigned bigint | nullable before capture authorization | Exact immutable authorization-member row count | aggregate |
| `cohort_seed_fingerprint` | `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin` | nullable before capture authorization | Hash of the sorted capture-start authorized seed hashes; write-once | pseudonymous |
| `terminal_reason_code` | varchar(96) ASCII | nullable | Stable allowlisted reason for terminal frozen/failed | public contract |
| `claimed_at` | timestamp(6) | nullable | MySQL UTC current-attempt claim instant written in the owner CAS | operational metadata |
| `attempt_lease_expires_at` | timestamp(6) | nullable | Upper bound for current queued/processing attempt ownership | operational metadata |
| `processing_started_at` | timestamp(6) | nullable | Write-once non-repeatable mutation boundary | operational metadata |
| `terminal_at` | timestamp(6) | nullable | Canonical MySQL UTC terminal instant | operational metadata |
| `created_at`, `updated_at` | timestamps | database managed | Coordination audit only | operational metadata |

`chk_import_execution_claim_path_parent` is the same-row schema authority for
the only two execution paths. An orchestrated claim requires a non-null
`supplier_import_run_id`; a legacy claim forbids that parent and requires both
`supplier_feed_id` and `import_job_id`, which also makes it pair-bound through
`chk_import_execution_claim_allocation_pair`. An orchestrated
`pending_dispatch` or `queued` claim may be pair-null. Every processing claim
requires the complete allocation tuple, a bound ImportHistory, source
fingerprint, complete capture-start cohort authorization, all three ownership
fields, and write-once `processing_started_at`. A claim may bind at most one
ImportHistory. Terminal qualified/frozen requires that history; terminal
failure before successful allocation may have neither allocation nor history.
Cross-record ownership remains transactional; the same-row checks and
owner-checked application validation do not pretend to validate another table.

`execution_path` is byte-exact and immutable after INSERT. The named future
MySQL `BEFORE UPDATE` trigger
`trg_import_execution_claim_path_immutable` compares the old and new values as
binary strings and raises an error on any byte change. The model exposes no
setter after creation, and every repository state transition includes the
original path in its immutable-column guard. Ordinary permitted state updates
must still succeed when the path bytes are unchanged. Uppercase, mixed case,
leading or trailing whitespace, tabs, newlines, trailing ASCII spaces, null
bytes, Unicode lookalikes, normalized alternatives and every unsupported value
fail the byte-length and binary comparison above.

Database uniqueness is deliberately narrow: the logical key is globally
unique; `supplier_import_execution_claims.supplier_import_run_id`,
`supplier_import_execution_claims.import_job_id`, and
`supplier_import_execution_claims.import_history_id` are each unique when
non-null; generation claim and ImportHistory references are each unique; and a
claim row can reach terminal state once. MySQL permits multiple `NULL` values in
each nullable unique index, so legacy claims keep a null run parent while every
non-null SupplierImportRun belongs to exactly one orchestrated claim. A
separately authorized legacy re-execution creates a fresh ImportJob rather than
sharing one across claims.

Claim rows have no DELETE, prune, reuse, or key-rotation path in this phase.
Their retention is indefinite while any outbox, ImportHistory, or immutable
generation reference exists, and every parent/child foreign key is `RESTRICT`.
A later retention design must be dry-run-first and may not erase the durable
identity needed to explain a published or terminal execution.

### Capture-start authorization-member data dictionary

Proposed additive append-only coordination table:
`supplier_import_cohort_authorization_members`. These rows preserve the exact
hashed seed membership selected before source work so retry or finalization
never reconstructs authorization from mutable application state. They are not
snapshot observations, absences, lifecycle evidence, or import authorization.

| Column | Type | Null/default | Purpose and invariant | Privacy |
| --- | --- | --- | --- | --- |
| `id` | unsigned bigint | not null | Surrogate authorization-member key | internal |
| `supplier_import_execution_claim_id` | unsigned bigint | not null | Exact owning logical execution | internal |
| `supplier_sku_hash` | `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin` | not null | Domain-separated capture-start seed identity | pseudonymous |
| `created_at` | timestamp(6) | database current time | Authorization audit time only | operational metadata |

There is no `updated_at`. The pair
(`supplier_import_execution_claim_id`, `supplier_sku_hash`) is immutable and
unique. The table forbids UPDATE and DELETE, uses `RESTRICT` to the claim, and
contains no raw SKU, EAN, MPN, source row, name, URL, path, credential, price,
quantity, or XML. The authorization transaction inserts the complete sorted set
and writes the claim's version, timestamp, count, and fingerprint in one commit.
An existing partial or mismatching member set is an integrity failure and is
never repaired from current application rows.

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
19. every one of the 36 crash rows uses canonical claim/outbox/parent states;
20. every claim/outbox cross-record mismatch fails closed;
21. the installed Laravel Worker honors `$tries = 8` because neither job
    defines or returns `retryUntil()`;
22. the eighth cumulative logical delivery exhausts before supplier-lock or
    source work, even when a republished payload has a fresh Laravel attempt
    number;
23. MySQL UTC deadline expiry may exhaust transport before delivery eight;
24. `release()`, queue redelivery, reconciliation, and manual republication
    preserve both the original deadline and cumulative delivery count;
25. transport-only `failed()` racing queued-to-processing ownership acquisition
    produces either a still-recoverable `queued/recovery_required` with no
    owner, an irrecoverable `terminal_failed/terminal_failed`, or
    `processing/published`, never a mixed state;
26. reconciliation racing a stale payload permits only an acknowledged
    `recovery_required -> leased -> published` path while both transport
    boundaries remain valid, otherwise it commits the exact terminal pair;
27. live terminal finalization racing transport recovery commits exactly one
    canonical outcome: processed terminal claim with `published` outbox or
    pre-processing terminal claim with `terminal_failed` outbox;
28. duplicate delivery while another owner is processing cannot mutate the
    claim, outbox, parents, or evidence; and
29. acknowledged recovery publication permits exactly one successful owner
    acquisition and every stale competitor exits without work;
30. direct delivery-eight exhaustion atomically produces
    `terminal_failed/terminal_failed` with
    `transport_delivery_budget_exhausted`;
31. direct MySQL-deadline exhaustion atomically produces
    `terminal_failed/terminal_failed` with `transport_deadline_expired`;
32. `queued/recovery_required` republishes only while the deadline is future and
    `delivery_attempt_count <= 6`, leaving one work-admissible delivery, and
    otherwise atomically terminalizes claim, outbox, and applicable parents;
33. live-owner finalization requires the raw token, matching hash, currently
    owned Redis lock, unexpired tuple, and literal `processing/published`;
34. abandoned-owner recovery requires a newly acquired supplier lock and an
    expired complete persisted tuple but never the lost raw token;
35. abandoned-owner recovery racing the live owner or finalization affects zero
    rows unless it proves the exact expired `processing/published` pair;
36. the capture-start authorization fields and immutable member rows commit
    before any source work and reconcile by exact count and fingerprint;
37. application inserts after the consistent snapshot are deferred unless also
    observed in exact source bytes, while later deletes or updates cannot alter
    the current seed;
38. valid exact-source-only additions create one authorized new baseline and do
    not emit `capture_cohort_changed`;
39. finalization performs no mutable application-state membership reread;
40. a successful eighth publication is acknowledged as `published`;
41. only a failed or irreconcilably ambiguous eighth publication terminalizes
    with `dispatch_publication_attempts_exhausted`, while a binding mismatch
    uses `dispatch_publication_mismatch`; and
42. pairing a `pending_dispatch` claim with a `recovery_required` outbox is
    rejected by every repository,
    invariant, crash row, and migration test.
43. nullable `uq_import_execution_claim_run` permits multiple legacy nulls but
    rejects a second non-null claim for the same SupplierImportRun;
44. `chk_import_execution_claim_path_parent` rejects every unsupported,
    uppercase, mixed-case, whitespace-padded, control-containing, null-byte,
    Unicode-lookalike or normalized-alternative path, orchestrated-without-run,
    legacy-with-run, legacy-without-feed, and legacy-without-job shape; the
    immutable-path trigger rejects every byte change while unrelated permitted
    claim-state updates still work;
45. acknowledged publication establishes a watchdog only for
    `queued/published`, processing acquisition clears it in the same
    transaction, and no processing or terminal row retains it;
46. recovery-required transition, terminalization, publication-mismatch
    terminalization, expired-owner terminalization, abandoned-processing
    terminalization, and normal finalization clear the watchdog atomically;
47. only a later acknowledged `queued/published` publication may establish a
    new watchdog;
48. the scheduled monitor selects the exact due range read-only, emits only
    privacy-safe fields, creates no authorization, job, or database write, and
    repeats warning/critical notifications at the specified thresholds;
49. a recovery authorization can be created only for one exact due
    claim/outbox pair, expires after exactly 900 seconds, and fails closed on
    action, fingerprint, reason, operator, nonce, state, or expiry mismatch;
50. the exact authorized command records at most one `started` event and one
    terminal result; publication success is recorded only after Redis
    acknowledgement in the same transaction as `published` plus the new
    watchdog, and an identical rerun returns the stored terminal event without
    mutation;
51. after the 1,800-second operator objective, same-key republication is
    rejected and only exact authorized fail-closed terminalization remains
    available, while absence of operator action leaves a visible critical
    nonterminal condition; and
52. authorization/result rows are append-only, omit secrets and raw source
    identity, use the exact FKs/indexes/uniqueness below, and remain absent from
    monitor-only evaluation; and
53. the queued/published protocol matrix has exactly 12 outcomes and contains
    zero statement permitting payload observation, delivery admission, lock
    contention, release, duplicate delivery or `failed()` to establish or
    refresh `delivery_watchdog_at`.

The same future MySQL/Redis suite must add focused watchdog, authorization, and
mismatch coverage for exactly these cases:

1. acknowledged publication whose Redis payload is missing;
2. watchdog grace not yet expired;
3. watchdog expiry;
4. worker observation racing watchdog selection;
5. supplier-lock contention;
6. same-key republication;
7. delivery-count and deadline preservation across republication;
8. null-owner terminal exhaustion;
9. expired-owner recovery with remaining budget;
10. expired-owner terminal exhaustion;
11. live-owner race rejection;
12. mismatch dry-run;
13. mismatch apply;
14. mismatch idempotent rerun;
15. mismatch conflicting rerun;
16. stale payload after terminalization; and
17. zero evidence and zero staging mutation for every new terminal path;
18. watchdog clearing on processing, recovery-required, every terminal path,
    publication mismatch, abandoned processing, and normal finalization;
19. watchdog re-establishment only after a later acknowledged
    `queued/published` publication;
20. read-only monitor cadence, due ordering, warning/critical thresholds,
    privacy-safe output, and zero writes/jobs;
21. exact recovery-authorization creation, 900-second expiry, fingerprint and
    action binding, nonce-hash uniqueness, and conflict rejection;
22. post-objective republication rejection and exact authorized
    `dispatch_watchdog_response_expired` terminalization;
23. immutable one-result-per-authorization recording and deterministic no-op
    rerun; and
24. zero authorization/result creation during monitor-only evaluation.

Those tests must also prove the exact field/check/index contract, the
4,320-second MySQL-UTC grace, bounded deterministic candidate ordering, and the
populated-fixture `EXPLAIN` requirements for
`ix_import_dispatch_outbox_state_watchdog_id`.

The same suite retains the previously specified allocation rollback, unique
ImportJob, duplicate binding, parent closeout, zero-qualified-evidence and
legacy/orchestrated idempotency assertions.

### Dispatch-outbox data dictionary

Proposed additive coordination table: `supplier_import_dispatch_outbox`.
It is mutable only through owner-checked publishing/recovery transitions. It is
not evidence, a schedule, or authorization for another import.

The future schema adds this exact nullable liveness field:

```sql
delivery_watchdog_at TIMESTAMP(6) NULL
```

| Column | Type | Null/default | Purpose and invariant | Privacy |
| --- | --- | --- | --- | --- |
| `id` | unsigned bigint | not null | Surrogate outbox key | internal |
| `supplier_import_execution_claim_id` | unsigned bigint | not null | Exact authorized claim | internal |
| `logical_execution_key` | `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin` | not null | Exact key copied from and constrained with the claim | internal |
| `event_type` | varchar(48) ASCII | `initial_dispatch` | Only authorized event in this phase | public contract |
| `job_type` | varchar(48) ASCII | not null | `run_supplier_import` or `process_xml_supplier_feed` | public contract |
| `dispatch_payload` | JSON | not null | Canonical allowlist containing a non-authoritative copy of the fixed UTC transport deadline | restricted operational metadata |
| `dispatch_payload_hash` | `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin` | not null | SHA-256 of canonical dispatch payload | pseudonymous |
| `transport_deadline_at` | timestamp(6) | not null | Immutable canonical deadline, exactly original authorization MySQL UTC plus 24 hours | operational metadata |
| `state` | varchar(32) ASCII | `pending` | `pending`, `leased`, `published`, `recovery_required`, or `terminal_failed` | public contract |
| `attempt_count` | unsigned smallint | `0` | Bounded outbox publication attempts; maximum 8 | aggregate |
| `delivery_attempt_count` | unsigned smallint | `0` | Cumulative dequeued handler deliveries for the logical execution; maximum 8 and never reset | aggregate |
| `lease_owner_key` | varchar(96) CHARACTER SET ascii COLLATE ascii_bin | nullable | Random per-invocation owner label; no host/user name | pseudonymous |
| `lease_token_hash` | `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin` | nullable | SHA-256 of in-memory lease token | pseudonymous |
| `leased_at` | timestamp(6) | nullable | Lease acquisition instant | operational metadata |
| `lease_expires_at` | timestamp(6) | nullable | Stale-lease recovery boundary | operational metadata |
| `next_attempt_at` | timestamp(6) | nullable | Deterministic retry eligibility | operational metadata |
| `published_at` | timestamp(6) | nullable | First acknowledged Redis publication; write-once | operational metadata |
| `last_published_at` | timestamp(6) | nullable | Most recent acknowledged publication of the same event/key | operational metadata |
| `delivery_watchdog_at` | timestamp(6) | nullable | MySQL-UTC detection deadline for an acknowledged payload; non-null only for the cross-record pair `claim.state = queued / outbox.state = published` | operational metadata |
| `recovery_required_at` | timestamp(6) | nullable | Transport exhaustion/manual intervention boundary | operational metadata |
| `recovery_reason_code` | varchar(96) ASCII | nullable | Allowlisted recoverable transport reason | public contract |
| `terminal_at` | timestamp(6) | nullable | Canonical terminal-failure instant | operational metadata |
| `terminal_failure_reason_code` | varchar(96) ASCII | nullable | Stable allowlisted terminal reason | public contract |
| `created_at`, `updated_at` | timestamp(6) | database managed | Mutable coordination audit | operational metadata |

`dispatch_payload` contains exactly `schema_version`, claim ID, logical key,
parent type, parent ID, a non-authoritative copy of the canonical
`transport_deadline_at`, and the existing boolean `force` intent where required
by the authorized parent action. The database column, not the payload, is the
deadline authority. It contains no supplier ID, feed URL, credential, XML,
observation, source identity, source path, raw supplier identifier, or arbitrary
job data. Consumers load every other value from the outbox, claim, and their
constrained parent.

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
| `pending` | `terminal_failed` | failed eighth initial publication or irreconcilable binding mismatch |
| `leased` | `leased` | owner-checked replacement after lease expiry |
| `leased` | `published` | owner-checked initial-dispatch acknowledgement or handler adoption for a pending-origin lease |
| `leased` | `terminal_failed` | failed or ambiguous eighth publication or irreconcilable binding mismatch |
| `published` | `recovery_required` | owner-proven in-handle pre-processing closeout, transport-only callback, stale-payload watchdog, or expired queued-owner recovery while deadline and delivery budget remain valid |
| `recovery_required` | `leased` | separately authorized manual reconciler |
| `leased` | `published` | owner-checked acknowledgement of that same reconciled recovery event/key |
| `recovery_required` | `terminal_failed` | deadline/delivery budget became irrecoverable, failed eighth recovery publication, or irreconcilable binding mismatch |

No other transition is valid. `terminal_failed` has no outgoing transition.
In particular, `recovery_required -> published` is impossible without the
explicit intermediate `leased` state and acknowledged republication. Lease
fields are present only in `leased`; publication, recovery-required, and
terminal transitions clear them. `published_at` records the first publication
and is write-once; `last_published_at` advances only after another acknowledged
publication of the same event/key. Every acknowledged publication sets
`delivery_watchdog_at = UTC_TIMESTAMP(6) + INTERVAL 4320 SECOND` in the same
transaction that establishes the cross-record `queued/published` pair; only a
later successfully acknowledged same-key recovery publication may refresh it
by the same MySQL-UTC rule. It is
cleared atomically when queued ownership enters `processing`, when the outbox
leaves `published` for `leased`, `recovery_required`, or `terminal_failed`, and
when a claim becomes `terminal_qualified`, `terminal_frozen`, or
`terminal_failed`. Consequently, `processing/published` and every terminal
claim whose canonical outbox remains `published` carry a null watchdog. A later
acknowledged same-key recovery publication re-establishes it only with
`queued/published`. `transport_deadline_at` and `delivery_attempt_count` are
never reset by those transitions. Recovery fields are present only in
`recovery_required`. State changes require the expected state, token where
applicable, and exactly one affected row. No DELETE or pruning is authorized.

The outbox table alone can enforce only that a non-null watchdog belongs to a
`published` outbox. Whether the joined claim is `queued`, `processing`, or
terminal is a cross-record fact and is enforced by the fixed-order repository
transaction plus future concurrency tests. No single-table `CHECK` is claimed
to enforce that joined-state invariant.
Outbox retention is indefinite until a later dry-run-first retention design
defines protection for linked claims and audit evidence; parent deletion
remains blocked by `RESTRICT`.

The exact canonical cross-record pairs are:

```text
pending_dispatch / pending
pending_dispatch / leased
queued / published
queued / recovery_required
processing / published
terminal_qualified / published
terminal_frozen / published
post-processing terminal_failed / published
pre-processing irrecoverable terminal_failed / terminal_failed
```

An initial publication remains in `pending` or `leased` while retryable. A
failed eighth initial publication moves directly to the final pair and never
passes through `recovery_required`.

The future MySQL 8.4 migration uses these exact single-table checks:

```sql
CONSTRAINT chk_import_outbox_state CHECK (
    state IN ('pending', 'leased', 'published', 'recovery_required', 'terminal_failed')
),
CONSTRAINT chk_import_outbox_attempt_bound CHECK (
    attempt_count >= 0 AND attempt_count <= 8
),
CONSTRAINT chk_import_outbox_delivery_attempt_bound CHECK (
    delivery_attempt_count >= 0 AND delivery_attempt_count <= 8
),
CONSTRAINT chk_import_outbox_transport_deadline CHECK (
    transport_deadline_at = TIMESTAMPADD(HOUR, 24, created_at)
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
        AND delivery_watchdog_at IS NULL
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
        AND delivery_watchdog_at IS NULL
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
        AND published_at IS NOT NULL
        AND last_published_at IS NOT NULL
        AND delivery_watchdog_at IS NULL
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
        AND delivery_watchdog_at IS NULL
        AND recovery_required_at IS NULL
        AND recovery_reason_code IS NULL
        AND terminal_at IS NOT NULL
        AND terminal_failure_reason_code IS NOT NULL
    )
),
CONSTRAINT chk_import_outbox_watchdog_state CHECK (
    delivery_watchdog_at IS NULL OR state = 'published'
),
CONSTRAINT chk_import_outbox_terminal_attempt CHECK (
    terminal_failure_reason_code <> 'dispatch_publication_attempts_exhausted'
    OR attempt_count = 8
),
CONSTRAINT chk_import_outbox_timestamp_order CHECK (
    transport_deadline_at > created_at
    AND (leased_at IS NULL OR (leased_at >= created_at AND leased_at < lease_expires_at))
    AND (next_attempt_at IS NULL OR next_attempt_at >= created_at)
    AND (published_at IS NULL OR published_at >= created_at)
    AND (last_published_at IS NULL OR last_published_at >= published_at)
    AND (delivery_watchdog_at IS NULL OR delivery_watchdog_at >= last_published_at)
    AND (recovery_required_at IS NULL OR recovery_required_at >= created_at)
    AND (terminal_at IS NULL OR terminal_at >= created_at)
)
```

The application and repository allowlists additionally restrict recoverable
reasons to failures that still have both transport boundaries available.
They include the liveness reasons `dispatch_payload_unobserved` and
`queued_ownership_lease_expired` alongside the existing transport-only callback
and in-handle pre-processing reasons.
Canonical terminal reasons in this boundary are exactly
`transport_delivery_budget_exhausted`, `transport_deadline_expired`,
`dispatch_publication_attempts_exhausted`, and
`dispatch_publication_mismatch`, plus the manually authorized watchdog reasons
`dispatch_watchdog_operator_terminalized` and
`dispatch_watchdog_response_expired`. Publication attempt nine is rejected before mutation by
`chk_import_outbox_attempt_bound`; logical delivery nine is rejected by
`chk_import_outbox_delivery_attempt_bound`, while delivery eight enters the
pre-work exhaustion path. The immutable deadline check binds the canonical
deadline to the original database-generated `created_at`; repository updates
must include it in their immutable-column compare-and-set allowlist. A terminal
failure caused specifically by publication exhaustion is valid only after a
failed or irreconcilably ambiguous eighth publication, with
`terminal_failure_reason_code = 'dispatch_publication_attempts_exhausted'` and
`attempt_count = 8`; irreconcilable parent/key failures close under
`dispatch_publication_mismatch`. A successful eighth publication remains
`published` with `attempt_count = 8`, but it does not reset the separate
delivery count or transport deadline.

| Reason code | Exact state/result boundary |
| --- | --- |
| `dispatch_payload_unobserved` | recoverable `queued/recovery_required` after the due null-owner watchdog proves no delivery observation |
| `queued_ownership_lease_expired` | recoverable `queued/recovery_required` after complete expired pre-processing ownership is cleared |
| `transport_delivery_budget_exhausted` | irrecoverable pre-processing `terminal_failed/terminal_failed` when delivery eight is consumed |
| `transport_deadline_expired` | irrecoverable pre-processing `terminal_failed/terminal_failed` at or after the immutable deadline |
| `dispatch_publication_attempts_exhausted` | `terminal_failed/terminal_failed` only after failed or irreconcilably ambiguous publication attempt eight |
| `dispatch_publication_mismatch` | explicit canonical pre-processing mismatch resolution to `terminal_failed/terminal_failed` |
| `dispatch_watchdog_operator_terminalized` | manually authorized fail-closed terminalization of one due dispatch before the response objective expires |
| `dispatch_watchdog_response_expired` | manually authorized fail-closed terminalization after 1,800 seconds beyond `delivery_watchdog_at`; republication is forbidden |
| `processing_lease_abandoned` | post-processing claim/parents become terminal failed while the canonical outbox remains `published` |

Cross-record invariants cannot be delegated to single-table checks. Every
transaction below uses the fixed `outbox -> claim -> ImportHistory -> ImportJob
-> SupplierImportRun -> SupplierFeed` `FOR UPDATE` lock order, skipping absent
parents without reordering the rest; owner acquisition adds the supplier lock before this short database
transaction. They are enforced by the named future repository/service
transactions and proved by
`SupplierImportMysqlRedisRecoveryTest`:

| Cross-record case | Required invariant | Transaction owner and integration assertion |
| --- | --- | --- |
| terminal claim with duplicate published delivery | preserve the terminal claim/parents/evidence and exact canonical outbox state; never republish or reopen | `SupplierImportDispatchOutboxPublisher` through `SupplierImportDispatchOutboxRepository`; duplicate terminal delivery is a zero-work no-op |
| processing admission | `queued -> processing` requires the same transaction to verify `outbox.state = published`; `processing/recovery_required` is forbidden | `SupplierImportExecutionCoordinator` through `SupplierImportStateInvariantRepository`; queued-to-processing versus transport-failure races yield only one canonical pair |
| stale delivery with `recovery_required` | preserve queued claim/parents and outbox; the stale payload exits before ownership, allocation, download, importer or evidence | `SupplierImportExecutionCoordinator`; only an acknowledged manual `recovery_required -> leased -> published` cycle can make a later payload eligible |
| recoverable pre-processing failure | claim remains `queued`; its `published` outbox becomes `recovery_required` only while the deadline is future and `delivery_attempt_count <= 6`; parent state is preserved | `SupplierImportTransportFailureService`; no terminal claim/parent/evidence write and a pending-dispatch/recovery-required pairing is rejected |
| acknowledged payload unobserved | only `queued/published` with due `delivery_watchdog_at`, null ownership, canonical parents, and no evidence becomes `queued/recovery_required` with `dispatch_payload_unobserved`, or the exact terminal pair when a transport boundary is exhausted | `ReconcileSupplierImportDispatchOutbox` through the fixed-order invariant/terminal repositories; bounded indexed selection and supplier-lock revalidation prevent a Redis-payload assumption |
| expired queued ownership | only a complete expired `queued/published` ownership tuple may be cleared; recoverable transport becomes `queued/recovery_required` with `queued_ownership_lease_expired`, otherwise all applicable rows close terminally | `ExpiredQueuedImportTerminalRepository::resolveExpiredQueuedOwnership()`; complete-tuple CAS, new supplier lock, exact race rejection, and zero importer/evidence work are required |
| irrecoverable pre-processing delivery/deadline exhaustion | `queued/published` or `queued/recovery_required` becomes `terminal_failed/terminal_failed`; every applicable parent closes atomically | delivery admission or `SupplierImportTransportFailureService` plus terminal repository; exact reason distinguishes budget from deadline and no importer/evidence work runs |
| explicit publication mismatch | one exactly identified eligible pre-processing pair becomes `terminal_failed/terminal_failed` with `dispatch_publication_mismatch`; same-terminal rerun is a no-op and every conflict fails closed | `PublicationMismatchTerminalRepository::failPreProcessingMismatch()` under the supplier lock and canonical row-lock order; no broad selection or source/import/evidence work |
| terminal outbox failure | outbox `terminal_failed`, claim `terminal_failed`, complete ownership tuple cleared, and every applicable parent closed in one transaction | `SupplierImportDispatchOutboxRepository` plus `TransactionalImportTerminalRepository`; mismatch rolls back all rows |
| terminal claim no-op | all terminal deliveries preserve canonical terminal state, parent state, generation/gap and exact terminal outbox mapping | `SupplierImportExecutionCoordinator`; no source, importer, parent, outbox or evidence mutation |
| published outbox after final claim | `terminal_qualified`, `terminal_frozen`, and post-processing `terminal_failed` require the acknowledged outbox to remain `published`; this does not reopen execution | coordinator terminal repository; finalization locks outbox then claim and rejects every other pair |
| abandoned processing | expired `processing/published` is not republished; the separate abandoned-owner API acquires a new supplier lock and closes claim/parents as `terminal_failed`, clears the expired tuple, leaves outbox `published`, and creates no qualified generation | `ReconcileAbandonedSupplierImportExecutions` through a dedicated abandoned terminal method; expiry, no lost-token requirement, lock order, races, and zero replay are proven |

Any other claim/outbox combination fails closed, affects zero rows, and requires
explicit operator reconciliation. No cross-state repair creates a replacement
claim, outbox event, ImportJob, execution authorization, or importer replay.

Every transaction in the table above that moves `queued/published` into
`processing`, `recovery_required`, or a terminal claim clears
`delivery_watchdog_at` in the same commit. Finalization and abandoned-processing
recovery also require the already-null watchdog on `processing/published`.
Malformed processing or terminal `published` pairs with a non-null watchdog are
cross-record integrity failures and cannot be repaired by the monitor.

### Recovery authorization and result data dictionaries

The future append-only authorization table is
`supplier_import_dispatch_recovery_authorizations`. It records one human
decision for one exact due dispatch and is neither queue work nor evidence:

| Column | Type | Null/default | Purpose and invariant | Privacy |
| --- | --- | --- | --- | --- |
| `id` | unsigned bigint | not null | Surrogate authorization key | internal |
| `supplier_import_execution_claim_id` | unsigned bigint | not null | Exact execution claim | internal |
| `supplier_import_dispatch_outbox_id` | unsigned bigint | not null | Exact outbox bound to that claim | internal |
| `authorization_action` | `VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin` | not null | `republish_same_key` or `terminalize_stale_dispatch` | public contract |
| `expected_state_fingerprint` | `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin` | not null | SHA-256 of the exact requested action and compare-and-set state | pseudonymous |
| `canonical_reason_code` | `VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin` | not null | Exact allowlisted recovery or terminal reason | public contract |
| `authorized_operator_id` | unsigned bigint | not null | Existing active Super Admin who approved this one action | internal |
| `authorized_at` | timestamp(6) | not null | MySQL UTC authorization instant | operational metadata |
| `expires_at` | timestamp(6) | not null | Exactly `authorized_at + 900 seconds` | operational metadata |
| `authorization_nonce_hash` | `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin` | not null | Unique domain-separated SHA-256 of 32 random nonce bytes | pseudonymous |

There is no `created_at` or `updated_at`; `authorized_at` is the immutable audit
time. Every foreign key uses `RESTRICT`. The exact named same-table checks are:

```sql
CONSTRAINT chk_import_recovery_auth_action CHECK (
    BINARY authorization_action IN (
        BINARY _ascii'republish_same_key',
        BINARY _ascii'terminalize_stale_dispatch'
    )
),
CONSTRAINT chk_import_recovery_auth_expected_fingerprint CHECK (
    OCTET_LENGTH(expected_state_fingerprint) = 64
    AND REGEXP_LIKE(expected_state_fingerprint, _ascii'^[0-9a-f]{64}$', 'c')
),
CONSTRAINT chk_import_recovery_auth_nonce_hash CHECK (
    OCTET_LENGTH(authorization_nonce_hash) = 64
    AND REGEXP_LIKE(authorization_nonce_hash, _ascii'^[0-9a-f]{64}$', 'c')
),
CONSTRAINT chk_import_recovery_auth_expiry CHECK (
    expires_at = TIMESTAMPADD(SECOND, 900, authorized_at)
)
```

The expected-state fingerprint domain is exactly
`supplier-import-dispatch-recovery-state-v1` and the digest is:

```text
SHA-256(domain_separator || 0x00 || canonical_json_bytes)
```

The canonical object contains these keys in this exact order:

```text
schema, authorization_action, execution_claim_id, dispatch_outbox_id,
logical_execution_key, execution_path, claim_state, outbox_state, supplier_id,
supplier_import_run_id, supplier_feed_id, import_job_id, import_history_id,
publication_attempt_count, delivery_attempt_count, transport_deadline_at,
delivery_watchdog_at, active_attempt_token_hash, attempt_lease_expires_at
```

`schema` is exactly `supplier-import-dispatch-recovery-state-v1`. Strings are
emitted without normalization; IDs and counters are base-10 JSON integers;
every absent nullable value is literal JSON `null`; timestamps are UTC
`YYYY-MM-DDTHH:MM:SS.ffffffZ`; and lowercase SHA-256 strings are exactly 64
`[0-9a-f]` characters. Keys are never reordered or omitted, and the encoding
has no insignificant whitespace or pretty printing. JSON booleans, floats,
localized numbers and omitted nullable keys are invalid. Future PHP encoding
uses exactly `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE |
JSON_THROW_ON_ERROR`. The raw canonical JSON and logical execution key exist
only in the bounded in-memory computation and are never printed, logged,
included in dry-run output, or persisted.

The future issuer interface is exactly:

```php
SupplierImportDispatchRecoveryAuthorizationIssuer::issue(
    User $actor,
    int $executionClaimId,
    int $dispatchOutboxId,
    string $authorizationAction,
    string $expectedStateFingerprint,
): IssuedSupplierImportDispatchRecoveryAuthorization
```

It accepts only an authenticated active Super Admin. It acquires the supplier
lock, then locks outbox, claim and applicable parents in canonical order,
recomputes the fingerprint, and rejects a mismatch, active processing,
evidence, noncanonical parents or an action outside the two-value allowlist. It
uses MySQL UTC and inserts one immutable authorization expiring exactly 900
seconds later. It generates exactly 32 cryptographically secure random nonce
bytes and returns them once as unpadded base64url. The raw bytes are never
persisted. The persisted nonce hash is lowercase hexadecimal:

```text
SHA-256("supplier-import-dispatch-recovery-nonce-v1" || 0x00 || raw_nonce_bytes)
```

The issuer result object exposes the authorization ID and one-time nonce only;
it is not serializable, queueable, loggable or persistable. Issuance is allowed
only after `delivery_watchdog_at <= UTC_TIMESTAMP(6)` for a canonical
`queued/published` pair. `republish_same_key` additionally requires the
1,800-second response window, future transport deadline and
`delivery_attempt_count <= 6`. `terminalize_stale_dispatch` requires the exact
current terminal reason. The read-only monitor cannot invoke the issuer.

Recovery commands require non-secret `--authorization-id` and the boolean
`--nonce-stdin`. They read exactly one unpadded base64url value from standard
input, disable terminal echo where supported, require exactly 32 decoded bytes,
derive the domain-separated hash above, compare it in constant time, and clear
or release the raw bytes as soon as practical. The nonce is forbidden in an
argument value, environment variable, config file, URL, log, database
plaintext, queue payload, shell history, process title, or dry-run/apply output.
The command never echoes it. Non-interactive execution without a separately
approved secure standard-input source fails closed.

The command rederives action, reason, fingerprint, operator and expiry under
locks before domain mutation. A bad nonce or unauthenticated actor fails without
an audit write, preventing an untrusted caller from consuming an authorization.
After a valid nonce and actor are established, expiry, changed state,
noncanonical parent or conflicting use is recorded as the allowlisted immutable
`rejected` event with zero claim, outbox, parent or evidence mutation.

Consumption never updates the authorization. The append-only
`supplier_import_dispatch_recovery_results` table records ordered lifecycle
events:

| Column | Type | Null/default | Purpose and invariant | Privacy |
| --- | --- | --- | --- | --- |
| `id` | unsigned bigint | not null | Surrogate immutable event key | internal |
| `supplier_import_dispatch_recovery_authorization_id` | unsigned bigint | not null | Exact authorization | internal |
| `event_sequence` | unsigned smallint | not null | Monotonic sequence, starting at 1 | public contract |
| `event_kind` | `VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin` | not null | Exact lifecycle event allowlist below | public contract |
| `supplier_import_execution_claim_id` | unsigned bigint | not null | Claim copied from and revalidated against authorization | internal |
| `supplier_import_dispatch_outbox_id` | unsigned bigint | not null | Outbox copied from and revalidated against authorization | internal |
| `canonical_result_code` | `VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin` | not null | Allowlisted result or rejection code, never exception text | public contract |
| `executed_operator_id` | unsigned bigint | not null | Super Admin bound to the authorization | internal |
| `occurred_at` | timestamp(6) | not null | MySQL UTC event instant | operational metadata |
| `result_fingerprint` | `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin` | not null | Exact immutable event fingerprint | pseudonymous |
| `started_once_guard` | generated nullable tinyint | generated | `1` only for `started`, otherwise null | internal |
| `terminal_once_guard` | generated nullable tinyint | generated | `1` only for a terminal event, otherwise null | internal |

The generated guards use binary comparisons. Exact same-row checks require a
positive sequence, one of the six event kinds and a lowercase hexadecimal
result fingerprint:

```sql
started_once_guard TINYINT UNSIGNED GENERATED ALWAYS AS (
    CASE WHEN BINARY event_kind = BINARY _ascii'started' THEN 1 ELSE NULL END
) STORED,
terminal_once_guard TINYINT UNSIGNED GENERATED ALWAYS AS (
    CASE WHEN BINARY event_kind IN (
        BINARY _ascii'republish_succeeded',
        BINARY _ascii'terminalization_succeeded',
        BINARY _ascii'publish_failed',
        BINARY _ascii'rejected',
        BINARY _ascii'already_terminal'
    ) THEN 1 ELSE NULL END
) STORED,
CONSTRAINT chk_import_recovery_result_event CHECK (
    BINARY event_kind IN (
        BINARY _ascii'started',
        BINARY _ascii'republish_succeeded',
        BINARY _ascii'terminalization_succeeded',
        BINARY _ascii'publish_failed',
        BINARY _ascii'rejected',
        BINARY _ascii'already_terminal'
    )
),
CONSTRAINT chk_import_recovery_result_sequence CHECK (event_sequence BETWEEN 1 AND 2),
CONSTRAINT chk_import_recovery_result_fingerprint CHECK (
    OCTET_LENGTH(result_fingerprint) = 64
    AND REGEXP_LIKE(result_fingerprint, _ascii'^[0-9a-f]{64}$', 'c')
)
```

The event allowlist is exactly `started`, `republish_succeeded`,
`terminalization_succeeded`, `publish_failed`, `rejected`, and
`already_terminal`. Unique (`authorization_id`, `event_sequence`), unique
(`authorization_id`, `started_once_guard`) and unique (`authorization_id`,
`terminal_once_guard`) enforce ordered identity, at most one start and at most
one terminal event under MySQL nullable-unique semantics. `started` is sequence
1. A successful/failing publication or terminalization is sequence 2 after a
start. `rejected` or `already_terminal` may be sequence 1 only when no start
exists. Cross-row ordering is revalidated under the authorization lock; a
same-authorization rerun with a terminal event returns that existing event and
inserts nothing.

The exact event-to-result-code map is:

| Event | Permitted canonical result codes |
| --- | --- |
| `started` | `authorization_attempt_started` |
| `republish_succeeded` | `dispatch_republished_same_key` |
| `terminalization_succeeded` | `transport_delivery_budget_exhausted`, `transport_deadline_expired`, `dispatch_watchdog_operator_terminalized`, `dispatch_watchdog_response_expired` |
| `publish_failed` | `dispatch_publication_failed`, `dispatch_publication_attempts_exhausted`, `dispatch_publication_mismatch` |
| `rejected` | `authorization_expired`, `state_fingerprint_mismatch`, `state_conflict`, `noncanonical_parent`, `action_not_permitted`, `response_window_expired` |
| `already_terminal` | `already_terminal_noop` |

Any other code, and any raw exception text, fails before INSERT.

`started` is inserted only after actor, nonce, expiry, fingerprint, state and
parents pass, and it commits atomically with claiming the authorization attempt
and the approved `published -> recovery_required` transition. It never claims
publication success. `republish_succeeded` is inserted only after Redis has
accepted the same canonical payload and the publication is acknowledged; the
event, `recovery_required/leased -> published` transition and newly established
watchdog commit together. `publish_failed` records only a failed or
irreconcilably ambiguous publication attempt and preserves the existing bounded
recovery/terminal rules. `rejected` records a completed, authenticated,
nonce-proven fail-closed attempt with an allowlisted code and no domain
mutation. `already_terminal` records the sequence-1 no-op when another path
already established the exact expected terminal result before this
authorization started.

Crash and retry behavior is exact:

- before `started`, rollback leaves no event or domain mutation and the same
  unexpired authorization may be retried;
- after `started` but before publication, the same authorization resumes only
  its existing bounded recovery event and never inserts another start;
- expiry prevents a new start, but does not revoke an already committed start;
- Redis success before database acknowledgement creates no success event; an
  exact handler adoption may atomically acknowledge it, otherwise stale-lease
  recovery republishes the same key within the unchanged budget/deadline;
- a failed or irreconcilably ambiguous publication records `publish_failed`
  only when its authoritative recovery/terminal transaction commits;
- a rerun returns the existing terminal event without another event;
- an expired or state-conflicting nonce-proven authorization records one
  `rejected` event and zero domain mutation; and
- an exact terminal state won by another path records `already_terminal`, while
  a conflicting terminal state records `rejected`.

The immutable result fingerprint domain is exactly
`supplier-import-dispatch-recovery-result-v1` and uses
`SHA-256(domain_separator || 0x00 || canonical_json_bytes)`. Its canonical
object keys are exactly, in order:

```text
schema, authorization_id, event_sequence, event_kind, execution_claim_id,
dispatch_outbox_id, expected_state_fingerprint, canonical_result_code,
occurred_at
```

`schema` is exactly `supplier-import-dispatch-recovery-result-v1`. It uses the
same fixed-order, no-whitespace, no-normalization, base-10 integer,
lowercase-hexadecimal and UTC microsecond timestamp rules as the state
fingerprint. No key is omitted and no boolean, float or localized number is
accepted. The stored lowercase hexadecimal digest is `result_fingerprint`.

### Outbox publisher and manual recovery

After the authorization transaction commits, an immediate publisher may lease
the pending row and publish the original serialized job to Redis. Publication
success is acknowledged by one owner-token-checked transaction that changes the
outbox from `leased` (or `pending` when a fast handler adopts it) to `published`
and the claim from `pending_dispatch` to `queued`. A crash before publication
leaves the row recoverable. A crash after Redis accepted the job but before
acknowledgement leaves it eligible for duplicate publication; claim uniqueness
and terminal checks make that duplicate harmless. Every acknowledged initial or
recovery publication sets
`delivery_watchdog_at = UTC_TIMESTAMP(6) + INTERVAL 4320 SECOND` in the same
owner-token-checked transaction. Neither case creates a key.

The mandatory future visibility interface is the separate CLI command:

```text
php artisan suppliers:monitor-import-dispatch-watchdogs
```

It is read-only, non-mutating, safe to schedule every 300 seconds, and incapable
of dispatching a job or changing claim, outbox, authorization, result, parent,
evidence, staging, Product, schedule, or Catalog Sync state. It uses the
canonical watchdog index and the exact due-candidate predicate below. It emits
only due candidate count, oldest due timestamp, oldest overdue duration,
opaque claim/outbox IDs, and supplier numeric ID where the existing privacy
policy permits that ID. Supplier name, source identity, URL, path, raw offer
identity, logical execution key, payload, token, nonce, and hashes are forbidden.

The monitoring schedule and alert contract are mandatory before capture can be
enabled:

- run every 300 seconds;
- warning on the first monitor cycle at or after a watchdog becomes due, with a
  maximum cadence latency of 300 seconds;
- critical at `delivery_watchdog_at + INTERVAL 1800 SECOND`; and
- repeat the critical alert every 900 seconds while the due row remains
  unresolved.

The scheduler contract is `everyFiveMinutes()` with overlap prevention. The
monitor derives a zero-based critical bucket as
`FLOOR((overdue_seconds - 1800) / 900)` once critical. Each emitted alert uses
only the opaque outbox ID, watchdog timestamp and bucket as its deduplication
identity. The external alert sink emits at most once per identity, so delayed
300-second monitor cycles cannot create a write-side cursor or duplicate a
900-second repetition. No deduplication key contains a source, supplier name,
logical key, payload, token, nonce, or hash.

Alerting is visibility and escalation only. The monitor cannot create a
recovery authorization or invoke the mutating reconciler.

The future recovery interface remains CLI-only:

```text
php artisan suppliers:reconcile-import-dispatch-outbox --dry-run --limit=25
php artisan suppliers:reconcile-import-dispatch-outbox --apply --limit=25
php artisan suppliers:reconcile-import-dispatch-outbox \
    --apply \
    --authorization-id=<recovery-authorization-id> \
    --nonce-stdin
```

It is absent in this phase. A Release/Operations operator with separate
one-run authorization may invoke it on a trusted application host. Dry-run is
the default; its `--limit` defaults to 25 and rejects values outside 1 through
50. The existing bounded pending/recovery/stale-lease page remains separately
authorized. Stale `published` watchdog mutation rejects broad selection and
requires exactly one valid
`supplier_import_dispatch_recovery_authorizations` ID plus the protected
out-of-band nonce and current operator identity. A `recovery_required` row with
`dispatch_payload_unobserved` may resume only when the immutable matching
authorization result already exists. There is no scheduler, HTTP route,
Filament action, queue self-dispatch, or automatic invocation for the mutating
reconciler.

The reconciler reads only due `pending` or `recovery_required` rows, `leased`
rows whose lease has expired, or stale `published` candidates selected through
`ix_import_dispatch_outbox_state_watchdog_id`. The exact stale candidate query
contract is equivalent to:

```sql
SELECT o.id, o.supplier_import_execution_claim_id, o.delivery_watchdog_at
FROM supplier_import_dispatch_outbox AS o
INNER JOIN supplier_import_execution_claims AS c
    ON c.id = o.supplier_import_execution_claim_id
WHERE o.state = 'published'
  AND o.delivery_watchdog_at IS NOT NULL
  AND o.delivery_watchdog_at <= UTC_TIMESTAMP(6)
  AND c.state = 'queued'
ORDER BY o.delivery_watchdog_at, o.id
LIMIT :validated_limit_1_through_50
```

Terminal and processing `published` rows have a null watchdog and therefore do
not occupy the due non-null range. Candidate discovery does not authorize a
mutation. After discovery, the reconciler acquires the owner-checked supplier
Redis lock, transactionally locks `outbox -> claim -> applicable parents`, and
revalidates ownership, authorization, expected-state fingerprint, response
window, transport boundaries, evidence absence, and every parent binding. MySQL
8.4 workers claim an ordinary pending/recovery/stale-lease page through one
transaction using `SELECT ... FOR UPDATE SKIP LOCKED`, a random owner key and
hashed token, and a five-minute lease. It validates the original
claim/key/parent and refuses every terminal claim. A `recovery_required` event
must carry an allowlisted transport-only `failed()` or in-handle pre-processing
reason, a literal `queued` claim, a future deadline, and
`delivery_attempt_count <= 6`. Republication serializes the unchanged canonical
`transport_deadline_at` and remaining `delivery_attempt_count`. If either
boundary is no longer valid, the reconciler locks outbox, claim, and applicable
parents and atomically terminalizes them with `transport_deadline_expired` or
`transport_delivery_budget_exhausted`; it cannot merely refuse republication.
For a stale `queued/published` candidate with a complete null ownership tuple,
an exact unexpired `republish_same_key` authorization before the response
objective may change only the outbox to `recovery_required`, clear the watchdog,
preserve the queued claim and parents, record `dispatch_payload_unobserved`, and
append the immutable authorization result. The already-authorized outbox may
then resume the same bounded `recovery_required -> leased -> published` path
without creating another execution or authorization. If a transport boundary
is exhausted, the exact terminal authorization instead atomically closes claim,
outbox, parents, and its result. A complete expired ownership tuple is delegated only to
`ExpiredQueuedImportTerminalRepository::resolveExpiredQueuedOwnership()`; an
unexpired or half-bound tuple is never cleared or guessed.
Attempt delays are deterministic: 1, 5, 15, 30, 60, 120, 240, then 480 minutes,
capped at eight outbox publication attempts. Safe output contains only row IDs,
states, counts, and allowlisted reason codes.

The operational response objective is 1,800 seconds after
`delivery_watchdog_at`. Before that instant, an authorized operator may approve
same-key republication while both transport boundaries remain valid, or may
approve fail-closed terminalization. At or after that instant,
`republish_same_key` is rejected even if its 900-second artifact has not yet
expired; only `terminalize_stale_dispatch` is permitted. The terminal reason is
the actual authoritative boundary: `transport_delivery_budget_exhausted`,
`transport_deadline_expired`, or `dispatch_watchdog_response_expired`; an
explicit earlier operator terminalization uses
`dispatch_watchdog_operator_terminalized`.

These are operational objectives, not autonomous wall-clock terminalization.
Redis payload loss is detectable, but recovery is manually authorized and the
mutating command is separately invoked. Once a valid exact authorization is
consumed, the protocol has a bounded one-execution state machine and cannot
create another key, event, or unbounded page. If the operator takes no action,
the alert remains critical and the execution may remain non-terminal; no fixed
wall-clock terminalization guarantee is claimed.

Publication attempts one through seven remain retryable after failure. A
successful eighth publication is acknowledged normally and leaves the outbox
`published`. Only after a failed or irreconcilably ambiguous eighth publication
does the reconciler acquire the supplier lock and lock outbox, claim, and every
bound parent. For `pending_dispatch` with `pending` or `leased`, one transaction
moves outbox and claim directly to `terminal_failed`, closes the pending
orchestrated run, and closes the legacy ImportJob when present. For
`queued/recovery_required`, it additionally closes the bound ImportJob, any
started ImportHistory, and authoritative orchestrated run fields. The reason is
`dispatch_publication_attempts_exhausted`; an irreconcilable binding uses
`dispatch_publication_mismatch`. A stale eighth lease whose Redis success cannot
be proved is terminalized, and any payload accepted despite the ambiguity sees
the terminal claim and no-ops. No ninth publication, importer work, or evidence
is permitted. A mismatch in the terminal transaction rolls back everything and
requires the separately authorized exact terminal-resolution command below. It
never leaves a silently stranded `pending_dispatch` or
`queued` claim.

A terminal claim encountered after an ambiguous successful publication accepts
only its already-canonical `published` or `terminal_failed` outbox mapping; it
never republishes or performs a recovery-state shortcut. Stale leases may be
replaced only after expiry with an owner-checked compare-and-set. Recovery never
changes a schedule, deadline, delivery budget, calls Catalog Sync, or authorizes
a new execution.

The exact future publication-mismatch interface is:

```text
php artisan suppliers:resolve-import-publication-mismatch \
    --claim-id=<claim-id> \
    --expected-outbox-id=<outbox-id> \
    --expected-logical-execution-key=<64-character-key>

php artisan suppliers:resolve-import-publication-mismatch \
    --claim-id=<claim-id> \
    --expected-outbox-id=<outbox-id> \
    --expected-logical-execution-key=<64-character-key> \
    --apply
```

`suppliers:resolve-import-publication-mismatch` is CLI-only, unscheduled,
dry-run by default, explicitly mutating only with `--apply`, limited to exactly
one selected execution, idempotent, and protected by the approved operator
authorization procedure. All three identifying inputs are mandatory. Supplier
name, feed URL, source path, raw supplier identifier, and any broad query are
forbidden selectors.

The command calls
`PublicationMismatchTerminalRepository::failPreProcessingMismatch()`. After a
supplier Redis lock it transactionally locks `outbox -> claim -> applicable
parents` and accepts only these canonical pairs: `pending_dispatch/pending`;
`pending_dispatch/leased` with an expired lease; `queued/published` with null or
expired pre-processing ownership; or `queued/recovery_required` with no active
owner. It rejects unexpired or half-bound ownership, `processing`, qualified or
frozen terminal claims, conflicting terminal reasons, generation/evidence,
noncanonical parents, and any mismatched claim ID, outbox ID, or logical key.

Parent authority is exact: an orchestrated execution requires its
SupplierImportRun; a legacy execution forbids one. ImportJob/feed must be a
valid pair-null or pair-bound tuple for the current state, never half-bound, and
any ImportHistory must belong to the same execution. The complete-state CAS
atomically writes `terminal_failed/terminal_failed`, closes every applicable
parent, records `dispatch_publication_mismatch` and a MySQL-UTC terminal
timestamp, and clears all ownership, publication-lease, watchdog, and recovery
fields. It performs zero source access, download, importer/staging work,
generation, enrollment, observation, absence evidence, or lifecycle
recommendation.

A rerun with the identical claim, outbox, key, terminal reason, and parent
bindings returns `already_terminal_noop` without mutation. Any conflicting
reason, state, key, parent, active owner, or evidence fails closed. Dry-run and
apply output is limited to the selected claim/outbox IDs, canonical states,
allowlisted reason, parent type/counts, eligibility/result code, and affected
row counts. It never prints payload JSON, supplier/feed/source identifiers,
URLs, paths, credentials, raw product identifiers, or source data.

### Operationally governed `queued/published` substate outcomes

| Ownership and payload observation | Transport/response boundary | Permitted protocol outcome |
| --- | --- | --- |
| null owner; payload unobserved and watchdog not due | any | no mutation; detection grace remains active |
| null owner; payload unobserved and watchdog due for less than 1,800 seconds | budget and deadline valid | monitor warning; an exact unexpired authorization may permit supplier-locked `published -> recovery_required` with `dispatch_payload_unobserved` and bounded same-key republication, or fail-closed terminalization |
| null owner; payload unobserved and watchdog due for 1,800 seconds or more | any | monitor critical; republication is forbidden and an exact authorization may only atomically terminalize with the actual transport reason or `dispatch_watchdog_response_expired` |
| null owner; due watchdog with no operator action | any | read-only alerts continue; no mutation occurs and terminalization is not guaranteed |
| complete unexpired owner | any | active-owner continuation; watchdog/reconciler and duplicates affect zero rows |
| complete expired owner | budget/deadline valid and response objective open | an exact authorization may allow complete-tuple CAS to clear ownership, write `queued_ownership_lease_expired`, and continue bounded same-key recovery |
| complete expired owner | transport exhausted or response objective expired | an exact authorization may allow complete-tuple CAS to atomically terminalize claim, outbox, and applicable parents; no autonomous mutation |
| half-bound owner | any | fail closed; no ownership clearing, republication, terminal write, or work |
| recoverable transport failure already in `queued/recovery_required` | budget and deadline valid | existing bounded same-key `recovery_required -> leased -> published` path |
| recoverable transport failure already in `queued/recovery_required` | delivery exhausted or deadline expired | atomic pre-processing terminalization with the exact transport reason |
| publication mismatch in an eligible canonical pre-processing pair | no active owner and no evidence | explicit one-execution mismatch command performs atomic terminal failure; identical rerun is `already_terminal_noop` |
| stale payload after a recovery or terminal winner | any | state/key/owner revalidation rejects work; terminal delivery returns the stored no-op |

This table contains exactly 12 outcomes. Merely reaching `handle()`, entering
delivery admission, being observed by a worker, contending for the supplier
lock, being released, arriving as a duplicate, or entering `failed()` never
changes or refreshes `delivery_watchdog_at`. Only an acknowledged Redis
publication establishes it.

Thus Redis payload loss is durably detectable and every authorized mutating
command has a bounded, exact state machine after it starts. The 1,800-second
operator objective and alert cadence govern response; they do not create an
autonomous terminalization guarantee. Without operator response, a due
`queued/published` execution can remain non-terminal and critically alerted.

## Cohort Enrollment Contract

Enrollment is privacy-safe, monotonic, and source-scoped.

Before source download or importer work, the future coordinator creates exactly
one durable capture-start authorization for the claim. It starts one MySQL
`REPEATABLE READ` transaction with a consistent snapshot and streams into a
mode-0600 bounded privacy-safe spool:

- every earlier effective immutable enrollment in the same supplier/source
  scope; and
- every applicable `supplier_products` and `product_supplier_offers` identity
  visible in that one database snapshot.

The collector validates each identity, writes only the canonical
domain-separated `supplier_sku_hash`, externally sorts and deduplicates the
hashes, and computes the exact seed count and
`snapshot_cohort_authorization_v1` fingerprint. It then locks the canonical
outbox and claim, verifies `queued/published`, inserts the complete immutable
authorization-member set, and writes the claim's
`cohort_authorization_version`, `cohort_authorized_at`, `cohort_seed_count`, and
`cohort_seed_fingerprint` in the same commit. No source work begins before that
commit. The persisted member count and sorted fingerprint must recompute exactly
before processing and finalization; a partial or conflicting authorization
fails closed. The spool is removed in `finally` and is never authoritative after
commit.

A same-key retry before `processing` reuses the committed authorization-member
rows and never rereads application membership. It verifies the closed version,
count, fingerprint, claim, supplier, feed, and ImportHistory bindings first. A
missing partial tuple, missing member, extra member, or fingerprint mismatch
closes the pre-mutation execution as `terminal_frozen` with
`capture_cohort_changed`, produces no generation or absence evidence, and
requires a separately authorized new execution.

Only the canonical hash is persisted. Raw SKU, EAN, MPN, source record, name,
URL, or path is prohibited. An application row without one unambiguous
canonical supplier SKU is a capture-integrity blocker; the coordinator must not
guess its identity. The documented 86-identity APCOM staging-only cohort is
therefore authorized exactly as visible in that first future capture-start
snapshot, including identities absent from the later downloaded source.

The exact generation cohort is the immutable capture-start seed union every
valid identity observed in the exact downloaded temporary file. Source-only
additions are expected and authorized only under the fixed
`supplier_offer_cohort_v1` source-expansion rule, after identity validation and
inclusion in the deterministic source-observation collector. Finalization never
rereads mutable application rows to rebuild membership. Application inserts
after the consistent snapshot are deferred to a future generation unless the
same identity is also present in the exact source bytes. Later deletes do not
remove current seed members, and later updates cannot alter the current
authorized cohort.

New immutable enrollments use the closed provenance values
`capture_start_seed`, `exact_source_observation`, or
`capture_start_seed_and_exact_source_observation`. The provenance is included in
the enrollment fingerprint. The authorization version, authorized-at instant,
seed count, seed fingerprint, and exact source-expansion policy are included in
the generation fingerprint through the owning write-once claim contract.

Enrollment never claims history before its effective generation. An identity
first enrolled from the capture-start seed and absent from that generation's
source receives a physical `present=false` observation beginning with that
generation only. An identity first discovered in the source receives
`present=true`. Deleting mutable staging later cannot erase either enrollment
or its subsequent absence history.

Every expected enrollment authorized by the capture-start-plus-exact-source
algorithm changes the cohort
fingerprint and starts exactly one new cohort epoch. The same generation that
atomically writes all new enrollments and the complete effective cohort's
physical observations is itself the new epoch's `qualified_baseline` when all
non-comparative integrity gates pass. Multiple authorized enrollments in that
transaction create one epoch and one baseline, not a sequence of intermediate
epochs. The baseline has `predecessor_snapshot_generation_id = null`,
`comparable=false`, and `product_drop_percent=null`; it does not emit
`capture_cohort_changed`. This is required because the current V1 reader
requires the exact same identity set in every selected snapshot. A V1 evidence
window may include only later qualified comparable generations from that one
unchanged cohort epoch. It must not synthesize false observations before an
identity was enrolled.

`capture_cohort_changed` is reserved exclusively for a deterministic
authorization failure: the persisted authorization members no longer match the
claim count/fingerprint, a final identity belongs to neither the seed nor exact
source-observation set, the policy version changes during execution, immutable
enrollment ownership differs, or another stated authorization invariant fails.
Valid new identities in the exact captured source do not emit this reason. An
unauthorized failure freezes the generation, cannot silently create a baseline
or absence fact, and requires separate operator investigation or authorization.
A later explicitly authorized expansion begins another epoch under the baseline
rule above.

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

Creation order is the additive `import_jobs` ownership index, execution claims,
the execution-path immutable trigger, dispatch outbox, dispatch recovery
authorizations plus their UPDATE/DELETE triggers, dispatch recovery results plus
their UPDATE/DELETE triggers, cohort authorization members, generation headers,
enrollments, observations, and the additive ImportHistory range index. Rollback
is the exact reverse order: each table's guards are dropped immediately before
that table, and the path trigger is dropped before the claim table. Rollback
fails closed while retained rows make a `RESTRICT` dependency non-empty; it
never disables foreign-key checks or drops a parent first.

### `supplier_import_execution_claims`

| Index | Ordered columns | Unique | Supported boundary |
| --- | --- | --- | --- |
| `PRIMARY` | (`id`) | yes | claim identity |
| `uq_import_execution_claim_logical_key` | (`logical_execution_key`) | yes | exact retry/redelivery lookup and one claim per logical execution |
| `uq_import_execution_claim_id_key` | (`id`, `logical_execution_key`) | yes | exact composite parent for the outbox claim/key pair |
| `ix_import_execution_claim_supplier` | (`supplier_id`) | no | `fk_import_execution_claim_supplier` to `suppliers.id` |
| `ix_import_execution_claim_feed` | (`supplier_feed_id`) | no | `fk_import_execution_claim_feed` to `supplier_feeds.id` |
| `uq_import_execution_claim_run` | (`supplier_import_run_id`) | yes | `fk_import_execution_claim_run` to `supplier_import_runs.id` and one claim per non-null orchestrated run |
| `uq_import_execution_claim_job` | (`import_job_id`) | yes | one ImportJob may belong to only one execution claim; this single-column key does not support the three-column ownership FK by itself |
| `ix_import_execution_claim_job_owner_fk` | (`import_job_id`, `supplier_id`, `supplier_feed_id`) | no | exact child-side ordered support for `fk_import_execution_claim_job_scope`; prevents an implicit MySQL-generated FK index |
| `uq_import_execution_claim_history` | (`import_history_id`) | yes | `fk_import_execution_claim_history` to `import_histories.id` and at most one claim per history |
| `ix_import_execution_claim_scope_state` | (`supplier_id`, `supplier_feed_id`, `state`, `id`) | no | bounded active/terminal claim inspection for one supplier/feed |

All five claim parent foreign keys use `RESTRICT`. MySQL permits multiple
`NULL` entries in nullable unique `uq_import_execution_claim_run`, while every
non-null SupplierImportRun ID can occur in only one claim. That unique index is
also the exact leftmost FK-support index; the redundant non-unique
`ix_import_execution_claim_run` is not created. Legacy claims remain null in
this column. The logical-key query is
`WHERE logical_execution_key = ?`. Active-state inspection is
`WHERE supplier_id = ? AND supplier_feed_id = ? AND state IN (...) ORDER BY id`.
The exact composite ownership foreign key
`fk_import_execution_claim_job_scope` uses
(`import_job_id`, `supplier_id`, `supplier_feed_id`) and references the future
unique `import_jobs` key with those columns in the same order. It guarantees
that a bound job belongs to the claim's supplier and feed. The retained
single-column `uq_import_execution_claim_job` enforces one claim per ImportJob
but cannot satisfy MySQL's three-column child-index requirement. The separate
named `ix_import_execution_claim_job_owner_fk` must exist with all three child
columns first and in exactly the FK order, so MySQL creates no implicit child
index. The parent tuple must use the named compatible unique index below with
the same column order and compatible column types, character sets, and
collations. An unnamed or MySQL-generated FK-support index is a migration
failure.

### `supplier_import_cohort_authorization_members`

| Index | Ordered columns | Unique | Supported boundary |
| --- | --- | --- | --- |
| `PRIMARY` | (`id`) | yes | authorization-member identity |
| `uq_import_cohort_auth_claim_offer` | (`supplier_import_execution_claim_id`, `supplier_sku_hash`) | yes | exact immutable capture-start seed, left-prefix support for `fk_import_cohort_auth_claim`, and bounded sorted fingerprint traversal |

The named foreign key `fk_import_cohort_auth_claim` references
`supplier_import_execution_claims.id` with `RESTRICT`. The unique index is the
only authorized membership traversal:
`WHERE supplier_import_execution_claim_id = ? ORDER BY supplier_sku_hash`.
It supports exact count/fingerprint reconciliation without an implicit MySQL
index or mutable application-state join.

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
| `uq_import_dispatch_outbox_id_claim` | (`id`, `supplier_import_execution_claim_id`) | yes | exact composite parent for recovery authorization outbox/claim binding |
| `ix_import_dispatch_outbox_due` | (`state`, `next_attempt_at`, `id`) | no | bounded pending recovery page |
| `ix_import_dispatch_outbox_lease` | (`state`, `lease_expires_at`, `id`) | no | bounded stale-lease recovery page |
| `ix_import_dispatch_outbox_state_watchdog_id` | (`state`, `delivery_watchdog_at`, `id`) | no | bounded stale acknowledged-payload selection ordered by watchdog and ID |

Both outbox foreign keys use `RESTRICT`. The two claim references are
deliberately redundant: the simple relationship supports ownership traversal,
while the composite relationship prevents a claim ID and logical key from
different executions being paired. The implementation must name both
constraints explicitly and must not rely on an implicit MySQL index.

### `supplier_import_dispatch_recovery_authorizations`

| Index | Ordered columns | Unique | Supported boundary |
| --- | --- | --- | --- |
| `PRIMARY` | (`id`) | yes | immutable authorization identity |
| `uq_import_recovery_auth_nonce` | (`authorization_nonce_hash`) | yes | one opaque authorization nonce |
| `ix_import_recovery_auth_claim` | (`supplier_import_execution_claim_id`, `authorized_at`, `id`) | no | `fk_import_recovery_auth_claim` and bounded claim audit |
| `ix_import_recovery_auth_outbox_claim` | (`supplier_import_dispatch_outbox_id`, `supplier_import_execution_claim_id`) | no | `fk_import_recovery_auth_outbox_claim` to the exact outbox/claim pair |
| `ix_import_recovery_auth_operator` | (`authorized_operator_id`, `authorized_at`, `id`) | no | `fk_import_recovery_auth_operator` and bounded operator audit |

All three named foreign keys use `RESTRICT`. The composite outbox FK references
`uq_import_dispatch_outbox_id_claim` and prevents an authorization from pairing
one claim with another claim's outbox. The claim and operator FKs reference
`supplier_import_execution_claims.id` and `users.id`. No implicit FK index is
accepted.

### `supplier_import_dispatch_recovery_results`

| Index | Ordered columns | Unique | Supported boundary |
| --- | --- | --- | --- |
| `PRIMARY` | (`id`) | yes | immutable result identity |
| `uq_import_recovery_result_auth_sequence` | (`supplier_import_dispatch_recovery_authorization_id`, `event_sequence`) | yes | ordered immutable event identity and left-prefix support for `fk_import_recovery_result_auth` |
| `uq_import_recovery_result_auth_started` | (`supplier_import_dispatch_recovery_authorization_id`, `started_once_guard`) | yes | at most one `started`; nullable unique permits all terminal rows |
| `uq_import_recovery_result_auth_terminal` | (`supplier_import_dispatch_recovery_authorization_id`, `terminal_once_guard`) | yes | at most one terminal result; nullable unique permits the start row |
| `ix_import_recovery_result_claim` | (`supplier_import_execution_claim_id`, `occurred_at`, `id`) | no | `fk_import_recovery_result_claim` and bounded claim audit |
| `ix_import_recovery_result_outbox_claim` | (`supplier_import_dispatch_outbox_id`, `supplier_import_execution_claim_id`) | no | exact result outbox/claim binding |
| `ix_import_recovery_result_operator` | (`executed_operator_id`, `occurred_at`, `id`) | no | `fk_import_recovery_result_operator` and bounded operator audit |

All named foreign keys use `RESTRICT`. The authorization, claim, composite
outbox/claim and operator references use the exact named indexes above. An
identical completed rerun reads the existing terminal event, while a
conflicting reuse fails closed.

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
synthetic databases. Migration acceptance must inspect both `SHOW CREATE TABLE`
and `information_schema.statistics` and prove the retained single-column unique
key, exact named child index
`ix_import_execution_claim_job_owner_fk(import_job_id, supplier_id,
supplier_feed_id)`, named parent unique key, compatible column definitions, and
absence of any unnamed or MySQL-generated FK index. It must also prove
`uq_import_execution_claim_run`,
`chk_import_execution_claim_path_parent`, the recovery authorization/result
tables, and their exact checks, indexes, and `RESTRICT` FKs. Malformed-row tests
must reject a duplicate non-null SupplierImportRun parent, an unknown
`execution_path`, every case/whitespace/control/null-byte/lookalike path variant,
orchestrated-without-run, legacy-with-run, legacy pair-null, half-bound
feed/job, mismatched recovery parent, ambiguous terminal parent, mismatched
authorization outbox/claim, invalid action, invalid hash, non-900-second expiry,
duplicate event sequence, duplicate start and duplicate terminal result. Direct
and concurrent UPDATE tests must reject an `execution_path` byte change while
unrelated permitted state updates succeed. Authorization tests must cover
double start/terminal consumption, expiry, state-fingerprint mismatch, every
crash point above and parent-deletion races. They must accept multiple legacy
claims whose nullable run parent is `NULL`. The
expected named key must
be selected; access type must be `const`, `eq_ref`, `ref` or bounded `range`;
no unbounded full-table scan is accepted; estimated rows must remain bounded by
the selected supplier/feed/generation interval; and any filesort or temporary
table must be explicitly justified and bounded.

The stale-payload migration test additionally requires MySQL `EXPLAIN` to select
`ix_import_dispatch_outbox_state_watchdog_id` for the exact candidate query,
use `range` access with used key parts including `state` and
`delivery_watchdog_at`, satisfy deterministic `delivery_watchdog_at, id`
ordering through the index without a full-table filesort, and use a named
PK/FK-compatible key for the joined claim lookup. A full outbox scan or terminal
null-watchdog rows entering the due range is a migration-acceptance failure.
MySQL's optimizer row estimate is not required to be less than or equal to the
requested page limit; `LIMIT` bounds returned rows, not a universal estimate.

Future `EXPLAIN ANALYZE FORMAT=TREE` acceptance uses a terminal-heavy synthetic
fixture with at least 100,000 terminal or processing `published` outboxes whose
watchdog is null, 75 due `queued/published` outboxes, 75 future
`queued/published` outboxes, and limit 50. It must report fixture size and actual
iterator counts, prove the watchdog range is used, prove terminal null-watchdog
rows are not visited as due candidates, return the first 50 due rows in
deterministic order, and show actual outbox rows visited proportional to the due
non-null range rather than the historical terminal population. No acceptance
rule compares an optimizer estimate to `LIMIT`.

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
- Capture-start authorization fingerprints use `sample()` with bucket
  `snapshot_cohort_authorization_v1`, the exact authorization version, and the
  deterministically sorted authorization-member hashes.
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
| `supplier_import_execution_claims` | `logical_execution_key` | `active_attempt_token_hash`, `source_fingerprint`, `cohort_seed_fingerprint` |
| `supplier_import_dispatch_outbox` | `logical_execution_key`, `dispatch_payload_hash` | `lease_token_hash` |
| `supplier_import_dispatch_recovery_authorizations` | `expected_state_fingerprint`, `authorization_nonce_hash` | none |
| `supplier_import_dispatch_recovery_results` | `result_fingerprint` | none |
| `supplier_import_cohort_authorization_members` | `supplier_sku_hash` | none |
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

- no UPDATE or DELETE path for either dispatch-recovery audit table, the
  authorization-member table or any of the three evidence tables;
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

The recovery tables use these exact independently tested trigger names, all
within MySQL's identifier limit:

```text
trg_import_recovery_auth_no_update
trg_import_recovery_auth_no_delete
trg_import_recovery_result_no_update
trg_import_recovery_result_no_delete
```

Each trigger raises an error before the attempted mutation. Their models reject
update, delete, force-delete, increment, decrement and touch; expose no mutable
mass-assignment surface; and have no mutable repository method. All parent FKs
use `RESTRICT`. Retention requires a separately authorized future phase.
Malformed direct UPDATE/DELETE, concurrent double-consumption and parent-delete
race tests must prove the database guards independently of model events.

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
5. Before download, a consistent-snapshot MySQL transaction streams prior
   enrollments and current applicable application identities into the bounded
   mode-0600 capture-start authorization spool. Concurrent inserts are deferred,
   and concurrent updates/deletes cannot change this transaction's snapshot.
6. The authorization collector validates, hashes, sorts, and deduplicates the
   seed. Under outbox-then-claim locks it inserts every immutable authorization
   member and writes the claim's version, MySQL UTC timestamp, count, and
   fingerprint in the same commit. No source request is permitted before this
   commit.
7. `SsrfProtectionService::downloadToTemporaryFile()` downloads once to its
   restricted system temporary file.
8. The process opens the mode-0600 file without following links, records its
   file identity and size, and hashes its exact bytes incrementally.
9. A behavior-equivalent streaming XML parser traverses that same restricted
   file while its identity is held. Before deletion, a second bounded local
   hash pass must reproduce the first digest and the file identity/size must be
   unchanged. Any mismatch freezes capture. This is not a second fetch or
   download and proves that parsing and the stored fingerprint refer to the
   same downloaded bytes.
10. The streaming parser consumes the file without a complete XML tree; the
    existing complete SimpleXML tree and extracted row array must not remain in
    the capture-enabled path.
11. Immediately before existing mapping, validation, counters, failure inserts,
    or staging writes can mutate state, the coordinator owner-checks the lock,
    claim, published outbox, and complete authorization count/fingerprint, then
    compare-and-sets `queued -> processing`. Existing importer behavior executes
    for each streamed row exactly as before and cannot be replayed for this key.
12. The observer writes only fixed-size privacy-safe canonical source
    observation records to a separate mode-0600 temporary spool. It never writes
    raw identifiers, XML, credentials, URLs, paths, names, or payloads.
13. Each spool has explicit row and byte ceilings derived from the approved
    maximum import row count and canonical record size. Neither introduces a
    smaller source-file limit than the authorized importer. Exceeding a capture
    ceiling yields `overflow`; no prefix is represented as complete.
14. Finalization uses bounded external sort/deduplication and a streamed merge
    of immutable authorization members, prior enrollments, and the exact-source
    observation collector. It never rereads mutable application membership.
    Memory remains bounded by configured chunk size, not source or cohort size.
15. The final cohort must equal the authorized seed union the valid exact-source
    set. Any other identity, policy-version change, authorization mismatch, or
    immutable ownership mismatch freezes with `capture_cohort_changed`.
16. One database transaction on the import connection inserts new enrollments,
    the final header, deterministic chunks of exhaustive physical observations,
    all final privacy-safe fingerprints/counts, the terminal ImportHistory CAS,
    importer-equivalent terminal ImportJob/feed fields, authoritative terminal
    SupplierImportRun fields when applicable, and the live-owner terminal claim
    CAS.
17. The source file and both privacy-safe spools are removed in `finally` on
    success, failure, signal-driven worker termination where PHP cleanup runs,
    and repository retry. A startup janitor may remove only stale files bearing
    a capture-specific random prefix and correct owner/mode; it is not evidence
    and must never inspect or log contents.

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

### Live-owner atomic terminal transition service

The current `ImportHistory::transitionForImport()` owns its own transaction and
therefore is not the future finalization API. The implementation must introduce
a transaction-aware
`TransactionalImportTerminalRepository::finalizeOwnedProcessing()` method that
accepts the caller's active database connection and transaction. It locks the canonical outbox first, the
claim second, and then the expected ImportHistory and applicable parents. It
requires `outbox.state = published`, `ImportHistory.event = started`, exact
`claim.state = processing`, the raw active-attempt token, matching persisted
token hash, currently owned Redis supplier lock, and complete unexpired
ownership tuple. It performs every compare-and-set inside the same transaction
as evidence insertion. Recovery publication uses the same lock order, so it
cannot race terminal finalization. This API may finalize qualified, frozen, or
live processing-failed outcomes. It is never called by abandoned-owner recovery
and has no token-bypass mode.

The abandoned path is separately owned by
`AbandonedSupplierImportTerminalRepository::failExpiredProcessing()`. It accepts
only the new supplier-lock proof and complete persisted expired tuple described
below. The two methods have different parameter objects and neither delegates
to the other.

That finalization transaction also applies the current importer's terminal
ImportJob and SupplierFeed status/timestamp fields from the already-computed
result. For the orchestrated path it atomically writes the authoritative
SupplierImportRun status, bound feed/job, `started_at`, `finished_at`,
`duration_seconds`, `products_seen`, `products_failed`, warning/error counts,
and allowlisted terminal reason. `terminal_qualified` maps ImportHistory to
`finished`. `terminal_frozen` maps it to `finished` when staging completed but
capture qualification froze, and to `failed` when the importer never crossed
into a valid completed result. `terminal_failed` maps it to `failed`.

The canonical terminal outbox mapping is exact:

| Terminal boundary | Claim | Outbox | Evidence/parent rule |
| --- | --- | --- | --- |
| qualified finalization | `terminal_qualified` | `published` | one complete qualified generation; successful/warning authoritative parents |
| frozen finalization or pre-mutation fingerprint conflict | `terminal_frozen` | `published` | frozen header-only outcome or documented zero-generation conflict; exact terminal parents |
| live-owner execution failure after `processing` | `terminal_failed` | `published` | raw-token/Redis-lock proof; no qualified generation; applicable parents/history failed atomically |
| abandoned-owner processing recovery | `terminal_failed` | `published` | new supplier lock plus expired persisted tuple; no raw old token, generation, evidence, or replay |
| recoverable pre-processing failure, unobserved published payload, or expired queued owner | non-terminal `queued` | `recovery_required` | both transport boundaries valid; exact recoverable reason; no importer/evidence; parents preserved at their exact boundary |
| irrecoverable pre-processing delivery/deadline exhaustion | `terminal_failed` | `terminal_failed` | exact transport reason; every applicable parent closed atomically; no importer/evidence |
| failed eighth dispatch publication or irreconcilable dispatch binding | `terminal_failed` | `terminal_failed` | exact publication reason; every applicable parent closed atomically; no importer/evidence |

A `pending_dispatch` claim paired with `recovery_required`,
`processing/recovery_required`, qualified or frozen terminal claims with
`recovery_required`, and any terminal claim with an outbox state outside this
table are forbidden and fail the whole transaction.

Only the non-authoritative detailed `SupplierImportRun.report` projection and
derived secondary aggregates may be rebuilt idempotently after commit from the
stored terminal result. Rebuilding may not alter authoritative terminal status,
timestamps, parent bindings, critical counts, or reasons and never reruns the
importer. The legacy path has no SupplierImportRun.

Each terminal compare-and-set must affect exactly one row or throw so the whole
transaction rolls back. A terminal claim with `ImportHistory=started`, a
terminal ImportHistory without its matching terminal claim outcome, or a
terminal claim/outbox pair outside the table above is forbidden. On rollback no
generation, enrollment, observation, terminal ImportHistory, terminal claim,
outbox mutation, or final fingerprint/count becomes visible. Because rollback
occurs after `processing`, automatic importer replay remains forbidden; manual
abandoned-processing recovery closes the canonical published-outbox pair as
failed.

### Crash and recovery matrix

`IH` below means the one bound ImportHistory. `None` under evidence means no
committed generation, enrollment, or observation.
`N/A — legacy path has no SupplierImportRun` is abbreviated in cells as
`legacy: N/A`. Every recovery query is bounded and owner-checked. This canonical
matrix has exactly 36 data rows and 11 columns.
Every `processing/published` or terminal-claim/`published` cell below implies a
null `delivery_watchdog_at`; only a `queued/published` cell may carry the
non-null watchdog, and every transition away from that pair clears it in the
same transaction.

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
| 17. Processing CAS commits | both | orchestrated: `running`; legacy: N/A | `running` | `started` | owner-held `processing`, pair-bound | `published` | none | live owner continues once; a hard crash waits for lease expiry and the separate abandoned-owner API | automatic importer replay, new allocation, or abandoned recovery using the old raw token | authorize abandoned-processing recovery after verified expiry if owner is lost |
| 18. During first or later staging mutation | both | orchestrated: `running`; legacy: N/A | `running` | `started` | owner-held `processing` | `published` | none committed | live owner continues; otherwise the new-lock/expired-tuple abandoned-owner API closes failed | download retry, importer replay, partial evidence, or hidden token bypass | authorize recovery, then separately authorize any new execution |
| 19. Importer completes before finalization | both | orchestrated: `running`; legacy: N/A | `running`; computed terminal result exists only in bounded local facts | `started` | owner-held `processing` | `published` | none committed | live owner finalizes with raw-token/lock proof; a hard crash uses separate abandoned-owner recovery after expiry | importer replay or evidence reconstructed from mutable staging | authorize recovery only after owner loss and lease expiry |
| 20. Finalization transaction rolls back | both | orchestrated: `running`; legacy: N/A | `running` | `started` | `processing` with complete persisted expired tuple after lease expiry | `published` | none committed | dedicated abandoned-owner API locks `published` outbox then claim/parents and records `processing_lease_abandoned` | importer replay, partial terminal repair, or reuse of live-owner API without its raw token | authorize bounded recovery after proving rollback and expiry |
| 21. Finalization commits before queue acknowledgement | both | orchestrated: authoritative terminal `completed`, `completed_with_warnings`, or `failed`; legacy: N/A | authoritative terminal `completed`, `completed_with_errors`, or `failed` | `finished` or `failed` | exactly `terminal_qualified`, `terminal_frozen`, or `terminal_failed` according to the committed outcome | `published` | exact qualified set, frozen header-only outcome, or zero-header failed/fingerprint-conflict gap defined below | duplicate delivery returns stored terminal result | any rerun, rebinding, or terminal mutation | none |
| 22. Duplicate after any terminal state | both | orchestrated: unchanged `completed`, `completed_with_warnings`, or `failed`; legacy: N/A | unchanged `completed`, `completed_with_errors`, or `failed` | unchanged `finished` or `failed` | unchanged `terminal_qualified`, `terminal_frozen`, or `terminal_failed` | unchanged `published` or `terminal_failed` | unchanged qualified set, frozen header-only outcome, or zero-header failed/fingerprint-conflict gap | deterministic no-op | source access, importer, new evidence, or terminal rewrite | new authorization only for a genuinely new execution |
| 23. Delivery eight reaches pair-null queued guard | orchestrated | atomically `failed` | absent | absent | pair-null `terminal_failed` with `transport_delivery_budget_exhausted` | `terminal_failed` with same reason | none | no recovery or retry; a later import requires new authorization | `recovery_required`, new claim/job, source access, or stale-payload ownership | inspect transport, then separately authorize any new execution |
| 24. Deadline/budget exhaustion reaches pair-bound queued guard without history | both | orchestrated: atomically `failed`; legacy: N/A | atomically `failed` | absent | pair-bound `terminal_failed` with exact `transport_deadline_expired` or `transport_delivery_budget_exhausted` | `terminal_failed` with same reason | none | no recovery or retry; a later import requires new authorization | second ImportJob, `recovery_required`, direct importer invocation, or source access | inspect transport, then separately authorize any new execution |
| 25. Deadline/budget exhaustion reaches pair-bound queued guard with started history | both | orchestrated: atomically `failed`; legacy: N/A | atomically `failed` | atomically `failed` | pair-bound `terminal_failed` with exact transport reason | `terminal_failed` with same reason | none | no recovery or retry; a later import requires new authorization | second history/job, `recovery_required`, processing, or inferred evidence | inspect transport, then separately authorize any new execution |
| 26. Recoverable callback commits before a transport boundary later expires | both | orchestrated: initially unchanged `pending` or `running`, then atomically `failed` if boundary expires; legacy: N/A | initially unchanged, then atomically `failed` if boundary expires | initially unchanged, then atomically `failed` when present | initially `queued` with all-null owner, then `terminal_failed` if no longer recoverable | initially `recovery_required`, then `terminal_failed` with exact transport reason | none | republish only while deadline and delivery budget remain valid; otherwise reconciler atomically terminalizes claim/outbox/parents | refusing republication without terminalization, stale ownership, source work, or importer replay | authorize one bounded reconciler run |
| 27. In-handle exception while current attempt owns `processing` | both | orchestrated: atomically `failed`; legacy: N/A | atomically `failed` | atomically `failed` | atomically `terminal_failed` with `capture_processing_failed` and cleared ownership | unchanged `published` | none | no replay; later execution needs new authorization | importer replay, republish, or inferred evidence | inspect failure, then separately authorize any new execution |
| 28. Transport-only `failed()` sees `processing` | both | orchestrated: unchanged `running`; legacy: N/A | unchanged `running` | unchanged `started` | unchanged `processing` with complete original ownership tuple | unchanged `published` | none | after verified expiry, dedicated abandoned-owner recovery acquires a new supplier lock and CASes the persisted tuple without the old raw token | outbox regression, owner replacement, live-owner token bypass, direct terminal updates, lock release, or importer replay | authorize the abandoned-processing reconciler after verified expiry |
| 29. Publication attempt 8 succeeds | both | unchanged at the exact pre-processing boundary; orchestrated may remain `pending`; legacy: N/A | unchanged at the exact pre-processing boundary; may be absent or `pending` | absent | initial publication changes `pending_dispatch -> queued`; recovery publication preserves `queued` | literal `published` with `attempt_count=8` | none | normal same-key delivery; terminal checks still precede all work | terminalizing solely because ordinal is eight or permitting attempt nine | none |
| 30. Publication attempt 8 fails or remains irreconcilably ambiguous | both | orchestrated: atomically `failed`; legacy: N/A | bound ImportJob atomically `failed` when present | atomically `failed` when present | atomically `terminal_failed` with `dispatch_publication_attempts_exhausted` | atomically `terminal_failed` with same reason | none | no ninth publication; any actually accepted stale payload sees terminal claim and no-ops | leaving parent pending/queued, another publication, new job under old key, or importer replay | inspect queue transport, then separately authorize a new execution |
| 31. Stale queued attempt lease | both | orchestrated: unchanged `pending` before history or `running` after history; legacy: N/A | unchanged absent, `pending`, or `running` according to allocation/history boundary | unchanged absent or `started` | `queued` with a complete expired pre-processing owner | `published` with durable watchdog | none | new supplier lock plus complete-tuple CAS clears ownership and writes `queued_ownership_lease_expired` for bounded same-key recovery, or atomically terminalizes on exhausted delivery/deadline | live-owner takeover, `forceRelease()`, second parent/history, lost-token fabrication, source work, or ownership while outbox is `recovery_required` | authorize one bounded outbox reconciliation if delivery admission did not invoke the repository |
| 32. Stale processing attempt lease | both | orchestrated: unchanged `running`; legacy: N/A | unchanged `running` | unchanged `started` | expired `processing` with complete persisted tuple | unchanged `published` | none | new supplier lock plus `outbox -> claim -> parents` abandoned-owner CAS; winner of live-owner/finalization race is authoritative | outbox regression, owner replacement, old raw-token requirement, download, importer replay, or direct terminal repair | authorize bounded abandoned-processing recovery |
| 33. Different source fingerprint under same key | both | orchestrated: atomically `failed`; legacy: N/A | atomically `failed` without importer replay | atomically `failed` when already started | atomically `terminal_frozen` with first digest retained | unchanged `published` | no generation | no replay; new logical execution requires new authorization | digest replacement, parser/staging, or evidence generation | investigate source identity, then authorize a new execution if appropriate |
| 34. Publication acknowledged but Redis payload is lost before `handle()` | both | orchestrated: unchanged `pending`; legacy: N/A | unchanged absent or `pending` | absent | `queued` with null owner | `published` with due `delivery_watchdog_at` | none | the indexed read-only monitor raises privacy-safe warning/critical evidence; only an unexpired exact authorization lets the command write `dispatch_payload_unobserved` and republish the same key before the operator objective, or terminalize with the actual exhausted transport reason / `dispatch_watchdog_response_expired` after it | autonomous mutation, a claim that monitoring guarantees terminalization, new key/event/job, source access, importer, evidence, or ninth publication | review the due execution and create one exact 900-second authorization; without action it remains nonterminal and critically alerted |
| 35. Complete expired `queued/published` pre-processing owner | both | orchestrated: unchanged `pending` or `running`; legacy: N/A | unchanged absent, `pending`, or `running` | unchanged absent or `started` | `queued` with complete expired tuple | `published` with due watchdog | none | the monitor detects the exact row without mutation; only an unexpired exact authorization lets `ExpiredQueuedImportTerminalRepository::resolveExpiredQueuedOwnership()` win the supplier lock and complete-tuple CAS, then perform the authorized same-key recovery before the objective or exact terminalization | autonomous owner clearing, clearing a live/half-bound owner, old-token fabrication, importer replay, evidence, or parent drift | review and authorize the exact action; absent operator action the critical nonterminal state remains visible |
| 36. Explicit publication-mismatch terminal resolution and rerun | both | applicable orchestrated run atomically `failed`; legacy: N/A | applicable bound job atomically `failed` | applicable same-execution history atomically `failed` | eligible pre-processing claim atomically `terminal_failed` | atomically `terminal_failed` | none | exact-ID/key command under supplier and row locks records `dispatch_publication_mismatch`; identical rerun returns `already_terminal_noop` | broad selection, active-owner race, parent/key guess, source/import/staging/evidence work, or conflicting terminal rewrite | authorize the one-execution dry-run, review, then separately apply if still canonical |

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
`--apply` is mandatory; limits outside 1 through 50 fail closed; and exact claim
selection is supported. The command calls a dedicated abandoned-owner API, not
the live-owner terminal service. It acquires a new owner-checked supplier Redis
lock, reads MySQL `UTC_TIMESTAMP(6)`, and locks outbox, claim, ImportHistory,
ImportJob, and applicable SupplierImportRun in the canonical order. It accepts
only literal `processing/published`, a complete persisted ownership tuple with
`attempt_lease_expires_at < UTC_TIMESTAMP(6)`, the expected `started`
ImportHistory and parent bindings, and no terminal generation/header. It CASes
the complete expired tuple without the unavailable old raw token, changes the
history, job, applicable run, and claim to failed, clears ownership, leaves the
outbox `published`, and records `processing_lease_abandoned`. Any live-owner,
lease, state, fingerprint, parent, generation, or outbox mismatch affects zero
rows and fails the whole transaction. Thus live finalization winning the race
causes recovery to no-op, while recovery winning makes any late live-owner CAS
fail. Output is limited to IDs, counts, states, and allowlisted reason codes. It
creates no outbox row, job, import, generation, enrollment, observation,
schedule, or Catalog Sync action and never calls `XmlImportEngine`.

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

`capture_cohort_changed` means only a deterministic capture-start authorization
failure: member/count/fingerprint mismatch, a final identity outside both the
authorized seed and exact-source set, policy-version drift, or immutable
enrollment-ownership mismatch. A valid source-only addition admitted by the
versioned exact-source expansion rule is expected, produces a clean new
baseline with its complete observation set, and must not carry that reason;
because any non-empty reason set freezes, storing the reason on an authorized
expansion would be invalid.

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

An expected expansion authorized by the immutable capture-start seed plus
exact-source rule ends the prior epoch and makes that same complete enrollment
generation the next epoch's baseline. An authorization invariant failure
freezes with `capture_cohort_changed` and establishes
no new baseline. A source identity change, policy-semantic change, failed or
frozen generation, missing header, overlap, chronology ambiguity, or fingerprint
conflict ends the epoch; only the next later complete, clean generation may
become a baseline. A gap is never skipped and never interpreted as absence.

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
counter. An identity enrolled through an expected, authorized later expansion
changes the cohort epoch; that complete enrollment generation is the new
baseline, and three later consecutive qualified comparable absences are
required. The baseline is not absence 1, and the 48-hour clock begins only at
the first later comparable absence. A further authorized expansion repeats the
same reset. No mutable current row or pre-enrollment timestamp can shorten the
sequence.

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

All eight proposed tables may not contain raw supplier SKU, EAN/GTIN, MPN,
product name, description, raw source record, XML, feed URL, credential, raw
token, host path, container path, SEO, category, attribute, image, or
application secret. Hashed attempt/lease tokens and approved pseudonymous
digests follow the exact hexadecimal contract. Exception messages and log prose
are not evidence fields.

Dispatch coordination writes only the claim/outbox state machine and the exact
append-only recovery authorization/result audit. Capture writes only the three
new append-only evidence tables in addition to the importer's pre-existing
staging behavior. The snapshot evidence and recovery-audit persistence paths do
not:

- write or read-modify-write a Product;
- execute Catalog Sync CREATE or UPDATE;
- link, unlink, publish, hide, deactivate, or archive anything;
- mutate `supplier_products`, `product_supplier_offers`, mappings, categories,
  attributes, images, prices, or stock beyond the existing importer behavior;
- dispatch a job or enable APCOM; the only planned schedule addition is
  the non-mutating 300-second watchdog monitor; and
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
`history_gap_requires_new_baseline`. An expected, authorized cohort expansion
immediately returns to `qualified_baseline_only` for its new epoch when that
enrollment generation passes every non-comparative gate. Unexpected drift
changes state to `cohort_changed_requires_operator_investigation` and cannot
establish a baseline. Neither condition is skipped.

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
+ capture-start authorization-member rows per logical execution
+ dispatch-recovery authorization and result audit rows
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

This is the canonical 53-row fine-grained checkpoint matrix. Every
authorization row records an
explicit human/repository-owner decision and performs no technical action.
Every action row permits only its named action. Review is not push/PR; review is
not merge; merge is not deployment; deployment is not enablement; enablement is
not import; candidate creation is not approval; approval is not preview; result
review is not closeout. A failed row blocks every later row.

| # | Checkpoint | Prerequisite | Separately responsible authorization | Permitted action | Result/artifact | Failure behavior | Next |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | Local design independent approval | complete local eleven-commit design candidate | independent Security, Database and Catalog Sync Safety reviewers | review only | `APPROVED` verdict for exact diff | remediate locally; no push | 2 |
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
| 20 | Independent monitor implementation review approval | checkpoint 19 plus an exact monitor-only Draft PR created under the prior implementation authorization, with green zero-mutation tests | independent Release, Security and Catalog Sync Safety reviewers | review only; no merge, deployment or enablement | approval pinned to the exact monitor PR head | remediate monitor scope; PR remains unmerged | 21 |
| 21 | Monitor PR merge | checkpoint 20 approval and separate repository-owner merge authorization | Release/DevOps operator | merge only the exact approved monitor PR | monitor code in `main`; schedule remains disabled | PR remains open; no deployment | 22 |
| 22 | Disabled monitor deployment | checkpoint 21 merge and separate exact-commit deployment authorization | Release/DevOps operator | deploy exact `origin/main` with monitor schedule disabled | monitor deployed and verified unavailable to scheduler | rollback application state; schedule stays disabled | 23 |
| 23 | Explicit read-only monitor schedule enablement | checkpoint 22 verification and separate repository-owner/Catalog Sync Safety authorization | Release/Operations operator | enable only the 300-second read-only watchdog schedule | cadence, privacy and zero-mutation evidence | disable monitor schedule; no reconciler action | 24 |
| 24 | Authorize APCOM capture enablement | checkpoint 23 successful monitor verification | repository owner with Catalog Sync Safety approval | authorize capture enablement only | one enablement authorization | capture stays disabled | 25 |
| 25 | Enable and verify capture | checkpoint 24 authorization | Release/Operations operator | enable APCOM-specific gate and verify; do not import | enabled, verified default-off-import state | disable capture | 26 |
| 26 | Authorize one future APCOM import | checkpoint 25 or prior verified import | repository owner/operator for one named execution | authorize exactly one manual import | pinned one-import authorization | no import | 27 |
| 27 | Execute/verify authorized import | checkpoint 26 authorization | Supplier Import operator | run exactly one import and verify claim/outbox/generation | one qualified/frozen/failed generation or gap | no automatic retry; recover fail-closed | 26 or 28 |
| 28 | Verify warm-up/readiness | sufficient checkpoint 27 generations | independent Product Data Quality/Catalog Sync Safety reviewer | read-only readiness evaluation | baseline plus three comparable absences and 48-hour proof, or not-ready result | wait for separately authorized imports | 29 |
| 29 | Authorize evidence-producer implementation | checkpoint 28 ready evidence | repository owner | authorize producer code only | scoped authorization | producer remains absent | 30 |
| 30 | Implement/validate producer locally | checkpoint 29 authorization | implementation owner | implement bounded read-only V1 producer and tests | local validated producer commit | no candidate | 31 |
| 31 | Independent producer review | checkpoint 30 exact diff | independent Security/Product Data Quality/Catalog Sync Safety reviewers | review only | approval or findings | remediate; no push | 32 |
| 32 | Authorize producer push/Draft PR | checkpoint 31 approval | repository owner | authorize exact reviewed commit | recorded authorization | remain local | 33 |
| 33 | Create producer Draft PR | checkpoint 32 authorization | Release/DevOps operator | push/open Draft PR | pinned Draft PR | stop | 34 |
| 34 | Producer PR CI/review approval | checkpoint 33 PR | CI plus independent reviewers | checks/review only | green checks and approval | remediate through review | 35 |
| 35 | Authorize producer merge | checkpoint 34 evidence | repository owner | authorize merge only | merge authorization | PR remains open | 36 |
| 36 | Merge producer PR | checkpoint 35 authorization | Release/DevOps operator | merge exact approved PR | merge commit in `main` | stop | 37 |
| 37 | Authorize producer staging deployment | checkpoint 36 merge | repository owner | authorize exact deployment only | deployment authorization | no VPS action | 38 |
| 38 | Deploy producer | checkpoint 37 authorization | Release/DevOps operator | deploy read-only producer from exact `origin/main` | deployment evidence | rollback application state | 39 |
| 39 | Producer post-deployment verification | checkpoint 38 deployment | independent Release/QA reviewer | read-only verification | bounded/read-only/zero-mutation proof | block candidate work | 40 |
| 40 | Authorize evidence-candidate preparation | checkpoint 39 proof | repository owner/human decision owner | authorize one candidate preparation only | candidate-preparation authorization | no candidate | 41 |
| 41 | Prepare exact candidate | checkpoint 40 authorization | authorized evidence operator | create one pinned privacy-safe candidate | path, SHA-256 and evaluation timestamp | destroy/reject invalid candidate | 42 |
| 42 | Human approval of exact candidate | checkpoint 41 artifact | named human decision owner | approve exact path/hash/timestamp only | recorded exact-candidate approval | reject/destroy candidate | 43 |
| 43 | Authorize operational preview | checkpoint 42 approval | repository owner | authorize exactly one preview run | one-run authorization | no preview | 44 |
| 44 | Execute one operational preview | checkpoint 43 authorization | authorized operator | run exactly one read-only C3D.1 preview | report and zero-mutation evidence | stop; rerun needs new authorization | 45 |
| 45 | Independent operational-result review | checkpoint 44 report | independent Security/Product Data Quality/Catalog Sync Safety reviewers | review results only | approved result or findings | C3D.1 remains open | 46 |
| 46 | Authorize documentation closeout | checkpoint 45 approval | repository owner | authorize documentation edits only | closeout authorization | no edits | 47 |
| 47 | Implement closeout documentation | checkpoint 46 authorization | Documentation owner | update status/evidence docs only | local closeout commit | C3D.1 remains open | 48 |
| 48 | Independent closeout review | checkpoint 47 exact diff | independent Documentation/Safety reviewers | review only | approval or findings | remediate; no push | 49 |
| 49 | Authorize closeout push/Draft PR | checkpoint 48 approval | repository owner | authorize exact commit | recorded authorization | remain local | 50 |
| 50 | Create closeout Draft PR | checkpoint 49 authorization | Release/DevOps operator | push/open Draft PR | pinned Draft PR | stop | 51 |
| 51 | Closeout PR CI/review approval | checkpoint 50 PR | CI plus independent reviewers | checks/review only | green checks and approval | remediate through review | 52 |
| 52 | Authorize closeout merge | checkpoint 51 evidence | repository owner | authorize merge only | merge authorization | PR remains open | 53 |
| 53 | Merge closeout PR | checkpoint 52 authorization | Release/DevOps operator | merge exact approved documentation PR | closeout merge in `main` | C3D.1 remains open if merge fails | no later supplier phase without separate authorization |

Monitor review is not monitor merge. Monitor merge is not deployment. Disabled
monitor deployment is not schedule enablement. Monitor schedule enablement
authorizes only read-only detection and never reconciliation, import, evidence,
Catalog Sync or automatic mutation. Deployment is not capture activation.
Capture activation is not import authorization. Import completion is not
evidence approval.

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
database/migrations/*_create_supplier_import_dispatch_recovery_authorizations_table.php
database/migrations/*_create_supplier_import_dispatch_recovery_results_table.php
database/migrations/*_create_supplier_import_cohort_authorization_members_table.php
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
app/Models/SupplierImportDispatchRecoveryAuthorization.php
app/Models/SupplierImportDispatchRecoveryResult.php
app/Models/SupplierImportCohortAuthorizationMember.php
app/Models/SupplierOfferSnapshotGeneration.php
app/Models/SupplierOfferSnapshotEnrollment.php
app/Models/SupplierOfferSnapshotObservation.php
app/Data/Suppliers/Onboarding/SnapshotSourceIdentity.php
app/Repositories/Suppliers/ImmutableSupplierOfferSnapshotRepository.php
app/Repositories/Suppliers/SupplierImportExecutionClaimRepository.php
app/Repositories/Suppliers/SupplierImportDispatchOutboxRepository.php
app/Repositories/Suppliers/SupplierImportDispatchRecoveryAuthorizationRepository.php
app/Repositories/Suppliers/SupplierImportDispatchRecoveryResultRepository.php
app/Repositories/Suppliers/SupplierImportAllocationRepository.php
app/Repositories/Suppliers/SupplierImportStateInvariantRepository.php
app/Repositories/Suppliers/SupplierImportCohortAuthorizationRepository.php
app/Repositories/Imports/TransactionalImportGenerationStartRepository.php
app/Repositories/Imports/TransactionalImportTerminalRepository.php
app/Repositories/Imports/AbandonedSupplierImportTerminalRepository.php
app/Repositories/Imports/ExpiredQueuedImportTerminalRepository.php
app/Repositories/Imports/PublicationMismatchTerminalRepository.php
app/Services/Suppliers/SupplierImportExecutionLock.php
app/Services/Suppliers/SupplierImportDeliveryAdmissionService.php
app/Services/Suppliers/SupplierImportExecutionCoordinator.php
app/Services/Suppliers/SupplierImportInHandleFailureService.php
app/Services/Suppliers/SupplierImportDispatchOutboxPublisher.php
app/Services/Suppliers/SupplierImportTransportFailureService.php
app/Services/Suppliers/SupplierImportQueueTimingValidator.php
app/Services/Suppliers/Snapshots/SupplierImportCohortAuthorizationService.php
app/Services/Suppliers/Snapshots/SupplierOfferSnapshotCollector.php
app/Services/Suppliers/Snapshots/SupplierOfferSnapshotCaptureService.php
app/Services/Suppliers/Snapshots/ImportHistorySnapshotSourceAdapter.php
app/Services/Suppliers/Onboarding/OperationalSupplierOfferEvidenceProducer.php
app/Console/Commands/PrepareOperationalSupplierOfferLifecycleEvidence.php
app/Console/Commands/ReconcileSupplierImportDispatchOutbox.php
app/Console/Commands/MonitorSupplierImportDispatchWatchdogs.php
app/Console/Commands/ReconcileAbandonedSupplierImportExecutions.php
app/Console/Commands/ResolveImportPublicationMismatch.php
config/supplier_snapshot_capture.php
tests/Feature/SupplierOfferSnapshotPersistenceTest.php
tests/Feature/SupplierOfferSnapshotCaptureTest.php
tests/Feature/SupplierOfferSnapshotConcurrencyTest.php
tests/Feature/SupplierImportExecutionIdempotencyTest.php
tests/Feature/SupplierImportDispatchOutboxTest.php
tests/Feature/SupplierImportCrashRecoveryTest.php
tests/Feature/SupplierImportAllocationTest.php
tests/Feature/SupplierImportCohortAuthorizationTest.php
tests/Feature/SupplierImportQueueTimingTest.php
tests/Feature/SupplierImportFailedCallbackTest.php
tests/Feature/SupplierImportMysqlRedisRecoveryTest.php
tests/Feature/SupplierImportPublishedPayloadWatchdogTest.php
tests/Feature/SupplierImportDispatchRecoveryAuthorizationTest.php
tests/Feature/SupplierImportDispatchWatchdogMonitorTest.php
tests/Feature/SupplierImportPublicationMismatchResolutionTest.php
tests/Feature/OperationalSupplierOfferEvidenceProducerTest.php
tests/Unit/Suppliers/SupplierOfferSnapshotFingerprintTest.php
tests/Feature/SupplierOfferLifecycleDocumentationContractTest.php
```

The future schema migrations must add `allocated_at`, the exact pair-null,
pair-bound, ownership-tuple and outbox checks, the unique claim-to-ImportJob
allocation, nullable unique `uq_import_execution_claim_run`, exact
`chk_import_execution_claim_path_parent`, retained single-column
claim/job uniqueness, separately named
three-column child FK index, compatible composite ImportJob parent key,
immutable transport deadline, durable delivery counter,
`delivery_watchdog_at`, exact watchdog state checks and named selection index,
outbox recovery/terminal fields, the exact immutable dispatch-recovery
authorization/result tables and exact UPDATE/DELETE guards, the execution-path
write-once trigger, four write-once capture-start authorization
fields, and the exact immutable cohort authorization-member table/index defined
above. Every reverse migration must drop dependent result/authorization FKs and
tables before the outbox/claim parents and restore no redundant run index. The
result and authorization mutation triggers are dropped before their respective
tables, and the execution-path trigger is dropped before the claim table.
Migration tests must audit `SHOW CREATE
TABLE` and `information_schema.statistics`; an implicit FK index fails
acceptance. The owner repository must use one MySQL-generated timestamp CAS for
the 4,200-second database lease and fail closed before work on every
unsuccessful CAS.

The later runtime implementation must add the dedicated
`redis_supplier_import` connection, `supplier-imports` queue,
`SUPPLIER_IMPORT_QUEUE_RETRY_AFTER=3900`, and dedicated Docker worker while
leaving the shared `REDIS_QUEUE_RETRY_AFTER=1300` and unrelated worker queues
unchanged. Both job paths and outbox payload publication must route explicitly
to that connection/queue. Startup validation must prove the exact
`3600 < 3900 < 4200 < 4320` hierarchy, 60-second bootstrap bound, and queue
isolation before capture can be enabled.

The delivery-admission service owns the immutable MySQL deadline and cumulative
delivery-budget gate before supplier-lock acquisition and directly terminalizes
irrecoverable pre-processing exhaustion. The cohort authorization service owns
the consistent-snapshot seed/member commit before source work. The coordinator and
in-handle failure service own the raw-token/Redis-lock `try/catch/finally`
closeout. `SupplierImportTransportFailureService` is used by newly deserialized
`failed()` only for owner-independent outbox transport recovery or exact
pre-processing terminal exhaustion. The
state-invariant repository owns fixed-order cross-record
outbox/claim/parent transactions; the outbox and live-owner terminal
repositories enforce canonical state checks and terminal ownership clearing.
The abandoned-processing command and expired-queued-owner repository use
separate expired-tuple methods and never a live-token bypass. The scheduled
watchdog monitor owns only indexed read-only detection and notifications. The
outbox reconciler may mutate an exact stale-payload execution only through an
unexpired immutable authorization and records the immutable result, while the exact
publication-mismatch command owns only one explicitly identified execution.
MySQL/Redis integration tests must prove every 53-item acceptance criterion and
all 24 focused watchdog/authorization/mismatch cases above. These are planned
implementation requirements only; this documentation commit changes no
runtime, queue, Docker, environment, schema, worker, or feature-flag value.

Implementation remains split by the 53 checkpoints above. Review, push/PR,
merge, deployment, enablement, import, candidate preparation, candidate
approval, preview, result review, and closeout never share one authorization.

## Non-approval Boundary

This design does not authorize a migration, model, parser refactor, producer,
import hook, feature flag, real evidence candidate, supplier import, APCOM
schedule change, Catalog Sync action, Product mutation, retention cleanup,
deployment, or C3D.1 operational preview. Supplier #3 selection must not begin
while this prerequisite remains unresolved.

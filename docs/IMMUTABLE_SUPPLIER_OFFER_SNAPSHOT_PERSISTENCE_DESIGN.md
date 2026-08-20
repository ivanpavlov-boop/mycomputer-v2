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
is a local documentation-only complete-branch follow-up pending fresh independent
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

The future implementation adds four narrowly scoped mutable coordination tables,
three append-only authorization/audit tables, three append-only evidence tables,
and reuses `import_histories.id` as the attempt sequence marker:

1. `supplier_import_execution_claims` owns one stable logical execution across
   queue retry and redelivery. It coordinates an attempt but is not evidence.
2. `supplier_import_dispatch_outbox` durably owns exactly one initial queue
   publication for the authorized claim. It is mutable coordination state, not
   lifecycle evidence and not import authorization.
3. `supplier_import_dispatch_monitor_health` stores only the bounded monitor
   heartbeat/integrity coordination state required by the continuous gate.
4. `supplier_import_dispatch_alert_intents` durably owns privacy-safe,
   idempotent external alert delivery and acknowledgement state.
5. `supplier_import_dispatch_recovery_authorizations` stores one immutable,
   human-approved recovery action for one exact due claim/outbox state.
6. `supplier_import_dispatch_recovery_results` stores the immutable consumption
   result without mutating the authorization row.
7. `supplier_import_cohort_authorization_members` stores the immutable,
   privacy-safe hashed seed set authorized from one capture-start MySQL
   snapshot. It is coordination proof, not lifecycle evidence.
8. `supplier_offer_snapshot_generations` stores one immutable final capture
   header for one ImportHistory generation.
9. `supplier_offer_snapshot_enrollments` stores the first immutable enrollment
   of every hashed offer identity in a supplier/source cohort.
10. `supplier_offer_snapshot_observations` stores one physical `present=true` or
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
never accepts or fabricates the lost raw attempt token and is called only after
nonce-proven start of `recover_expired_queued_ownership` or
`terminalize_stale_dispatch`; there is no clear-first preparatory mutation. The
caller first acquires a new owner-checked supplier Redis lock; the repository then reads
MySQL `UTC_TIMESTAMP(6)` and transactionally locks `outbox -> claim ->
applicable parents`. It accepts only literal `queued/published`, a complete
ownership tuple whose `attempt_lease_expires_at < UTC_TIMESTAMP(6)`, no
generation or evidence, authoritative parent bindings, and no conflicting
terminal state. Its compare-and-set includes the complete persisted expired
tuple; every state, token-hash, timestamp, key, parent, evidence, or lock
mismatch affects zero rows.

With a future deadline, remaining delivery/publication budget and open response
window, only `recover_expired_queued_ownership` clears the exact expired tuple,
changes `published -> recovery_required`, keeps the claim `queued`, clears
`delivery_watchdog_at`, and records `queued_ownership_lease_expired` plus its
compatible immutable result. A separately issued `republish_same_key` may then
republish. If a transport, response or publication boundary is exhausted, that
release action is rejected without mutation; only a separately issued
`terminalize_stale_dispatch` transaction clears the bound ownership tuple,
changes claim and outbox to `terminal_failed`, closes every applicable
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
authorized `recovery_required -> published` acknowledgement before the newly published handler
may pass delivery admission or acquire ownership. If the deadline, response
window or delivery budget becomes exhausted after `recovery_required` was
committed, an unstarted `republish_same_key` is rejected. A started one commits
only its compatible no-domain-terminalization `action_stopped` result. A newly
issued `terminalize_stale_dispatch` then locks outbox, claim and applicable
parents and atomically changes `queued/recovery_required` to
`terminal_failed/terminal_failed` with the exact reason. Terminal resolution is
always available through a new exact action, never inherited by republish
authority.
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
eight may also succeed and acknowledge `published`. A failed eighth initial
publisher attempt is terminal and operator-visible under the publisher's exact
non-recovery contract. A failed eighth attempt made by `republish_same_key`
instead commits only `publish_failed/dispatch_publication_attempts_exhausted`
and requires a new `terminalize_stale_dispatch` authorization; the recovery
authorization never changes action. For the initial pair-null
`pending_dispatch` publisher path, one transaction then marks outbox and claim
`terminal_failed` and the orchestrated run failed; legacy is normally
pair-bound and closes its pending ImportJob too. For an authorized
`recovery_required` queued claim, the separately authorized terminal action
closes the claim, bound
ImportJob, any started ImportHistory, and authoritative SupplierImportRun fields
as `terminal_failed` with `dispatch_publication_attempts_exhausted`. A stale
eighth recovery lease whose publication cannot be proved closes only the
republish result; after exact terminal authorization, any payload actually
accepted by Redis later observes the terminal claim and no-ops. An
irreconcilable claim/key/parent acknowledgement
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
19. every one of the 66 crash rows uses canonical claim/outbox/parent or
    coordination states;
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
    authorized `recovery_required -> published` path while both transport
    boundaries remain valid; otherwise the republish action rejects or
    action-stops without domain terminalization and a separately issued exact
    terminal action is required to commit the terminal pair;
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
    `delivery_attempt_count <= 6`, leaving one work-admissible delivery; when
    either boundary closes, `republish_same_key` rejects or action-stops and only
    a separately issued `terminalize_stale_dispatch` authorization may
    atomically terminalize claim, outbox, and applicable parents;
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
41. a failed or irreconcilably ambiguous eighth initial publication may
    terminalize through the initial-publisher contract, while a failed eighth
    authorized recovery publication records only
    `publish_failed/dispatch_publication_attempts_exhausted` and requires a new
    exact terminal authorization; a binding mismatch uses the separately
    authorized `dispatch_publication_mismatch` path; and
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
48. the scheduled monitor selects the exact due range without domain mutation,
    writes only the dedicated heartbeat/alert-intent coordination rows, emits
    privacy-safe alerts, and makes stale/failed/unknown health reject protected
    activity through a 600-second monitor/sink boundary plus a separately
    persisted 120-second independent-observer boundary;
49. a recovery authorization can be created only through authenticated
    Filament issuance for one exact complete action/claim/outbox/key/parent/
    operator tuple, expires after exactly 900 seconds, covers all five mutating
    recovery actions, and fails closed on health, action, fingerprint, reason,
    nonce, state or expiry mismatch;
50. the exact authorized command records at most one `started` event and one
    terminal result; B0 validates the immutable post-start baseline before the
    first B1 reservation, every physical Redis call consumes one committed
    publication ordinal/generation/token first, a reservation enters its call
    boundary at most once, stale workers lose CAS, and publication success is
    recorded only after Redis acknowledgement in the same transaction as
    `durable_success`, `published` plus the new watchdog; database-only actions
    commit start/mutation/result together, and an identical rerun returns the
    stored terminal event without mutation;
51. after the 1,800-second operator objective, an unstarted same-key
    republication is rejected, a started republication action-stops without
    terminalization, and only a newly issued exact terminal authorization may
    terminalize, while absence of operator action leaves a visible critical
    nonterminal condition; and
52. authorization/result rows are append-only, omit secrets and raw source
    identity, bind the complete operator/action/claim/outbox/key/parent tuple by
    composite FK and fingerprint, and remain absent from monitor-only
    evaluation; and
53. the recovery protocol matrix has exactly 19 outcomes, represents all five
    canonical mutating recovery actions including a dedicated
    `terminalize_abandoned_processing` outcome, and contains
    zero statement permitting payload observation, delivery admission, lock
    contention, release, duplicate delivery or `failed()` to establish or
    refresh `delivery_watchdog_at`;
54. `chk_import_recovery_result_action_event_code` rejects every cross-action
    event/result pair and a started republish can only succeed, fail publication
    or action-stop without domain terminalization;
55. `recover_expired_queued_ownership` has one satisfiable issue predicate and
    one atomic complete-owner CAS before any cleared state exists, while a
    successor owner makes the stale CAS affect zero rows;
56. the alert domain is the exact NUL-terminated
    `supplier-import-dispatch-monitor-alert-v1` byte sequence and both canonical
    synthetic vectors reproduce their documented SHA-256 identity;
57. monitor, observer and alert coordination use the exact named MySQL columns,
    keys, checks, FK and generation-bound CAS contracts below; and
58. all five PR-producing rollout chains separately establish/implement a
    candidate, validate it, independently review it, remediate or record
    not-required, obtain fresh independent PASS, authorize and perform push,
    verify the exact remote branch, and only then create a Draft PR;
59. `expected_state_fingerprint_v2` has exactly 20 ordered fields including
    `claimed_at`, rejects missing/unknown/reordered fields, and reproduces its
    synthetic vector;
60. the canonical crash matrix has exactly 66 rows and 11 columns;
61. four dedicated monitor rows prove crash-before-cycle, persisted-cycle,
    successor-generation and stale-writer behavior without domain mutation;
62. ten dedicated alert-delivery rows distinguish attempts below eight,
    attempt-eight reservation, unknown exhausted, successor-generation,
    stale-worker and acknowledged states; and
63. dedicated observer-failure and uncertain-external-ACK rows retain fail-closed
    admission and durable uncertainty; and
64. an eighth alert attempt with unknown outcome CASes exactly to
    `delivery_outcome_unknown_exhausted`, remains at count eight, permits no
    automatic retry or attempt nine, and is neither `acknowledged` nor
    `permanent_failed` without authoritative evidence.

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
20. monitor cadence, due ordering, warning/critical thresholds, privacy-safe
    output, bounded heartbeat/alert-intent writes, 600-second monitor/sink stale
    derivation, 120-second observer stale derivation, external observer failure
    and zero supplier/catalog-domain writes/jobs;
21. exact authenticated issuance for all five recovery actions, 900-second
    expiry, server-derived pre-state fingerprint, complete tuple binding,
    nonce-hash uniqueness, CLI actor separation and conflict rejection;
22. post-objective republication rejection and exact authorized
    `dispatch_watchdog_response_expired` terminalization;
23. pre-start rollback, B0 resume-fingerprint validation, B1 durable physical-
    attempt reservation, Redis-acknowledgement ambiguity, competing
    authorization, complete composite result binding and deterministic terminal
    rerun; and
24. healthy/stale/failed/unknown monitor and sink states, startup/restart/crash
    gates, zero authorization/result creation during monitor evaluation, and
    rejection of capture, protected generation and recovery start while health
    is not healthy;
25. committed republish start whose response/deadline/delivery boundary closes
    before reservation or call boundary, with no call and exact action-stop
    result;
26. possible Redis acceptance followed by acknowledgement crash, exact
    `outcome_unknown` classification and later boundary closure, with no same-
    reservation call or inferred success;
27. release of republish authorization ownership only after its compatible
    terminal result, followed by exact new-action issuance;
28. complete-expired-owner authorization issued before any clear mutation;
29. expired-owner CAS losing to a successor owner with zero domain mutation;
30. atomic expired-owner release commit, crash-after-commit and replay;
31. warning/null-bucket alert canonical bytes and hash vector;
32. critical/zero-bucket alert canonical bytes and hash vector; and
33. monitor lease acquisition followed by crash before successful cycle state;
34. successful monitor-cycle persistence followed by process loss before a next
    acquisition;
35. expired monitor lease takeover by one successor generation;
36. stale monitor wake and late state/heartbeat CAS rejection;
37. alert-delivery lease acquisition below attempt eight followed by crash before
    an external call;
38. external alert attempt below eight followed by crash before durable
    acknowledgement;
39. below-eight alert-delivery lease expiry with the outcome retained as
    unknown from durable facts;
40. successor alert worker acquisition with a new delivery generation;
41. late stale alert worker acknowledgement/retry/call rejection;
42. acknowledged alert persistence followed by worker loss with no redelivery;
43. independent observer failure and 120-second stale admission rejection;
44. uncertain external alert acknowledgement below eight retained and retried
    only through the stable idempotency identity and next ordinal;
45. attempt-eight reservation followed by crash before the external call;
46. attempt-eight external call followed by crash before durable ACK;
47. exact expired generation/token/no-ACK CAS to
    `delivery_outcome_unknown_exhausted` with count eight;
48. no lease acquisition, automatic retry or attempt nine from unknown-exhausted;
49. no false `acknowledged` or `permanent_failed` transition from uncertainty;
50. one physical Redis call requiring one committed publication reservation and
    counter increment first;
51. one-use `reserved -> call_boundary_entered` generation/token CAS before
    every Redis call;
52. stale publication worker call/result/counter CAS rejection after successor
    classification or reservation; and
53. final Redis attempt unknown outcome closing only republish authority and
    requiring a separately issued exact terminal action.

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
| `publication_attempt_generation` | unsigned bigint | `0` | Monotonic physical-publication generation; increments with every durable reservation | aggregate |
| `publication_attempt_state` | varchar(32) ASCII | `none` | Latest physical publication attempt: `none`, `reserved`, `call_boundary_entered`, `durable_success`, `durable_failure`, or `outcome_unknown` | public contract |
| `publication_attempt_token_hash` | `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin` | nullable | SHA-256 of the in-memory one-attempt authority token | pseudonymous |
| `publication_attempt_reserved_at` | timestamp(6) | nullable | MySQL-UTC durable reservation instant | operational metadata |
| `publication_attempt_lease_expires_at` | timestamp(6) | nullable | Five-minute authority boundary for the exact generation | operational metadata |
| `publication_call_boundary_at` | timestamp(6) | nullable | MySQL-UTC marker committed immediately before the one permitted Redis invocation | operational metadata |
| `publication_attempt_resolved_at` | timestamp(6) | nullable | MySQL-UTC durable success, failure, or unknown classification instant | operational metadata |
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

`dispatch_payload` is one canonical JSON object with exactly seven keys in this
order and no unknown or omitted keys:

| Order | Key | Exact type and value contract |
| --- | --- | --- |
| 1 | `schema_version` | non-null string, exactly `supplier-import-dispatch-payload-v1` |
| 2 | `execution_claim_id` | positive base-10 JSON integer within signed BIGINT |
| 3 | `logical_execution_key` | non-null string, exactly 64 lowercase hexadecimal ASCII characters |
| 4 | `parent_type` | non-null string, exactly `supplier_import_run` or `supplier_feed` |
| 5 | `parent_id` | positive base-10 JSON integer within signed BIGINT and valid for `parent_type` |
| 6 | `transport_deadline_at` | non-null string, UTC `YYYY-MM-DDTHH:MM:SS.ffffffZ`; it is a non-authoritative copy |
| 7 | `force` | literal JSON boolean, always present; `false` when the authorized parent action did not request force |

No payload field is nullable. Recovery never adds an authorization, recovery,
attempt, lease or result field; republication uses the byte-identical original
payload. The database column, not the payload, is the deadline authority. The
payload contains no supplier ID, feed URL, credential, XML, observation, source
identity, source path, raw supplier identifier or arbitrary job data. Consumers
load every other value from the outbox, claim and constrained parent.

Canonical bytes are UTF-8 without BOM or trailing newline. PHP constructs the
array in the order above and uses exactly `json_encode($payload,
JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)`. There
is no pretty printing, insignificant whitespace, locale-dependent formatting,
float, numeric string in place of an integer, alternate escaping policy or key
reordering. Producer and consumer reject duplicate, missing, unknown,
out-of-order or wrong-type fields before comparing the hash.

`dispatch_payload_hash` is lowercase hexadecimal:

```text
SHA-256("mycomputer:supplier-dispatch-payload:v1" || 0x00 || canonical_json_bytes)
```

The exact non-secret test vectors are:

```text
{"schema_version":"supplier-import-dispatch-payload-v1","execution_claim_id":42,"logical_execution_key":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","parent_type":"supplier_import_run","parent_id":17,"transport_deadline_at":"2026-08-20T12:34:56.123456Z","force":false}
d2a1b00c8b6d70393fdd65b246daa6e7e0c3cbba7c4ac1ff13fa38e9e34d59d0

{"schema_version":"supplier-import-dispatch-payload-v1","execution_claim_id":9223372036854775807,"logical_execution_key":"0000000000000000000000000000000000000000000000000000000000000000","parent_type":"supplier_feed","parent_id":1,"transport_deadline_at":"2026-12-31T23:59:59.999999Z","force":true}
471b08a6da920cc82c9612f15fa812546ffa32daf1a8d499eaadecf3d9a2334e
```

The later implementation suite must construct both vectors from typed values,
assert the exact serialized bytes and digest, and prove that changed order,
type, timestamp, enum, unknown key, missing key or newline fails closed.

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
| `pending` | `published` | fast handler adoption or exact authorized recovery after proven publication |
| `pending` | `terminal_failed` | failed eighth initial publication or irreconcilable binding mismatch |
| `leased` | `leased` | owner-checked replacement after lease expiry |
| `leased` | `published` | owner-checked initial-dispatch acknowledgement or handler adoption for a pending-origin lease |
| `leased` | `terminal_failed` | failed or ambiguous eighth publication or irreconcilable binding mismatch |
| `published` | `recovery_required` | owner-proven in-handle pre-processing closeout, transport-only callback, stale-payload watchdog, or expired queued-owner recovery while deadline and delivery budget remain valid |
| `recovery_required` | `published` | exact started-authorization tuple, supplier lock, byte-identical payload and acknowledged manual republication |
| `recovery_required` | `terminal_failed` | separately authorized `terminalize_stale_dispatch` after deadline/delivery/response/publication-attempt exhaustion, or separately authorized publication-mismatch resolution; `republish_same_key` never performs this transition |

No other transition is valid. `terminal_failed` has no outgoing transition.
In particular, `recovery_required -> published` is impossible without the
exact started authorization, Phase-B resume predicate, supplier lock and
acknowledged byte-identical republication. Manual recovery deliberately uses no
second publication lease whose lost raw token could strand the started action.
Lease fields are present only in `leased` for initial publisher work; publication, recovery-required, and
terminal transitions clear them. `published_at` records the first publication
and is write-once; `last_published_at` advances only after another acknowledged
publication of the same event/key. Every acknowledged publication sets
`delivery_watchdog_at = UTC_TIMESTAMP(6) + INTERVAL 4320 SECOND` in the same
transaction that establishes the cross-record `queued/published` pair; only a
later successfully acknowledged same-key recovery publication may refresh it
by the same MySQL-UTC rule. It is
cleared atomically when queued ownership enters `processing`, when the outbox
leaves `published` for `recovery_required` or `terminal_failed`, and
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
CONSTRAINT chk_import_outbox_publication_attempt_state CHECK (
    BINARY publication_attempt_state IN (
        BINARY _ascii'none',
        BINARY _ascii'reserved',
        BINARY _ascii'call_boundary_entered',
        BINARY _ascii'durable_success',
        BINARY _ascii'durable_failure',
        BINARY _ascii'outcome_unknown'
    )
),
CONSTRAINT chk_import_outbox_publication_attempt_tuple CHECK (
    (
        publication_attempt_state = 'none'
        AND publication_attempt_generation = 0
        AND attempt_count = 0
        AND publication_attempt_token_hash IS NULL
        AND publication_attempt_reserved_at IS NULL
        AND publication_attempt_lease_expires_at IS NULL
        AND publication_call_boundary_at IS NULL
        AND publication_attempt_resolved_at IS NULL
    )
    OR (
        publication_attempt_state = 'reserved'
        AND publication_attempt_generation > 0
        AND attempt_count > 0
        AND publication_attempt_token_hash IS NOT NULL
        AND publication_attempt_reserved_at IS NOT NULL
        AND publication_attempt_lease_expires_at > publication_attempt_reserved_at
        AND publication_call_boundary_at IS NULL
        AND publication_attempt_resolved_at IS NULL
    )
    OR (
        publication_attempt_state = 'call_boundary_entered'
        AND publication_attempt_generation > 0
        AND attempt_count > 0
        AND publication_attempt_token_hash IS NOT NULL
        AND publication_attempt_reserved_at IS NOT NULL
        AND publication_attempt_lease_expires_at > publication_attempt_reserved_at
        AND publication_call_boundary_at >= publication_attempt_reserved_at
        AND publication_attempt_resolved_at IS NULL
    )
    OR (
        publication_attempt_state IN ('durable_success', 'durable_failure')
        AND publication_attempt_generation > 0
        AND attempt_count > 0
        AND publication_attempt_token_hash IS NULL
        AND publication_attempt_reserved_at IS NOT NULL
        AND publication_attempt_lease_expires_at > publication_attempt_reserved_at
        AND publication_call_boundary_at >= publication_attempt_reserved_at
        AND publication_attempt_resolved_at >= publication_call_boundary_at
    )
    OR (
        publication_attempt_state = 'outcome_unknown'
        AND publication_attempt_generation > 0
        AND attempt_count > 0
        AND publication_attempt_token_hash IS NULL
        AND publication_attempt_reserved_at IS NOT NULL
        AND publication_attempt_lease_expires_at > publication_attempt_reserved_at
        AND (
            publication_call_boundary_at IS NULL
            OR publication_call_boundary_at >= publication_attempt_reserved_at
        )
        AND publication_attempt_resolved_at >= publication_attempt_reserved_at
    )
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
They include the liveness reasons `dispatch_durable_progress_stalled` and
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
failure caused specifically by publication exhaustion is valid after a failed
or irreconcilably ambiguous eighth initial publication, or after a failed
eighth authorized recovery publication followed by a separately issued exact
`terminalize_stale_dispatch` action. In either terminal transaction,
`terminal_failure_reason_code = 'dispatch_publication_attempts_exhausted'` and
`attempt_count = 8`; `republish_same_key` itself records only `publish_failed`.
Irreconcilable parent/key failures close under the separately authorized
`dispatch_publication_mismatch` action. A successful eighth publication remains
`published` with `attempt_count = 8`, but it does not reset the separate
delivery count or transport deadline.

The publication-attempt tuple is a transactional/application CAS contract in
addition to the same-row checks above. Every physical Redis publication,
initial or recovery, requires one committed reservation first. The reservation
atomically verifies the exact current claim/outbox/action tuple and allowed
budget, requires `attempt_count = N` with `N < 8`, increments
`attempt_count = N + 1` and `publication_attempt_generation` by one, writes a
fresh random token hash, sets `publication_attempt_state = reserved`, and sets
the reservation/lease timestamps while clearing the call/resolution timestamps.
The transaction commits before Redis can be invoked. Initial publishing also
binds its existing outbox lease; recovery publishing binds the exact started
authorization. Neither path increments `attempt_count` anywhere else.

Only the exact unexpired generation/token may CAS `reserved ->
call_boundary_entered`; that transaction commits
`publication_call_boundary_at` immediately before the one permitted Redis
primitive. There is no asynchronous yield or reconstructable permit between
the successful CAS and invocation, and the holder must refuse the call when its
lease deadline has passed. A generation may enter this boundary once and may
authorize at most one physical call. Success/failure acknowledgement binds the
same generation/token and changes the tuple to `durable_success` or
`durable_failure`, clears the token, and writes the resolution timestamp. A
stale generation affects zero rows and cannot call, acknowledge, fail, alter
counters, or overwrite a successor.

A reserved or call-boundary generation left unresolved after verified owner
loss and lease expiry consumes its attempt permanently. One exact CAS binds the
generation, token hash, reserved timestamp, optional call-boundary timestamp,
expiry and null resolution, then writes `outcome_unknown`, clears the token and
sets the resolution timestamp without calling Redis. It never decrements or
reuses the ordinal. When budget remains and every action boundary is still
valid, the same started logical `republish_same_key` action may reserve only the
next ordinal under a new generation and the unchanged logical execution key.
At attempt eight, `outcome_unknown` permits no further reservation and closes
the republish result as
`publish_failed/dispatch_publication_attempts_exhausted`; any terminal domain
mutation still requires a new exact `terminalize_stale_dispatch`
authorization. Thus one physical Redis call always has one durable reservation,
one reservation never permits multiple calls, and publication attempt nine is
impossible.

| Reason code | Exact state/result boundary |
| --- | --- |
| `dispatch_durable_progress_stalled` | recoverable `queued/recovery_required` after the due watchdog proves only that no durable owner or processing progress exists; it makes no claim about whether Redis delivered the payload or `handle()` observed it |
| `queued_ownership_lease_expired` | recoverable `queued/recovery_required` after complete expired pre-processing ownership is cleared |
| `transport_delivery_budget_exhausted` | irrecoverable pre-processing `terminal_failed/terminal_failed` when delivery eight is consumed |
| `transport_deadline_expired` | irrecoverable pre-processing `terminal_failed/terminal_failed` at or after the immutable deadline |
| `dispatch_publication_attempts_exhausted` | `terminal_failed/terminal_failed` after failed or irreconcilably ambiguous initial publication attempt eight, or through separately authorized `terminalize_stale_dispatch` after an authorized recovery attempt eight records `publish_failed`; never a terminal result of `republish_same_key` |
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
| stale delivery with `recovery_required` | preserve queued claim/parents and outbox; the stale payload exits before ownership, allocation, download, importer or evidence | `SupplierImportExecutionCoordinator`; only an acknowledged authorized manual `recovery_required -> published` cycle can make a later payload eligible |
| recoverable pre-processing failure | claim remains `queued`; its `published` outbox becomes `recovery_required` only while the deadline is future and `delivery_attempt_count <= 6`; parent state is preserved | `SupplierImportTransportFailureService`; no terminal claim/parent/evidence write and a pending-dispatch/recovery-required pairing is rejected |
| acknowledged dispatch without durable progress | only `queued/published` with due `delivery_watchdog_at`, null ownership, canonical parents, and no evidence becomes `queued/recovery_required` with `dispatch_durable_progress_stalled` under `republish_same_key`; exhausted boundaries remain unchanged until a separate `terminalize_stale_dispatch` authorization atomically writes the exact terminal pair; the state does not assert whether delivery or handler admission occurred | `ReconcileSupplierImportDispatchOutbox` through the fixed-order invariant/terminal repositories; bounded indexed selection, supplier-lock revalidation and exact action/result compatibility prevent a Redis-delivery assumption or cross-action terminalization |
| expired queued ownership | only `recover_expired_queued_ownership` may clear a complete expired `queued/published` ownership tuple while transport/response boundaries remain open, producing `queued/recovery_required` with `queued_ownership_lease_expired`; exhausted boundaries require a separate `terminalize_stale_dispatch` authorization that binds and clears the same complete tuple while terminalizing | `ExpiredQueuedImportTerminalRepository::resolveExpiredQueuedOwnership()`; complete-tuple CAS, new supplier lock, exact race rejection, action-compatible result and zero importer/evidence work are required |
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
| `logical_execution_key` | `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin` | not null | Durable key copied from and constrained to the exact claim/outbox tuple | internal |
| `target_parent_type` | `VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin` | not null | Exactly `supplier_import_run` or `supplier_feed` from the claim | internal |
| `target_parent_id` | unsigned bigint | not null | Exact run/feed target selected by `target_parent_type` | internal |
| `authorization_action` | `VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin` | not null | One value from the five-action allowlist below | public contract |
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
        BINARY _ascii'recover_expired_queued_ownership',
        BINARY _ascii'terminalize_stale_dispatch',
        BINARY _ascii'terminalize_publication_mismatch',
        BINARY _ascii'terminalize_abandoned_processing'
    )
),
CONSTRAINT chk_import_recovery_auth_logical_key CHECK (
    OCTET_LENGTH(logical_execution_key) = 64
    AND REGEXP_LIKE(logical_execution_key, _ascii'^[0-9a-f]{64}$', 'c')
),
CONSTRAINT chk_import_recovery_auth_parent_type CHECK (
    BINARY target_parent_type IN (
        BINARY _ascii'supplier_import_run',
        BINARY _ascii'supplier_feed'
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

The only expected-state fingerprint contract authorized for future
implementation is `expected_state_fingerprint_v2`. The historical unversioned
and `v1` wording is superseded context and is not implementable. The domain
separator is exactly the 46 UTF-8/ASCII bytes of
`mycomputer:supplier-recovery-expected-state:v2`, followed by one NUL byte
(`0x00`). The digest is:

```text
SHA-256(
  "mycomputer:supplier-recovery-expected-state:v2" || 0x00 ||
  canonical_json_bytes
)
```

The canonical object contains exactly these 20 keys in this exact order;
`claimed_at` is security-relevant owner identity and may not be removed:

```text
schema, authorization_action, execution_claim_id, dispatch_outbox_id,
logical_execution_key, execution_path, claim_state, outbox_state, supplier_id,
supplier_import_run_id, supplier_feed_id, import_job_id, import_history_id,
publication_attempt_count, delivery_attempt_count, transport_deadline_at,
delivery_watchdog_at, active_attempt_token_hash, claimed_at,
attempt_lease_expires_at
```

| Position | Key | Exact JSON type and value contract | Nullable |
| --- | --- | --- | --- |
| 1 | `schema` | string; exactly `expected_state_fingerprint_v2` | no |
| 2 | `authorization_action` | string; one exact value from the five-action authorization allowlist | no |
| 3 | `execution_claim_id` | positive base-10 JSON integer within signed BIGINT | no |
| 4 | `dispatch_outbox_id` | positive base-10 JSON integer within signed BIGINT | no |
| 5 | `logical_execution_key` | string; exactly 64 lowercase hexadecimal characters | no |
| 6 | `execution_path` | string; exactly `orchestrated` or `legacy_xml` | no |
| 7 | `claim_state` | string; one exact canonical execution-claim state | no |
| 8 | `outbox_state` | string; one exact canonical dispatch-outbox state | no |
| 9 | `supplier_id` | positive base-10 JSON integer within signed BIGINT | no |
| 10 | `supplier_import_run_id` | positive base-10 JSON integer within signed BIGINT, or JSON `null` where the path contract permits no run | yes |
| 11 | `supplier_feed_id` | positive base-10 JSON integer within signed BIGINT, or JSON `null` before valid orchestrated allocation | yes |
| 12 | `import_job_id` | positive base-10 JSON integer within signed BIGINT, or JSON `null` before valid orchestrated allocation | yes |
| 13 | `import_history_id` | positive base-10 JSON integer within signed BIGINT, or JSON `null` before history allocation | yes |
| 14 | `publication_attempt_count` | unsigned base-10 JSON integer in the canonical `0..8` range | no |
| 15 | `delivery_attempt_count` | unsigned base-10 JSON integer in the canonical `0..8` range | no |
| 16 | `transport_deadline_at` | string; MySQL UTC `YYYY-MM-DDTHH:MM:SS.ffffffZ` | no |
| 17 | `delivery_watchdog_at` | the same UTC timestamp string, or JSON `null` where the state contract permits no watchdog | yes |
| 18 | `active_attempt_token_hash` | string of exactly 64 lowercase hexadecimal characters, or JSON `null` | yes |
| 19 | `claimed_at` | the same UTC timestamp string, or JSON `null`; it is one of the 20 fingerprint fields | yes |
| 20 | `attempt_lease_expires_at` | the same UTC timestamp string, or JSON `null` | yes |

The state machine still enforces every cross-field path, allocation, watchdog
and complete-owner-tuple invariant before hashing. Nullable does not authorize
an otherwise invalid partial tuple. All 20 keys are mandatory even when their
value is `null`; a missing, duplicate, unknown, out-of-order or wrong-type key
is rejected before comparison.

Canonical JSON is UTF-8 without BOM, Unicode normalization, insignificant
whitespace, line endings or trailing newline. Object keys use only the order
above. Strings are emitted without semantic normalization; JSON quotation mark,
reverse solidus and control bytes use the required JSON escapes, while solidus
and Unicode characters are not additionally escaped. Null is the four ASCII
bytes `null`. Integers use ASCII decimal digits with no sign, leading zero,
decimal point, exponent or locale formatting. Booleans and floats are invalid.
Future PHP constructs one insertion-ordered typed array and encodes it with
exactly `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE |
JSON_THROW_ON_ERROR`. The raw canonical JSON buffer exists only in bounded
memory and is never printed, logged, included in dry-run output or persisted.
The `logical_execution_key` itself remains durably persisted in the claim,
outbox and authorization/result binding exactly as required by their schema and
foreign keys; it is never printed or logged and is not a capability.

This synthetic vector is normative. The displayed JSON is exactly 791 bytes and
has no trailing newline:

```text
{"schema":"expected_state_fingerprint_v2","authorization_action":"recover_expired_queued_ownership","execution_claim_id":42,"dispatch_outbox_id":77,"logical_execution_key":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","execution_path":"orchestrated","claim_state":"queued","outbox_state":"published","supplier_id":9,"supplier_import_run_id":501,"supplier_feed_id":12,"import_job_id":601,"import_history_id":701,"publication_attempt_count":2,"delivery_attempt_count":3,"transport_deadline_at":"2026-08-20T12:00:00.000000Z","delivery_watchdog_at":"2026-08-20T11:00:00.000000Z","active_attempt_token_hash":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","claimed_at":"2026-08-20T10:00:00.000000Z","attempt_lease_expires_at":"2026-08-20T11:10:00.000000Z"}
31d1cf23a2fceac08d71c0103b3093af392f916921ef2221d860a7ecf9f7a62c
```

The five actions above are the complete mutating-recovery inventory. Every
documented `--apply` path must consume one of them; no command has a prose-only,
ID-only or `--apply`-only authorization exception. Unknown actions fail before
authorization insertion.

The future issuer is invoked only by an authenticated Filament action on the
exact recovery-candidate detail screen. The action is visible only to an
authenticated, active `super_admin`, displays the non-secret IDs, canonical
states, reason and proposed action for confirmation, and calls exactly:

```php
SupplierImportDispatchRecoveryAuthorizationIssuer::issue(
    User $actor,
    int $executionClaimId,
    int $dispatchOutboxId,
    string $authorizationAction,
    string $canonicalReasonCode,
): IssuedSupplierImportDispatchRecoveryAuthorization
```

The server, never the browser or operator, derives the complete claim/outbox/key/
parent target tuple and computes `expected_state_fingerprint` under the supplier
lock and canonical row locks. The caller cannot submit or override a fingerprint,
operator ID, logical key or parent ID. Issuance requires the exact action-specific
state, no conflicting started authorization, and the continuously evaluated
healthy-monitor admission gate below. It rejects active processing except when
the selected action is the exact expired-owner
`terminalize_abandoned_processing`, evidence, noncanonical parents, stale
monitor health or an action/reason mismatch. It uses MySQL UTC and inserts one
immutable authorization expiring exactly 900 seconds later. It generates
exactly 32 cryptographically secure random nonce bytes and returns them once as
unpadded base64url. The raw bytes are never persisted. The persisted nonce hash
is lowercase hexadecimal:

```text
SHA-256("supplier-import-dispatch-recovery-nonce-v1" || 0x00 || raw_nonce_bytes)
```

The issuer result object exposes the authorization ID and one-time nonce only;
it is not serializable, queueable, loggable or persistable. Issuance is allowed
only for its action-specific canonical predicate:

| Action | Exact issue-time predicate |
| --- | --- |
| `republish_same_key` | one canonical `pending_dispatch/pending`, expired `pending_dispatch/leased`, `queued/recovery_required` (including the completed `queued_ownership_lease_expired` release), or due `queued/published` all-null-progress pair; no evidence, future deadline, remaining publication/delivery budget, open response window where watchdog-governed, and no pending or started competing authorization |
| `recover_expired_queued_ownership` | exact `queued/published` claim/outbox pair; complete ownership tuple (`active_attempt_token_hash`, `claimed_at`, `attempt_lease_expires_at` all non-null and hash-valid); `attempt_lease_expires_at < UTC_TIMESTAMP(6)`; due watchdog; no evidence; canonical parents; future deadline; remaining publication/delivery budget; open response window; and no pending or started competing authorization |
| `terminalize_stale_dispatch` | due `queued/published` or canonical `queued/recovery_required`, including a complete expired owner, with no live owner/evidence and an exact current terminal reason because deadline, delivery budget, response window or publication-attempt budget is exhausted; no pending or started competing authorization |
| `terminalize_publication_mismatch` | one exact eligible pre-processing mismatch tuple accepted by `PublicationMismatchTerminalRepository` |
| `terminalize_abandoned_processing` | exact expired complete `processing/published` owner tuple accepted by `AbandonedSupplierImportTerminalRepository` |

Monitor/sink/observer health is deliberately excluded from
`expected_state_fingerprint`: including changing heartbeat timestamps would
make an otherwise valid authorization unexecutable. The issuer checks the
derived health gate transactionally while holding the target locks, and Phase A
checks it again in the mutation transaction. `republish_same_key` additionally
checks the same gate immediately before each Phase-B external call. A failed
health check changes no target field and cannot be replaced by a fingerprint
comparison.

The issuer and every start transaction use the same supplier lock and
`outbox -> claim -> applicable parents` row-lock order to enforce one live
authorization owner per claim/outbox target. Issuance is rejected while either
(a) an unexpired authorization for the target has no terminal result, or (b) an
authorization has `started` and has no terminal result, even if its 900-second
issuance window has expired. An expired never-started authorization does not
block a new issuance and can only record `rejected` if its nonce is later
presented. A started authorization ceases to block the target only when its
action-compatible sequence-2 terminal result commits. Thus a pre-issued or
newly requested competing authorization cannot start between Phase A and the
original action's terminal result.

**Complete expired queued-owner predicate.** The canonical state is exactly
`claim.state = queued`, `outbox.state = published`, matching claim/outbox/key and
parent bindings, non-null due `delivery_watchdog_at`, no generation or other
evidence, and the complete persisted owner tuple:
`active_attempt_token_hash` is a 64-character lowercase hexadecimal digest,
`claimed_at IS NOT NULL`, `attempt_lease_expires_at IS NOT NULL`,
`claimed_at < attempt_lease_expires_at`, and
`attempt_lease_expires_at < UTC_TIMESTAMP(6)`. The token hash is both the opaque
owner identity and the ownership-generation discriminator; this schema has no
separate guessed owner ID or generation. Open transport/response boundaries
select `recover_expired_queued_ownership`; an exhausted deadline, delivery,
response or publication budget selects `terminalize_stale_dispatch`. Unknown
external observation is not converted into evidence.

The legal first mutation for the open-boundary case is the
`recover_expired_queued_ownership` Phase-A transaction itself. There is no
unauthenticated clear-first step. Its compare-and-set binds every owner field
captured by the server-computed fingerprint and is equivalent to:

```sql
UPDATE supplier_import_execution_claims
SET active_attempt_token_hash = NULL,
    claimed_at = NULL,
    attempt_lease_expires_at = NULL
WHERE id = :execution_claim_id
  AND state = 'queued'
  AND active_attempt_token_hash = :authorized_owner_token_hash
  AND claimed_at = :authorized_claimed_at
  AND attempt_lease_expires_at = :authorized_lease_expires_at
  AND attempt_lease_expires_at < UTC_TIMESTAMP(6)
```

The same transaction has already locked and revalidated the exact outbox and
parents, requires exactly one affected claim row, changes the outbox to
`recovery_required`, clears its watchdog, writes
`queued_ownership_lease_expired`, and appends `started` plus
`ownership_recovery_succeeded`. Zero affected rows rolls back every write. A
new worker that acquires ownership first necessarily changes at least the token
hash and timestamps, so a stale authorization cannot clear that successor.
Crash before or during commit leaves the old complete tuple; crash after commit
leaves the cleared recovery tuple and immutable terminal result; duplicate or
replay returns that result without another mutation.

The read-only monitor cannot invoke the issuer. It exposes opaque candidate IDs
and human-readable state only; it never exposes or accepts the fingerprint.

Recovery commands require non-secret `--authorization-id` and the boolean
`--nonce-stdin`. They read exactly one unpadded base64url value from standard
input, disable terminal echo where supported, require exactly 32 decoded bytes,
derive the domain-separated hash above, compare it in constant time, and clear
or release the raw bytes as soon as practical. The nonce is forbidden in an
argument value, environment variable, config file, URL, log, database
plaintext, queue payload, shell history, process title, or dry-run/apply output.
The command never echoes it. Non-interactive execution without a separately
approved secure standard-input source fails closed.

Laravel CLI has no authenticated current user. The immutable
`authorized_operator_id` from the authorization is the human principal and the
command accepts no `--operator-id`; it verifies that this user was an active
Super Admin at issue time and remains active before the first `started` commit.
The host CLI process is the execution principal, authorized only by possession
of the exact nonce through protected stdin plus trusted-host access; its OS audit
remains separate and is never misrepresented as the Laravel user. A host process
without the nonce cannot mutate state. After `started`, the immutable
authorization/result tuple remains the authority even if a later account-state
change occurs, so an already-started operation can be driven only to its exact
terminal result instead of being stranded.

Before the first start the command rederives action, reason, complete tuple,
pre-start fingerprint, authorization expiry and monitor-health admission under
locks. A bad nonce fails without an audit write. After valid nonce proof, expiry,
changed state, unhealthy monitor, noncanonical parent or conflicting use is
recorded as the allowlisted immutable `rejected` event with zero claim, outbox,
parent or evidence mutation. The first valid proof consumes the authorization
semantically by creating at most one `started` event. Re-presenting the same
nonce can only return the existing terminal event or resume the exact same
started authorization under the post-start predicate below; it can never create
another start, change action/target or authorize another mutation.

Consumption never updates the authorization. The append-only
`supplier_import_dispatch_recovery_results` table records ordered lifecycle
events:

| Column | Type | Null/default | Purpose and invariant | Privacy |
| --- | --- | --- | --- | --- |
| `id` | unsigned bigint | not null | Surrogate immutable event key | internal |
| `supplier_import_dispatch_recovery_authorization_id` | unsigned bigint | not null | Exact authorization | internal |
| `authorization_action` | `VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin` | not null | Action copied from and constrained to the authorization | public contract |
| `authorized_operator_id` | unsigned bigint | not null | Human Super Admin copied from and constrained to the authorization | internal |
| `supplier_import_execution_claim_id` | unsigned bigint | not null | Claim copied from and constrained to the authorization | internal |
| `supplier_import_dispatch_outbox_id` | unsigned bigint | not null | Outbox copied from and constrained to the authorization | internal |
| `logical_execution_key` | `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin` | not null | Key copied from and constrained to the authorization | internal |
| `target_parent_type` | `VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin` | not null | Parent type copied from and constrained to the authorization | internal |
| `target_parent_id` | unsigned bigint | not null | Parent ID copied from and constrained to the authorization | internal |
| `event_sequence` | unsigned smallint | not null | Monotonic sequence, starting at 1 | public contract |
| `event_kind` | `VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin` | not null | Exact lifecycle event allowlist below; checked against action and result code | public contract |
| `canonical_result_code` | `VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin` | not null | Allowlisted result or rejection code, never exception text | public contract |
| `resume_state_fingerprint` | `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin` | nullable | Exact post-start state digest; non-null only for `republish_same_key` `started` | pseudonymous |
| `occurred_at` | timestamp(6) | not null | MySQL UTC event instant | operational metadata |
| `result_fingerprint` | `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin` | not null | Exact immutable event fingerprint | pseudonymous |
| `started_once_guard` | generated nullable tinyint | generated | `1` only for `started`, otherwise null | internal |
| `terminal_once_guard` | generated nullable tinyint | generated | `1` only for a terminal event, otherwise null | internal |

The generated guards use binary comparisons. Exact same-row checks require a
positive sequence, one of the eight event kinds and a lowercase hexadecimal
result fingerprint:

```sql
started_once_guard TINYINT UNSIGNED GENERATED ALWAYS AS (
    CASE WHEN BINARY event_kind = BINARY _ascii'started' THEN 1 ELSE NULL END
) STORED,
terminal_once_guard TINYINT UNSIGNED GENERATED ALWAYS AS (
    CASE WHEN BINARY event_kind IN (
        BINARY _ascii'republish_succeeded',
        BINARY _ascii'terminalization_succeeded',
        BINARY _ascii'ownership_recovery_succeeded',
        BINARY _ascii'publish_failed',
        BINARY _ascii'action_stopped',
        BINARY _ascii'rejected',
        BINARY _ascii'already_terminal'
    ) THEN 1 ELSE NULL END
) STORED,
CONSTRAINT chk_import_recovery_result_event CHECK (
    BINARY event_kind IN (
        BINARY _ascii'started',
        BINARY _ascii'republish_succeeded',
        BINARY _ascii'terminalization_succeeded',
        BINARY _ascii'ownership_recovery_succeeded',
        BINARY _ascii'publish_failed',
        BINARY _ascii'action_stopped',
        BINARY _ascii'rejected',
        BINARY _ascii'already_terminal'
    )
),
CONSTRAINT chk_import_recovery_result_sequence CHECK (
    (BINARY event_kind IN (
        BINARY _ascii'started',
        BINARY _ascii'rejected',
        BINARY _ascii'already_terminal'
    ) AND event_sequence = 1)
    OR
    (BINARY event_kind IN (
        BINARY _ascii'republish_succeeded',
        BINARY _ascii'terminalization_succeeded',
        BINARY _ascii'ownership_recovery_succeeded',
        BINARY _ascii'publish_failed',
        BINARY _ascii'action_stopped'
    ) AND event_sequence = 2)
),
CONSTRAINT chk_import_recovery_result_fingerprint CHECK (
    OCTET_LENGTH(result_fingerprint) = 64
    AND REGEXP_LIKE(result_fingerprint, _ascii'^[0-9a-f]{64}$', 'c')
),
CONSTRAINT chk_import_recovery_result_resume_fingerprint CHECK (
    (
        BINARY event_kind = BINARY _ascii'started'
        AND BINARY authorization_action = BINARY _ascii'republish_same_key'
        AND OCTET_LENGTH(resume_state_fingerprint) = 64
        AND REGEXP_LIKE(resume_state_fingerprint, _ascii'^[0-9a-f]{64}$', 'c')
    ) OR (
        NOT (
            BINARY event_kind = BINARY _ascii'started'
            AND BINARY authorization_action = BINARY _ascii'republish_same_key'
        )
        AND resume_state_fingerprint IS NULL
    )
)
```

The same future MySQL 8.4 migration also adds the exact named same-row
compatibility check below. It is intentionally exhaustive: an authorization
cannot acquire a different action merely because a deadline, response window,
monitor gate or publication attempt changes after `started`.

```sql
CONSTRAINT chk_import_recovery_result_action_event_code CHECK (
    (BINARY event_kind = BINARY _ascii'started'
        AND BINARY canonical_result_code = BINARY _ascii'authorization_attempt_started')
    OR
    (BINARY event_kind = BINARY _ascii'rejected'
        AND BINARY canonical_result_code IN (
            BINARY _ascii'authorization_expired',
            BINARY _ascii'state_fingerprint_mismatch',
            BINARY _ascii'resume_state_fingerprint_mismatch',
            BINARY _ascii'state_conflict',
            BINARY _ascii'noncanonical_parent',
            BINARY _ascii'action_not_permitted',
            BINARY _ascii'response_window_expired',
            BINARY _ascii'monitor_integrity_not_healthy'
        ))
    OR
    (BINARY event_kind = BINARY _ascii'already_terminal'
        AND BINARY canonical_result_code = BINARY _ascii'already_terminal_noop')
    OR
    (BINARY authorization_action = BINARY _ascii'republish_same_key'
        AND (
            (BINARY event_kind = BINARY _ascii'republish_succeeded'
                AND BINARY canonical_result_code = BINARY _ascii'dispatch_republished_same_key')
            OR
            (BINARY event_kind = BINARY _ascii'publish_failed'
                AND BINARY canonical_result_code IN (
                    BINARY _ascii'dispatch_publication_failed',
                    BINARY _ascii'dispatch_publication_attempts_exhausted'
                ))
            OR
            (BINARY event_kind = BINARY _ascii'action_stopped'
                AND BINARY canonical_result_code IN (
                    BINARY _ascii'republish_delivery_budget_exhausted_after_start',
                    BINARY _ascii'republish_transport_deadline_expired_after_start',
                    BINARY _ascii'republish_response_window_expired_after_start',
                    BINARY _ascii'monitor_integrity_not_healthy_after_start',
                    BINARY _ascii'republish_state_conflict_after_start'
                ))
        ))
    OR
    (BINARY authorization_action = BINARY _ascii'recover_expired_queued_ownership'
        AND BINARY event_kind = BINARY _ascii'ownership_recovery_succeeded'
        AND BINARY canonical_result_code = BINARY _ascii'queued_ownership_lease_expired')
    OR
    (BINARY authorization_action = BINARY _ascii'terminalize_stale_dispatch'
        AND BINARY event_kind = BINARY _ascii'terminalization_succeeded'
        AND BINARY canonical_result_code IN (
            BINARY _ascii'transport_delivery_budget_exhausted',
            BINARY _ascii'transport_deadline_expired',
            BINARY _ascii'dispatch_watchdog_operator_terminalized',
            BINARY _ascii'dispatch_watchdog_response_expired',
            BINARY _ascii'dispatch_publication_attempts_exhausted'
        ))
    OR
    (BINARY authorization_action = BINARY _ascii'terminalize_publication_mismatch'
        AND BINARY event_kind = BINARY _ascii'terminalization_succeeded'
        AND BINARY canonical_result_code = BINARY _ascii'dispatch_publication_mismatch')
    OR
    (BINARY authorization_action = BINARY _ascii'terminalize_abandoned_processing'
        AND BINARY event_kind = BINARY _ascii'terminalization_succeeded'
        AND BINARY canonical_result_code = BINARY _ascii'processing_lease_abandoned')
)
```

The authorization table has one named unique key over exactly
`(id, authorization_action, authorized_operator_id,
supplier_import_execution_claim_id, supplier_import_dispatch_outbox_id,
logical_execution_key, target_parent_type, target_parent_id)`. Every result row
copies those eight columns and one named composite `RESTRICT` foreign key
references that unique key. Separate supporting indexes may remain, but they are
not accepted as tuple proof. A mismatched operator, action, claim, outbox, key or
parent is therefore structurally rejected before an immutable result can exist.

The event allowlist is exactly `started`, `republish_succeeded`,
`terminalization_succeeded`, `ownership_recovery_succeeded`, `publish_failed`,
`action_stopped`, `rejected`, and `already_terminal`. Unique
(`authorization_id`, `event_sequence`), unique
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
| `terminalization_succeeded` | action-specific subset enforced by `chk_import_recovery_result_action_event_code`: stale dispatch uses `transport_delivery_budget_exhausted`, `transport_deadline_expired`, `dispatch_watchdog_operator_terminalized`, `dispatch_watchdog_response_expired` or `dispatch_publication_attempts_exhausted`; mismatch uses only `dispatch_publication_mismatch`; abandoned processing uses only `processing_lease_abandoned` |
| `ownership_recovery_succeeded` | `queued_ownership_lease_expired` only for `recover_expired_queued_ownership` |
| `publish_failed` | `dispatch_publication_failed`, `dispatch_publication_attempts_exhausted` only for `republish_same_key` |
| `action_stopped` | `republish_delivery_budget_exhausted_after_start`, `republish_transport_deadline_expired_after_start`, `republish_response_window_expired_after_start`, `monitor_integrity_not_healthy_after_start`, `republish_state_conflict_after_start` only for `republish_same_key` |
| `rejected` | `authorization_expired`, `state_fingerprint_mismatch`, `resume_state_fingerprint_mismatch`, `state_conflict`, `noncanonical_parent`, `action_not_permitted`, `response_window_expired`, `monitor_integrity_not_healthy` |
| `already_terminal` | `already_terminal_noop` |

Any other code, and any raw exception text, fails before INSERT.

Validation has exactly two phases.

**Phase A, first start.** Under the supplier lock and canonical row locks, the
command proves nonce, unexpired authorization, still-active authorized Super
Admin, healthy monitor admission, complete composite target tuple, no result
row, and exact pre-start `expected_state_fingerprint`. For
`republish_same_key`, one transaction normalizes exactly one authorized source
state: `pending` remains `pending`, `recovery_required` remains
`recovery_required`, and only a due stale `published` row changes to
`recovery_required`, writes `dispatch_durable_progress_stalled` and clears the
watchdog. It preserves all target, parent, deadline and counter fields and
inserts sequence-1 `started` with the post-commit
`resume_state_fingerprint`. The fingerprint is calculated from the values being
committed in that transaction. A commit exposes all of them or none of them.

**Phase B0, committed-start resume validation.** A retry no longer compares
current state with the pre-start fingerprint. Before the first physical
publication reservation, it requires the same authorization row, exact
composite result/authorization tuple, exactly one sequence-1 `started`, no
terminal result, the action `republish_same_key`, its exact action-specific
`pending_dispatch/pending` or `queued/recovery_required` pair, null ownership
and watchdog where required, the preserved reason/parent/deadline/counters, and
a recomputed digest equal to the immutable `resume_state_fingerprint`. This is
the only canonical validation of the post-Phase-A baseline. Any field mismatch
cannot fall back to Phase A or another authorization. A canonical changed-state
boundary commits only the action-compatible
`action_stopped/republish_state_conflict_after_start` event; a malformed tuple
that cannot satisfy that exact stop predicate records no result and fails closed
for investigation.

**Phase B1, physical-attempt reservation.** The first B1 reservation occurs in
the same transaction as successful B0 validation. It atomically requires the
exact target/action tuple, no terminal result, valid monitor/deadline/response/
publication/delivery boundaries and `attempt_count = N < 8`; then it increments
the count and `publication_attempt_generation`, writes the fresh token hash,
and commits the `reserved` tuple described above. Redis is forbidden before
that commit. For a later attempt after an expired unresolved generation was
durably classified `outcome_unknown`, B1 does not pretend that the original
resume fingerprint is unchanged: it binds the immutable authorization/started
tuple, exact current claim/outbox/key/parent/reason/deadline/delivery fields and
the current resolved publication-attempt generation/state/count, then reserves
only `N + 1`. No additional attempt fingerprint or digest is introduced.

The resume fingerprint domain is exactly
`supplier-import-dispatch-recovery-resume-v1`. Its canonical object keys are,
in order:

```text
schema, authorization_id, authorization_action, authorized_operator_id,
execution_claim_id, dispatch_outbox_id, logical_execution_key,
target_parent_type, target_parent_id, claim_state, outbox_state,
recovery_reason_code, publication_attempt_count, delivery_attempt_count,
transport_deadline_at, delivery_watchdog_at
```

It uses the same byte-level JSON, string, timestamp, integer and null rules as
`expected_state_fingerprint_v2`, but it is a separate 16-field contract with a
different purpose, inventory and domain. `schema` is exactly
`supplier-import-dispatch-recovery-resume-v1`; the digest is
`SHA-256(domain_separator || 0x00 || canonical_json_bytes)`.

Immediately before every Redis call, the exact unexpired B1 generation/token
must commit `reserved -> call_boundary_entered` while transactionally
revalidating the monitor gate, publication and delivery budgets, immutable
deadline and response window. If any boundary is no longer valid, it performs
no Redis call, resolves the reservation without reusing its consumed ordinal,
and commits the corresponding sequence-2 `action_stopped` event. That event
preserves `queued/recovery_required`, parents, counters and evidence, and closes
only the `republish_same_key` authorization. It never writes a terminal claim/
outbox/parent result. Once that stop event commits, a new authorization is
legally issuable: another `republish_same_key` only if all republication
boundaries and attempt budget remain valid, otherwise the exact
`terminalize_stale_dispatch` action. There is no interval in which the old
authorization cannot continue, no compatible stop can commit, and a new action
cannot later be issued.

The boundary decision is the MySQL-UTC check committed immediately before the
external call. If that check passes and Redis accepts before time later crosses
a boundary, recording `republish_succeeded` remains compatible because it
acknowledges only the already-authorized republication. If Redis acceptance is
unknown after a crash, the expired exact attempt tuple is first classified
`outcome_unknown` without a Redis call. When a later resume finds a closed
boundary, Phase B does not reserve or publish again: it commits
`action_stopped`, leaves `queued/recovery_required`, and any accepted stale
payload no-ops against that state. When every boundary remains open and the
counter is below eight, only a fresh B1 reservation for the next ordinal may
permit another idempotent same-key call. A different terminal action always
requires a newly issued authorization.

`republish_succeeded` is inserted only after Redis acknowledges the byte-exact
same payload; that event, `publication_attempt_state = durable_success`, the
exact `pending|recovery_required -> published` outbox transition, required
`pending_dispatch -> queued` claim transition and new watchdog commit together
under the exact attempt generation/token. The authorized manual path holds the
supplier Redis lock and uses the dedicated publication-attempt reservation;
the immutable started tuple alone is not a physical-call reservation. Redis
acceptance followed by a database-acknowledgement crash is deliberately first
classified `outcome_unknown`; only a newly reserved next ordinal may publish
the same key/payload again under Phase B while every boundary remains valid. No
actor guesses whether the first external effect occurred. Claim uniqueness and
delivery admission make accepted copies converge on the same execution.
`publish_failed` commits only with authoritative preserved
`queued/recovery_required` state and a sequence-2 result. Even a failed eighth
publication cannot terminalize under `republish_same_key`; it records
`dispatch_publication_attempts_exhausted`, closes that authorization, and makes
an exact `terminalize_stale_dispatch` authorization the only legal terminal
next action.

The other four actions are database-only. Their first-start transaction inserts
`started`, performs exactly their authorized mutation, and inserts the
action-compatible sequence-2 result atomically. The three terminalization
actions write `terminalization_succeeded`; `recover_expired_queued_ownership`
writes `ownership_recovery_succeeded`. They cannot expose an incomplete started
state. `rejected` records a nonce-proven Phase-A rejection with no domain
mutation. `already_terminal` records the sequence-1 no-op when another path
established the exact expected terminal result before this authorization began.

Crash and retry behavior is exact:

- immediately before or during the Phase-A transaction, rollback leaves no
  event or domain mutation and the same unexpired authorization may retry;
- immediately after committed `republish_same_key` start and before any
  reservation, the same authorization resumes only through Phase B0;
- a crash after reservation consumes that ordinal; after verified owner loss
  and lease expiry, exact generation/token CAS classifies it `outcome_unknown`;
- after Redis acceptance but before completion evidence, Phase B never reuses
  that generation; only a fresh next-ordinal reservation may publish the same
  canonical bytes idempotently while all boundaries remain open;
- a competing authorization cannot pass either the changed pre-state or the
  exact started tuple and affects zero rows;
- authorization expiry prevents a new start; a committed Phase-A start either
  completes exact B0/B1 handling while every action boundary is valid or commits
  its exact no-terminalization `action_stopped` result;
- a database-only action commits start, mutation and terminal event together or
  rolls all of them back;
- a same-authorization retry with a terminal result returns that event without
  another event or mutation; and
- an exact terminal state won before start records `already_terminal`, while a
  conflicting state records `rejected` only after valid nonce proof.

The complete action-specific boundary contract is normative:

| Canonical action | Issue predicate and expected-state fingerprint | Phase-A CAS and committed `started` state | Phase-B continuation | Allowed external effect | Permitted sequence-2 event/result | Boundary or timeout behavior | Retry and competing authorization behavior |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `republish_same_key` | exact issue-time row above; fingerprint includes the complete target, counters, timestamps and all-null owner tuple | normalize only the authorized pending/leased, `recovery_required`, or due null-owner published source to the exact pending or `queued/recovery_required` resume tuple; preserve parents/key/deadline/budgets; insert `started` plus resume fingerprint atomically | B0 validates the unchanged baseline once; B1 durably increments the physical-attempt count and generation, writes one token-bound reservation, and commits before Redis; only that exact unexpired reservation may enter the one-call boundary | publish the original canonical key/payload bytes to Redis once per committed reservation; no other mutation or external call | `republish_succeeded/dispatch_republished_same_key`, `publish_failed/dispatch_publication_failed|dispatch_publication_attempts_exhausted`, or one allowlisted `action_stopped` code | before-call boundary failure performs no external effect and records `action_stopped`; unresolved expired reservation becomes `outcome_unknown`; next call needs the next ordinal; final unknown exhausts only republish authority; never terminalizes claim/outbox/parents | pre-start retry repeats Phase A; post-start retry uses B0 only before first reservation and then the exact attempt generation/state; stale generation loses CAS; terminal result is returned idempotently; all competing issuance/start remains blocked until sequence 2 commits |
| `recover_expired_queued_ownership` | exact complete expired `queued/published` owner tuple, open response/transport boundaries, no evidence and fresh monitor; fingerprint binds token hash, `claimed_at` and lease expiry | under supplier lock and fixed row locks, compare-and-set the exact expired tuple, clear all three owner fields, clear watchdog, set only outbox `published -> recovery_required` with `queued_ownership_lease_expired`, preserve claim/parents/key/counters, and insert start plus success atomically | none; database-only | none | `ownership_recovery_succeeded/queued_ownership_lease_expired` | rollback leaves the original complete owner tuple; successor/live/half-bound owner or any boundary change rejects with zero mutation | same authorization returns terminal result; stale authorization loses exact-tuple CAS; after success a newly issued `republish_same_key` is required for Redis |
| `terminalize_stale_dispatch` | exact due pre-processing tuple whose deadline, delivery, response or publication-attempt boundary requires one allowlisted terminal reason; complete expired owner is allowed only when bound in fingerprint | atomically insert start, CAS exact tuple, clear owner/watchdog/recovery fields, terminalize claim/outbox/applicable parents with the authorized reason, and insert terminal success | none; database-only | none | `terminalization_succeeded` with only its five action-compatible terminal codes | a changed/live/half-bound owner or recovered boundary rejects; no fallback to republication | rollback permits same unexpired authorization retry; committed result is idempotent; every other action requires new issuance |
| `terminalize_publication_mismatch` | exact eligible pre-processing mismatch tuple and `dispatch_publication_mismatch` fingerprint | atomically insert start, run the exact one-target mismatch terminal CAS and insert terminal success | none; database-only | none | `terminalization_succeeded/dispatch_publication_mismatch` | any active owner, parent/key/evidence mismatch or noncanonical state rejects without mutation | rollback retries same authorization; exact already-terminal replay returns no-op; cannot republish |
| `terminalize_abandoned_processing` | exact complete expired `processing/published` owner tuple and `processing_lease_abandoned` fingerprint | atomically insert start, CAS the exact expired tuple, fail applicable history/job/run and claim, preserve published outbox, clear owner, and insert terminal success | none; database-only | none | `terminalization_succeeded/processing_lease_abandoned` | live or successor owner, unexpired/half-bound tuple, generation or parent mismatch rejects without mutation | rollback retries same authorization; terminal retry returns existing result; cannot use queued-owner or republish paths |

No row can emit another row's event/result pair. Phase B exists only for
`republish_same_key`; elapsed time never changes its authorized action. The
four database-only actions expose no durable start without their exact mutation
and terminal result in the same transaction.

The immutable result fingerprint domain is exactly
`supplier-import-dispatch-recovery-result-v1` and uses
`SHA-256(domain_separator || 0x00 || canonical_json_bytes)`. Its canonical
object keys are exactly, in order:

```text
schema, authorization_id, authorization_action, authorized_operator_id,
execution_claim_id, dispatch_outbox_id, logical_execution_key,
target_parent_type, target_parent_id, event_sequence, event_kind,
expected_state_fingerprint, resume_state_fingerprint, canonical_result_code,
occurred_at
```

`schema` is exactly `supplier-import-dispatch-recovery-result-v1`. It uses the
same fixed-order, no-whitespace, no-normalization, base-10 integer,
lowercase-hexadecimal and UTC microsecond timestamp rules as the state
fingerprint. No key is omitted and no boolean, float or localized number is
accepted. The stored lowercase hexadecimal digest is `result_fingerprint`.

### Outbox publisher and manual recovery

After the authorization transaction commits, an immediate publisher may lease
the pending row, but it may publish the original serialized job to Redis only
after the same transaction durably reserves the exact physical publication
ordinal/generation/token. Its separate call-boundary CAS commits before the one
Redis invocation. Publication success is acknowledged by one owner-token- and
publication-generation-checked transaction that changes the
outbox from `leased` (or `pending` when a fast handler adopts it) to `published`
and the claim from `pending_dispatch` to `queued`. A crash before or after the
external call consumes the reserved ordinal and is classified through the exact
attempt tuple after owner loss; a duplicate publication requires the next
durably reserved ordinal and the same payload/key. Claim uniqueness and terminal
checks make an accepted duplicate harmless. Every acknowledged initial or
recovery publication sets
`delivery_watchdog_at = UTC_TIMESTAMP(6) + INTERVAL 4320 SECOND` in the same
owner-token-checked transaction. Neither case creates a key.

The mandatory future visibility interface is the separate CLI command:

```text
php artisan suppliers:monitor-import-dispatch-watchdogs
```

It is read-only for supplier/import/catalog domain state and incapable of
dispatching a job or changing claim, outbox, authorization, result, parent,
evidence, staging, Product, schedule, or Catalog Sync state. Its only writes are
the dedicated monitor-health and alert-intent coordination records specified
below. It is safe to schedule every 300 seconds and uses the
canonical watchdog index and the exact due-candidate predicate below. It emits
only due candidate count, oldest due timestamp, oldest overdue duration,
opaque claim/outbox IDs, and supplier numeric ID where the existing privacy
policy permits that ID. Supplier name, source identity, URL, path, raw offer
identity, logical execution key, payload, token, nonce, and hashes are forbidden.

The monitoring, durable heartbeat and acknowledged alert contract are mandatory
before capture can be enabled. The repository currently has no approved
external alert provider, so implementation may not invent credentials or claim
readiness. A provider-neutral `SupplierImportDispatchAlertSink` with a configured
implementation, bounded health acknowledgement and idempotent delivery is an
implementation prerequisite.

The scheduler runs every 300 seconds through `everyFiveMinutes()` with overlap
prevention. The monitor remains read-only for claims, outboxes, parents,
evidence, staging, Products and Catalog Sync, but it writes only two dedicated
coordination surfaces:

1. a singleton `supplier_import_dispatch_monitor_health` row containing exact
   monitor/observer identities, generation-bound monitor lease, monotonically
   increasing successful cycle and observer sequences, the generation/cycle
   observed by the independent probe, successful timestamps, integrity state
   and allowlisted failure code; and
2. durable `supplier_import_dispatch_alert_intents` containing opaque
   byte-canonical idempotency identity, severity, privacy-safe payload,
   generation-bound delivery lease, bounded attempt count, next-attempt time,
   acknowledgement time and allowlisted failure code.

Canonical monitor integrity states are exactly `healthy`, `stale`, `failed` and
`unknown`. A successful cycle must complete the due-row query, validate every
row shape, receive the sink's bounded health acknowledgement, durably create any
missing alert intents, and then atomically increment `cycle_sequence`, set both
successful timestamps from MySQL UTC and write `healthy`. Query/shape failure
writes `failed` when the database transaction is available. Sink timeout,
negative acknowledgement or permanent delivery failure writes `failed`.
Startup, database uncertainty and an unavailable/unreadable health row are
`unknown`. A last successful cycle or sink-health timestamp older than 600
seconds, or a last successful independent-observer timestamp older than 120
seconds, is derived as `stale` even if the stored label still says `healthy`.
Nothing can extend monitor/sink freshness except another complete successful
monitor cycle, and nothing can extend observer freshness except another
successful independent observer transaction. Neither writer may advance the
other writer's sequence or timestamps.

Warning remains the first cycle at or after a watchdog becomes due, with at most
300 seconds cadence latency. Critical begins at
`delivery_watchdog_at + INTERVAL 1800 SECOND` and repeats every 900 seconds while
unresolved. The critical bucket remains
`FLOOR((overdue_seconds - 1800) / 900)`.

The canonical logical alert object has schema
`supplier-import-dispatch-alert-v1` and exactly six keys in this order:

```text
schema, alert_type, dispatch_outbox_id, delivery_watchdog_at, severity,
critical_bucket
```

`schema` and `alert_type` are non-null strings exactly
`supplier-import-dispatch-alert-v1` and `dispatch_watchdog_overdue`.
`dispatch_outbox_id` is a positive base-10 JSON integer within signed BIGINT.
`delivery_watchdog_at` is a non-null MySQL-UTC timestamp rendered exactly as
`YYYY-MM-DDTHH:MM:SS.ffffffZ`. `severity` is exactly `warning` or `critical`.
`critical_bucket` is literal JSON `null` for warning and a zero-based unsigned
base-10 JSON integer for critical. Delivery attempt, lease, retry, provider and
acknowledgement fields never participate in logical identity.

Canonical JSON bytes are UTF-8 without BOM, normalization, insignificant
whitespace, line ending or trailing newline. Keys are never reordered or
omitted. Solidus and Unicode characters are not additionally escaped; JSON
string quotes, reverse solidus and control bytes use the JSON-required escapes.
Booleans and floats are forbidden. Null is the four ASCII bytes `null` and
integers have no sign, leading zero, decimal point, exponent or locale
formatting. Future PHP uses exactly
`JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR` on an
insertion-ordered array after these type checks.

The alert domain-separator bytes are exactly the UTF-8/ASCII bytes for
`supplier-import-dispatch-monitor-alert-v1` followed by one NUL byte (`0x00`).
There is no second delimiter. The 64-character lowercase hexadecimal
`alert_identity` is exactly:

```text
SHA-256(
  "supplier-import-dispatch-monitor-alert-v1" || 0x00 ||
  canonical_alert_json_bytes
)
```

Two synthetic, non-secret vectors are normative. The displayed JSON line is the
complete byte sequence and has no trailing newline:

```text
vector 1 canonical JSON (209 bytes):
{"schema":"supplier-import-dispatch-alert-v1","alert_type":"dispatch_watchdog_overdue","dispatch_outbox_id":101,"delivery_watchdog_at":"2026-08-20T10:15:30.123456Z","severity":"warning","critical_bucket":null}
vector 1 SHA-256:
0784419b016bd71a2ad912c752ab64d5405899f261a22fa78c75f5a300002fe0

vector 2 canonical JSON (207 bytes):
{"schema":"supplier-import-dispatch-alert-v1","alert_type":"dispatch_watchdog_overdue","dispatch_outbox_id":202,"delivery_watchdog_at":"2026-08-20T10:45:30.000000Z","severity":"critical","critical_bucket":0}
vector 2 SHA-256:
a4cfd7d96ada0678b7054d3bfe2f62a1b423a98bb9507ce7e664a9c549b14f31
```

No identity or payload contains source, supplier name, logical key, dispatch
payload, token, nonce, authorization value or authorization/result hash.

Delivery semantics are durable at-least-once intent with idempotent external
acknowledgement, not false at-most-once transport. The sink receives the stable
identity, severity and privacy-safe payload, must acknowledge that identity
within 10 seconds, and must treat duplicate identity as the same alert. Timeout
or transient failure retries on later monitor cycles after 300, 900 and 1,800
seconds, then every 1,800 seconds up to eight attempts. An authoritative
negative permanent response marks the intent `permanent_failed`. An eighth
attempt with no durable local ACK and no authoritative negative response is
never called failed or delivered; after exact lease-expiry classification it is
`delivery_outcome_unknown_exhausted`. Both states make monitor integrity
`failed`. Acknowledged intents are immutable except for retention controlled by
a later design. Silence is never treated as acknowledgement.

The independent liveness observer is the dedicated scheduler-container Docker
healthcheck, running every 60 seconds outside the scheduler process. It invokes
the coordination-only
`suppliers:observe-import-dispatch-monitor-health --quiet` command. The command
first requires exact monitor identity/version, stored state `healthy`, positive
monitor sequence, both monitor/sink timestamps no more than 600 seconds old and
no permanent-failed or outcome-unknown-exhausted intent. On success only, one short transaction locks the
singleton row, revalidates that predicate, increments `observer_sequence` by
exactly one and sets `last_successful_observer_at` from MySQL UTC; only the
committed transaction returns zero. It does not update monitor sequence,
monitor/sink timestamps or any supplier/import/catalog domain row. Missing row,
database/command error, unexpected identity/state, stale timestamps,
permanent-failed/outcome-unknown-exhausted intent or failed observer commit returns non-zero, leaves the
observer timestamp unchanged and marks the container unhealthy. The application
admission gate requires that durable observer timestamp to be no more than 120
seconds old in addition to re-evaluating the monitor predicate. A dead scheduler
cannot authorize itself with an old monitor timestamp, and a dead observer
cannot be masked by a still-running scheduler.

`SupplierImportDispatchMonitorGate` rejects capture enablement, the start of
every protected import generation, authorization issuance and every Phase-A
mutating recovery start unless health is currently derived `healthy`: exact
identities, stored `healthy`, positive monitor and observer sequences, monitor
and sink timestamps no more than 600 seconds old, observer timestamp no more
than 120 seconds old, and no permanent-failed or
`delivery_outcome_unknown_exhausted` intent. Startup requires capture
disabled until one fresh complete monitor cycle, one sink health acknowledgement
and one committed healthy external-observer heartbeat exist. `stale`, `failed`
and `unknown` always fail closed. If health fails after a republish start, Phase
B may not call Redis; it closes that authorization with allowlisted
`monitor_integrity_not_healthy_after_start`, preserves the exact
`queued/recovery_required` state and requires a later newly issued authorization
after health is restored. Database-only actions revalidate health in their one
start/mutation/result transaction.

Alerting remains visibility and escalation only. The monitor and liveness probe
cannot issue authorization, invoke recovery, dispatch import work or alter any
supplier/catalog domain row. On scheduler/monitor crash, database error, query
error or sink timeout/failure, monitor freshness becomes stale/failed/unknown
within 600 seconds. On observer failure or restart, observer freshness becomes
stale within 120 seconds even if monitor cycles continue. All new protected
activity is rejected until both a fresh complete healthy monitor cycle and a
fresh committed observer heartbeat exist.

The exact future MySQL 8.4 coordination schema has two tables. Observer state is
kept in the singleton monitor row because both writers must agree on one monitor
generation; alert acknowledgement is kept in the alert-intent row because it is
one state transition of that durable intent. There is no third acknowledgement
table or duplicate heartbeat concept.

#### `supplier_import_dispatch_monitor_health`

| Column | Exact MySQL contract | Mutability |
| --- | --- | --- |
| `id` | `TINYINT UNSIGNED NOT NULL`, exactly `1` | immutable primary key |
| `monitor_identity` | `VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL`, exactly `supplier-import-dispatch-watchdog-v1` | immutable |
| `monitor_generation` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` | increments by exactly one on each successful lease acquisition |
| `last_successful_monitor_generation` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` | set only by the matching successful cycle |
| `monitor_owner_token_hash` | `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL` | complete monitor-lease tuple |
| `monitor_lease_acquired_at` | `TIMESTAMP(6) NULL` | complete monitor-lease tuple, MySQL UTC |
| `monitor_lease_expires_at` | `TIMESTAMP(6) NULL` | complete monitor-lease tuple, MySQL UTC, 240-second lease |
| `cycle_sequence` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` | increments by exactly one only on a complete successful cycle |
| `last_successful_cycle_at` | `TIMESTAMP(6) NULL` | matching successful-cycle statement time |
| `last_successful_sink_health_at` | `TIMESTAMP(6) NULL` | same statement time after bounded sink acknowledgement |
| `observer_identity` | `VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL`, exactly `supplier-import-dispatch-observer-v1` | immutable |
| `observer_sequence` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` | increments by exactly one only on a successful observer transaction |
| `observed_monitor_generation` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` | generation observed by the last successful observer |
| `observed_cycle_sequence` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` | successful cycle observed by the last observer |
| `last_successful_observer_at` | `TIMESTAMP(6) NULL` | MySQL UTC observer commit time |
| `integrity_state` | `VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'unknown'` | exactly `healthy`, `stale`, `failed`, `unknown` |
| `last_failure_code` | `VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL` | allowlisted code only; never exception text |
| `created_at`, `updated_at` | `TIMESTAMP(6) NOT NULL` | database-managed MySQL UTC |

Named keys are `PRIMARY (id)`,
`uq_import_dispatch_monitor_identity (monitor_identity)` and
`uq_import_dispatch_observer_identity (observer_identity)`. No foreign key is
needed because the row references no domain record. Named MySQL-enforced checks
are:

```text
chk_import_dispatch_monitor_singleton       id = 1
chk_import_dispatch_monitor_identity        exact monitor and observer literals
chk_import_dispatch_monitor_integrity_state exact four-state allowlist
chk_import_dispatch_monitor_owner_tuple     all three lease fields null, or all non-null with lowercase-hex hash and acquired_at < expires_at
chk_import_dispatch_monitor_generation      last_successful_monitor_generation <= monitor_generation
chk_import_dispatch_monitor_success_tuple   cycle_sequence = 0 iff successful generation/timestamps are zero/null; otherwise all are positive/non-null
chk_import_dispatch_monitor_observer_tuple  observer_sequence = 0 iff observed generation/sequence are zero and timestamp null; otherwise all are positive/non-null and do not exceed successful monitor generation/cycle
chk_import_dispatch_monitor_stored_healthy  stored healthy requires positive successful cycle, equal cycle/sink timestamps and null failure code
```

MySQL 8.4 enforces those same-row `CHECK`s. Monotonic increments and exact owner
takeover are transactional CAS invariants, not falsely claimed as CHECK
semantics. Lease acquisition locks `id=1`, requires an all-null or expired
complete lease, increments `monitor_generation` by one, and writes a random
owner-token hash plus MySQL-UTC acquisition/expiry. Successful completion uses
`WHERE id=1 AND monitor_generation=:generation AND
monitor_owner_token_hash=:hash AND monitor_lease_expires_at >=
UTC_TIMESTAMP(6)`, increments `cycle_sequence` by one, copies generation to
`last_successful_monitor_generation`, writes both equal success timestamps,
sets stored `healthy`, clears failure and the complete lease tuple. Failure uses
the same owner/generation CAS, writes `failed`, clears the lease and does not
advance success fields. A stale lease holder therefore cannot overwrite a
successor.

The observer has no long-lived lease: one short `SELECT ... FOR UPDATE`
transaction revalidates the complete derived monitor/sink predicate and absence
of permanent-failed alerts, increments `observer_sequence`, copies current
`last_successful_monitor_generation` and `cycle_sequence`, and writes its UTC
timestamp. A monitor success deliberately makes prior observer bindings stale
until this independent transaction commits. Derived `healthy` requires equality
of both observed values to the latest successful values plus the 600/120-second
freshness windows; the database admission query re-evaluates all of it.

#### `supplier_import_dispatch_alert_intents`

| Column | Exact MySQL contract | Mutability |
| --- | --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` | immutable primary key |
| `alert_identity` | `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL` | immutable canonical digest |
| `schema_version` | `VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL` | immutable, exact alert schema |
| `alert_type` | `VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL` | immutable, exact alert type |
| `dispatch_outbox_id` | `BIGINT UNSIGNED NOT NULL` | immutable opaque target |
| `delivery_watchdog_at` | `TIMESTAMP(6) NOT NULL` | immutable identity timestamp |
| `severity` | `VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL` | immutable `warning` or `critical` |
| `critical_bucket` | `INT UNSIGNED NULL` | immutable; null warning, zero-based critical |
| `payload` | `JSON NOT NULL` | immutable semantic copy of the six-key object, canonical encoding <= 1,024 bytes before insert |
| `delivery_state` | `VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending'` | `pending`, `delivering`, `acknowledged`, `permanent_failed`, `delivery_outcome_unknown_exhausted` |
| `attempt_count` | `TINYINT UNSIGNED NOT NULL DEFAULT 0` | monotonic `0..8`, incremented at lease acquisition |
| `delivery_generation` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` | monotonic delivery-owner generation |
| `delivery_owner_token_hash` | `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL` | complete delivery-lease tuple |
| `delivery_lease_acquired_at` | `TIMESTAMP(6) NULL` | complete delivery-lease tuple |
| `delivery_lease_expires_at` | `TIMESTAMP(6) NULL` | complete delivery-lease tuple, five minutes |
| `next_attempt_at` | `TIMESTAMP(6) NULL` | non-null only for retryable pending intent |
| `acknowledged_at` | `TIMESTAMP(6) NULL` | non-null only for acknowledged intent |
| `last_failure_code` | `VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL` | allowlisted sink code only |
| `created_at`, `updated_at` | `TIMESTAMP(6) NOT NULL` | database-managed MySQL UTC |

Named keys are `PRIMARY (id)`, unique
`uq_import_dispatch_alert_identity (alert_identity)`,
`ix_import_dispatch_alert_outbox (dispatch_outbox_id, created_at, id)`,
`ix_import_dispatch_alert_due (delivery_state, next_attempt_at, id)`, and
`ix_import_dispatch_alert_lease (delivery_state,
delivery_lease_expires_at, id)`. Named
`fk_import_dispatch_alert_outbox` references
`supplier_import_dispatch_outbox(id)` with `ON UPDATE RESTRICT ON DELETE
RESTRICT`; the named child index prevents an implicit MySQL index.

Named MySQL-enforced checks are
`chk_import_dispatch_alert_identity` (lowercase hexadecimal digest),
`chk_import_dispatch_alert_schema_type` (exact schema/type literals),
`chk_import_dispatch_alert_severity_bucket` (warning/null or
critical/non-null), `chk_import_dispatch_alert_state` (five-state allowlist),
`chk_import_dispatch_alert_attempt_bound` (`0..8`),
`chk_import_dispatch_alert_delivery_owner_tuple` (all-null or complete
hash/acquired/expiry with strict time order), and
`chk_import_dispatch_alert_state_tuple`: pending has null owner/ack and a
non-null retry time; delivering has complete owner, null retry/ack and attempts
`1..8`; acknowledged has null owner/retry, non-null acknowledgement and null
failure; permanent-failed has null owner/retry/ack, attempts `1..8` and non-null
failure; `delivery_outcome_unknown_exhausted` has null owner/retry/ack, exactly
`attempt_count = 8`, and `last_failure_code =
alert_delivery_outcome_unknown_exhausted`. Application validation reserializes the six immutable fields and
requires the exact identity/payload match in the insert transaction because a
MySQL JSON value does not preserve canonical object byte order.

Intent insertion is idempotent under the unique identity. Delivery leasing uses
a random token stored only as a hash. Pending acquisition or expired-lease
takeover locks the row, increments `delivery_generation` and `attempt_count`,
and writes the complete lease only while `attempt_count < 8`. Acknowledgement CAS binds `id`,
`alert_identity`, generation, owner hash, `delivering` state and unexpired
lease; it changes state to `acknowledged`, sets MySQL-UTC `acknowledged_at` and
clears the lease atomically. Retry/permanent-failure updates use the same tuple.
A stale sink worker cannot acknowledge a successor generation. Raw token,
provider response, credentials and exception text are never stored.

The attempt-eight unknown-outcome transition is exact. It starts only from
`delivery_state = delivering`, `attempt_count = 8`, the complete exact current
delivery generation/token/acquisition/expiry tuple, `acknowledged_at IS NULL`,
an expired lease (or separately proven current-owner loss), and no authoritative
sink result. One database CAS writes
`delivery_outcome_unknown_exhausted`, preserves `attempt_count = 8`, clears the
complete lease and retry time, leaves `acknowledged_at` null, and writes only the
allowlisted uncertainty code. It makes no external call and never increments to
nine. A pending/takeover query excludes this state, so no worker can acquire a
new automatic delivery lease. A stale attempt-eight worker must revalidate its
generation and lease immediately before its external boundary; after expiry or
CAS loss it cannot call or update the row.

This is terminal only for automatic delivery, not proof of delivery or failure.
It cannot transition to `permanent_failed`, and it cannot synthesize an ACK.
Only a separately designed and authorized reconciliation may change it to
`acknowledged`, and only when authoritative provider evidence binds the exact
`alert_identity` to a positive accepted result; that future CAS would preserve
attempt eight and record the provider-backed acknowledgement time. The current
provider-neutral contract supplies no such lookup, so the state remains
unresolved, auditable and fail-closed and keeps monitor admission unhealthy
until explicit operational remediation. No reset, identity replacement,
counter decrement, automatic retry, or ninth attempt is permitted.

Future additive migration order is exact: (1) create the singleton monitor
table and checks/keys; (2) insert only its `unknown`, generation-zero row while
all capture/recovery gates remain disabled; (3) create alert-intent columns,
checks and indexes; (4) add the named outbox FK; (5) deploy monitor/observer/sink
code disabled; (6) run schema and CAS validation; (7) separately enable and
verify monitor/sink/observer; and only then (8) consider later capture admission.
This additive order does not authorize a production reverse migration.
Destructive `down()` behavior is governed exclusively by the fail-closed empty-
schema predicate below; operational rollback is forward-only and leaves both
coordination tables in place.

If the shared database is unreachable, no writer can persist a fresh state and
the application cannot claim that it read `failed` from the database. The
derived control result is safety-`unknown`; every available admission surface
rejects capture, protected generation and recovery, while the independent
container probe returns non-zero. Database recovery still requires a new
successful monitor cycle and observer heartbeat before activity can resume.

The future recovery interface remains CLI-only:

```text
php artisan suppliers:reconcile-import-dispatch-outbox --dry-run --limit=25
php artisan suppliers:reconcile-import-dispatch-outbox \
    --apply \
    --authorization-id=<recovery-authorization-id> \
    --nonce-stdin
```

It is absent in this phase. A Release/Operations operator with separate
one-run authorization may invoke it on a trusted application host. Dry-run is
the default; its `--limit` defaults to 25 and rejects values outside 1 through
50. Every apply is one exact target and requires one valid
`supplier_import_dispatch_recovery_authorizations` ID whose action is
`republish_same_key`, `recover_expired_queued_ownership` or
`terminalize_stale_dispatch`, plus the protected
out-of-band nonce. There is no broad/page apply and no operator ID argument.
Pending, leased, recovery-required, expired-owner and stale-published recovery
therefore share the same machine-enforced authorization lifecycle. A
`recovery_required` row with
`dispatch_durable_progress_stalled` may resume only when the immutable matching
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
8.4 dry-run discovery may inspect a bounded pending/recovery/stale-lease page,
but apply locks only the one tuple from the authorization. It uses a random
owner key and hashed token with a five-minute lease and validates the original
claim/key/parent and refuses every terminal claim. A `recovery_required` event
must carry an allowlisted transport-only `failed()` or in-handle pre-processing
reason, a literal `queued` claim, a future deadline, and
`delivery_attempt_count <= 6`. Republication serializes the unchanged canonical
`transport_deadline_at` and remaining `delivery_attempt_count`. If either
boundary is no longer valid before a `republish_same_key` start, the reconciler
rejects that action without domain mutation. Only a separately issued
`terminalize_stale_dispatch` authorization may lock outbox, claim and applicable
parents and atomically terminalize them with `transport_deadline_expired` or
`transport_delivery_budget_exhausted`. If a boundary closes after a committed
republish start, the action-specific `action_stopped` contract above closes only
that authorization and preserves the recovery tuple.
For a stale `queued/published` candidate with a complete null ownership tuple,
an exact unexpired `republish_same_key` authorization before the response
objective may change only the outbox to `recovery_required`, clear the watchdog,
preserve the queued claim and parents, record `dispatch_durable_progress_stalled`, and
append the immutable authorization result. The already-authorized outbox may
then resume the same bounded authorized `recovery_required -> published` path
without creating another execution or authorization. If a transport boundary
is exhausted, the exact terminal authorization instead atomically closes claim,
outbox, parents, and its result. A complete expired ownership tuple is delegated
only to the `recover_expired_queued_ownership` or
`terminalize_stale_dispatch` action-specific entry point on
`ExpiredQueuedImportTerminalRepository::resolveExpiredQueuedOwnership()`; an
unexpired or half-bound tuple is never cleared or guessed.
Attempt delays are deterministic: 1, 5, 15, 30, 60, 120, 240, then 480 minutes,
capped at eight outbox publication attempts. Safe output contains only row IDs,
states, counts, and allowlisted reason codes.

The operational response objective is 1,800 seconds after
`delivery_watchdog_at`. Before that instant, an authorized operator may approve
same-key republication while both transport boundaries remain valid, or may
approve fail-closed terminalization. At or after that instant, an unstarted
`republish_same_key` is rejected even if its 900-second artifact has not yet
expired. A started republish records
`action_stopped/republish_response_window_expired_after_start` without domain
terminalization; only a newly issued `terminalize_stale_dispatch` may perform
the terminal action. The terminal reason is
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

Publication attempts one through seven remain eligible for a newly authorized
retry after failure. A
successful eighth publication is acknowledged normally and leaves the outbox
`published`. Only after a failed or irreconcilably ambiguous eighth publication
does the failed `republish_same_key` action preserve the exact recovery state,
record `publish_failed/dispatch_publication_attempts_exhausted`, and release its
authorization ownership. A newly issued `terminalize_stale_dispatch` action
then acquires the supplier lock and locks outbox, claim, and every bound parent.
For `pending_dispatch` with `pending` or `leased`, one transaction
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
    --dry-run

php artisan suppliers:resolve-import-publication-mismatch \
    --apply \
    --authorization-id=<recovery-authorization-id> \
    --nonce-stdin
```

`suppliers:resolve-import-publication-mismatch` is CLI-only, unscheduled,
dry-run by default, explicitly mutating only with `--apply`, limited to exactly
one selected execution, idempotent, and protected by the same immutable
authorization lifecycle. Apply accepts only an authorization whose action is
`terminalize_publication_mismatch`; it derives claim, outbox, logical key,
parent, authorized human and expected fingerprint from that record. It accepts
no claim/key/operator override. The read-only dry-run requires claim/outbox IDs
but cannot issue or consume authorization. Supplier name, feed URL, source path,
raw supplier identifier, logical key in argv, and any broad apply query are
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

### Operationally governed recovery protocol outcomes

| Ownership and payload observation | Transport/response boundary | Permitted protocol outcome |
| --- | --- | --- |
| null owner; no durable progress and watchdog not due | any | no mutation; detection grace remains active; delivery/observation is unknown |
| null owner; no durable progress and watchdog due for less than 1,800 seconds | budget and deadline valid | monitor warning; the operator may issue exactly one action: `republish_same_key` permits supplier-locked `published -> recovery_required` with `dispatch_durable_progress_stalled` and bounded same-key republication, while `terminalize_stale_dispatch` permits only fail-closed terminalization; one authorization never performs both; delivery/observation is never inferred |
| null owner; no durable progress and watchdog due for 1,800 seconds or more | any | monitor critical; republication is forbidden and an exact authorization may only atomically terminalize with the actual transport reason or `dispatch_watchdog_response_expired`; delivery/observation remains unknown |
| null owner; due watchdog with no operator action | any | domain-read-only monitor cycles and durable alert intents continue only while monitor/sink/observer health is current; stale, failed or unknown health rejects every protected admission; no domain mutation occurs and terminalization is not guaranteed |
| complete unexpired owner | any | active-owner continuation; watchdog/reconciler and duplicates affect zero rows |
| complete expired owner | budget/deadline valid and response objective open | only `recover_expired_queued_ownership` may perform the legal first mutation: complete-tuple CAS clears ownership, writes `queued_ownership_lease_expired`, commits `ownership_recovery_succeeded`, and ends; a new `republish_same_key` authorization is required for Redis |
| complete expired owner | transport exhausted or response objective expired | only `terminalize_stale_dispatch` may bind the complete tuple and atomically terminalize claim, outbox and applicable parents; no clear-first step or autonomous mutation |
| half-bound owner | any | fail closed; no ownership clearing, republication, terminal write, or work |
| recoverable transport failure already in `queued/recovery_required` | budget and deadline valid | existing bounded authorized same-key `recovery_required -> published` path |
| recoverable transport failure already in `queued/recovery_required` | delivery exhausted or deadline/response expired | an unstarted republish is rejected; a separately issued `terminalize_stale_dispatch` performs atomic pre-processing terminalization with the exact reason |
| publication mismatch in an eligible canonical pre-processing pair | no active owner and no evidence | exact `terminalize_publication_mismatch` authorization permits the one-execution command to perform atomic terminal failure; identical rerun is `already_terminal_noop` |
| exact expired `processing/published` ownership tuple | exact expected-state fingerprint, authenticated issue/start authority, complete expired owner, canonical parents and no terminal generation | only `terminalize_abandoned_processing` may CAS the exact tuple, fail the bound history/job/run and claim, preserve the published outbox, clear ownership, and commit `terminalization_succeeded/processing_lease_abandoned`; a crash rolls back atomically, a retry returns the result, and a live/successor/competing owner affects zero rows and requires operator reinspection |
| stale payload after a recovery or terminal winner | any | state/key/owner revalidation rejects work; terminal delivery returns the stored no-op |
| committed `republish_same_key` start with exact unchanged baseline and no physical reservation | monitor healthy, publication/delivery budget available, deadline future and response window open | Phase B0 validates the immutable resume fingerprint and Phase B1 atomically reserves only attempt `N + 1`, increments count/generation, writes the fresh token-bound lease and commits before Redis; no reservation means no physical call |
| exact `reserved` publication attempt generation | all boundaries remain valid and the exact token/lease is current | only that generation may CAS once to `call_boundary_entered`; the commit precedes and authorizes exactly one original-key/payload Redis invocation; reuse, late generation, and call-before-commit are forbidden |
| exact `call_boundary_entered` publication attempt generation | Redis returns while the exact generation/token remains authoritative | positive ACK commits `durable_success`, compatible outbox/claim/watchdog changes and `republish_succeeded`; authoritative failure commits `durable_failure` and `publish_failed`; neither result may be written by a stale generation |
| expired unresolved `reserved` or `call_boundary_entered` generation with `attempt_count < 8` | owner loss proven; external outcome not durable; all action boundaries remain open | exact generation/token/timestamp CAS consumes the ordinal as `outcome_unknown` without Redis; only a fresh next-ordinal B1 reservation under the same logical action/key may permit another physical call |
| expired unresolved `reserved` or `call_boundary_entered` generation with `attempt_count = 8` | owner loss proven; no durable ACK or authoritative failure | exact CAS writes final `outcome_unknown`, no Redis call and no attempt nine; `republish_same_key` records only `publish_failed/dispatch_publication_attempts_exhausted`; terminal domain mutation requires a new `terminalize_stale_dispatch` authorization |
| committed `republish_same_key` start with exact resume tuple | any monitor, state, budget, deadline or response boundary invalid before the next external call | no Redis call and no domain terminalization; commit the exact `action_stopped` result, release authorization ownership, then require a new action-specific authorization for the current canonical state |

This table contains exactly 19 data rows and 3 columns. Merely reaching
`handle()`, entering
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

## Canonical Proposed Table Inventory

The complete Phase 9C.6.5C.3D persistence design contains exactly these ten
proposed additive tables. Existing-table constraint/index migrations are not
additional proposed tables:

1. `supplier_import_execution_claims`;
2. `supplier_import_dispatch_outbox`;
3. `supplier_import_dispatch_monitor_health`;
4. `supplier_import_dispatch_alert_intents`;
5. `supplier_import_dispatch_recovery_authorizations`;
6. `supplier_import_dispatch_recovery_results`;
7. `supplier_import_cohort_authorization_members`;
8. `supplier_offer_snapshot_generations`;
9. `supplier_offer_snapshot_enrollments`; and
10. `supplier_offer_snapshot_observations`.

Every reference to all proposed tables, schema guards, privacy scans,
migration tests or capacity estimates means this exact ten-table inventory.

## Exact Index And Foreign-key Contract

The future MySQL 8.4 additive migrations must create the exact named indexes
below. A foreign key is accepted only when its documented supporting index has
the referenced child column as the leftmost column. The implementation must not
depend on an implicit MySQL-created index or implicit name.

Creation order is the additive `import_jobs` ownership index, execution claims,
the execution-path immutable trigger, dispatch outbox, dispatch recovery
authorizations plus their UPDATE/DELETE triggers, dispatch recovery results plus
their UPDATE/DELETE triggers, cohort authorization members, generation headers,
enrollments, observations, and the additive ImportHistory range index. A
destructive `down()` that has first passed the complete local/testing one-run
empty-schema predicate uses the exact reverse order: each table's guards are
dropped immediately before that table, and the path trigger is dropped before
the claim table. The guarded downgrade fails closed while a `RESTRICT`
dependency is non-empty; it never disables foreign-key checks or drops a parent
first. This mechanical order is not an operational rollback path.

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
| `uq_import_recovery_auth_complete_tuple` | (`id`, `authorization_action`, `authorized_operator_id`, `supplier_import_execution_claim_id`, `supplier_import_dispatch_outbox_id`, `logical_execution_key`, `target_parent_type`, `target_parent_id`) | yes | exact composite parent for every immutable result |
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
| `ix_import_recovery_result_complete_auth_tuple` | (`supplier_import_dispatch_recovery_authorization_id`, `authorization_action`, `authorized_operator_id`, `supplier_import_execution_claim_id`, `supplier_import_dispatch_outbox_id`, `logical_execution_key`, `target_parent_type`, `target_parent_id`) | no | `fk_import_recovery_result_complete_auth_tuple` proves the complete authorization binding |
| `ix_import_recovery_result_claim` | (`supplier_import_execution_claim_id`, `occurred_at`, `id`) | no | `fk_import_recovery_result_claim` and bounded claim audit |
| `ix_import_recovery_result_outbox_claim` | (`supplier_import_dispatch_outbox_id`, `supplier_import_execution_claim_id`) | no | exact result outbox/claim binding |
| `ix_import_recovery_result_operator` | (`authorized_operator_id`, `occurred_at`, `id`) | no | bounded human-principal audit; tuple authority comes from the composite FK |

All named foreign keys use `RESTRICT`. The result's complete eight-column tuple
has one composite FK to `uq_import_recovery_auth_complete_tuple`; separate
claim/outbox/operator indexes are query support only and never substitute for
that binding. An identical completed rerun reads the existing terminal event,
while a conflicting reuse fails closed.

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

### Authoritative cryptographic and digest identity inventory

This is the complete 22-entry inventory. `OperationalSupplierOfferIdentityHasher`
uses UTF-8 bytes for its exact
`supplier-offer-lifecycle-operational-preview-v1`, ASCII `0x7c`, bucket, ASCII
`0x7c`, value framing. `CanonicalOnboardingData::encode()` recursively sorts
object keys, preserves list order, normalizes decimals through the existing
normalizer and emits compact UTF-8 JSON with unescaped solidus/Unicode. A row
that says raw bytes has no domain separator or textual conversion. No identity
outside this inventory is introduced by this design.

| # | Identity | Purpose | Producer | Canonical bytes and domain | Algorithm | Persistence location | Immutability | Comparison point |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | `logical_execution_key` | stable execution and redelivery identity | authorization/allocation repository | 32 CSPRNG bytes encoded as 64 lowercase hexadecimal characters; no hash domain | random identity, not a digest | claim, copied to outbox and recovery audit tuple | immutable for the execution | every dispatch, owner admission and recovery tuple |
| 2 | `active_attempt_token_hash` | current import owner proof | execution coordinator | exact in-memory random attempt-token bytes; no domain or text conversion | SHA-256 lowercase hexadecimal | execution claim | fixed for one owner tuple; cleared or replaced only by owner-generation CAS | owner acquisition, live closeout and stale-owner rejection |
| 3 | `source_fingerprint` | exact downloaded-source identity | streaming source reader | exact downloaded file bytes; no domain | SHA-256 lowercase hexadecimal | execution claim and snapshot generation | first claim value write-once; generation copy immutable | same-key source verification and final generation binding |
| 4 | `cohort_seed_fingerprint` | exact capture-start authorized membership | cohort authorization service | hasher `sample()` framing with bucket `snapshot_cohort_authorization_v1` and canonical JSON of version plus sorted member hashes | SHA-256 lowercase hexadecimal | execution claim | write-once with authorization members | capture start, restart and finalization member reconciliation |
| 5 | `supplier_sku_hash` | pseudonymous supplier-offer identity | operational identity hasher | hasher namespace, bucket `supplier_sku`, lowercase-trimmed supplier key, ASCII `0x7c`, trimmed supplier SKU | SHA-256 lowercase hexadecimal | authorization members, enrollments and observations | immutable | membership uniqueness, cohort traversal and observation binding |
| 6 | `dispatch_payload_hash` | byte-exact Redis payload binding | dispatch serializer | domain `mycomputer:supplier-dispatch-payload:v1`, one NUL, then the fixed-order canonical payload JSON | SHA-256 lowercase hexadecimal | dispatch outbox | immutable | every publish, republish and handler admission |
| 7 | `lease_token_hash` | outbox publisher ownership | outbox publisher | exact in-memory random lease-token bytes; no domain or text conversion | SHA-256 lowercase hexadecimal | dispatch outbox | fixed for one lease; owner-CAS cleared or replaced on generation takeover | publication acknowledgement, retry and stale publisher rejection |
| 8 | `publication_attempt_token_hash` | one physical Redis publication authority | initial/recovery publication reservation | exact in-memory random publication-attempt token bytes; no domain or text conversion | SHA-256 lowercase hexadecimal | dispatch outbox | fixed for one publication generation; cleared only by exact result/unknown CAS or replaced by next-generation reservation | call-boundary entry, durable result and stale-publication-worker rejection |
| 9 | `expected_state_fingerprint` | pre-start recovery authorization CAS identity | recovery authorization issuer | `expected_state_fingerprint_v2`: domain `mycomputer:supplier-recovery-expected-state:v2`, one NUL, then the exact 20-field canonical JSON | SHA-256 lowercase hexadecimal | recovery authorization | immutable | Phase-A start revalidation only |
| 10 | `authorization_nonce_hash` | one-time recovery capability proof | recovery authorization issuer | domain `supplier-import-dispatch-recovery-nonce-v1`, one NUL, then exact 32 raw nonce bytes | SHA-256 lowercase hexadecimal | recovery authorization | immutable and unique | constant-time nonce proof before any start/result write |
| 11 | `resume_state_fingerprint` | post-start same-key republication identity | republish Phase-A transaction | domain `supplier-import-dispatch-recovery-resume-v1`, one NUL, then its exact 16-field canonical JSON | SHA-256 lowercase hexadecimal | recovery result `started` row only | immutable | Phase-B0 baseline validation before first physical-attempt reservation |
| 12 | `result_fingerprint` | immutable recovery event identity | recovery result repository | domain `supplier-import-dispatch-recovery-result-v1`, one NUL, then its exact fixed-order result JSON | SHA-256 lowercase hexadecimal | recovery result | immutable | duplicate/result replay and audit verification |
| 13 | `monitor_owner_token_hash` | monitor lease-generation owner proof | watchdog monitor | exact in-memory random monitor-owner token bytes; no domain or text conversion | SHA-256 lowercase hexadecimal | monitor-health singleton | fixed for one monitor generation; cleared or replaced only by generation CAS | cycle success/failure and stale-monitor rejection |
| 14 | `alert_identity` | durable idempotent logical alert identity | watchdog monitor | domain `supplier-import-dispatch-monitor-alert-v1`, one NUL, then exact six-key canonical alert JSON | SHA-256 lowercase hexadecimal | alert intent and external sink idempotency key | immutable | idempotent insertion, delivery and external acknowledgement |
| 15 | `delivery_owner_token_hash` | alert-delivery lease owner proof | alert delivery worker | exact in-memory random delivery-owner token bytes; no domain or text conversion | SHA-256 lowercase hexadecimal | alert intent | fixed for one delivery generation; cleared or replaced only by generation CAS | ACK, retry, permanent failure, unknown-exhausted classification and stale-worker rejection |
| 16 | `snapshot_id` | V1 evidence projection identity | evidence adapter | hasher `sample()` framing with bucket `snapshot_generation_v1` and canonical JSON of stored supplier key plus ImportHistory ID | SHA-256 lowercase hexadecimal | derived V1 evidence output; not stored as a new table column | deterministic for immutable inputs | evidence projection identity check |
| 17 | `cohort_fingerprint` | effective enrolled cohort identity | snapshot capture service | hasher `sample()` framing with bucket `snapshot_cohort_v1` and canonical JSON of sorted enrolled offer hashes | SHA-256 lowercase hexadecimal | snapshot generation | immutable after finalization | predecessor comparability and projection gate |
| 18 | `observation_set_fingerprint` | complete physical observation-set identity | snapshot capture service | hasher `sample()` framing with bucket `snapshot_observation_set_v1` and canonical JSON of sorted observation fingerprints | SHA-256 lowercase hexadecimal | snapshot generation | immutable after finalization | count/set reconciliation and evidence projection |
| 19 | `generation_fingerprint` | complete finalized header identity | snapshot capture service | hasher `sample()` framing with bucket `snapshot_generation_header_v1` and canonical JSON of every canonical final header field except storage ID, self-digest and `created_at` | SHA-256 lowercase hexadecimal | snapshot generation | immutable | finalization retry, header integrity and projection |
| 20 | `enrollment_fingerprint` | first-enrollment provenance identity | snapshot capture service | hasher `sample()` framing with bucket `snapshot_enrollment_v1` and canonical JSON of supplier, source, offer hash, effective ImportHistory ID and provenance | SHA-256 lowercase hexadecimal | snapshot enrollment | immutable | idempotent enrollment insert and conflict detection |
| 21 | `observation_fingerprint` | one physical offer observation identity | snapshot collector | hasher `sample()` framing with bucket `snapshot_observation_v1` and canonical semantic observation JSON including `present`, excluding storage keys/timestamps | SHA-256 lowercase hexadecimal | snapshot observation | immutable | observation uniqueness, set fingerprint and replay verification |
| 22 | `reliable_manufacturer_mpn_hash` | reserved manufacturer-MPN identity | no authorized producer in APCOM V4 | no approved domain or bytes in this design | none authorized | nullable snapshot observation field | must remain null for APCOM V4 | any non-null value fails the current contract |

The inventory count is contractual. Repeated persistence of one identity, such
as `source_fingerprint` or `supplier_sku_hash`, remains one cryptographic
contract rather than an invented second algorithm. The monitor and observer
literal identity strings and numeric generations are not digests. Every
ownership token hash is generation-bound coordination state, not durable proof
of external delivery.

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
| `supplier_import_dispatch_outbox` | `logical_execution_key`, `dispatch_payload_hash` | `lease_token_hash`, `publication_attempt_token_hash` |
| `supplier_import_dispatch_monitor_health` | none | `monitor_owner_token_hash` |
| `supplier_import_dispatch_alert_intents` | `alert_identity` | `delivery_owner_token_hash` |
| `supplier_import_dispatch_recovery_authorizations` | `expected_state_fingerprint`, `authorization_nonce_hash` | none |
| `supplier_import_dispatch_recovery_results` | `result_fingerprint` | `resume_state_fingerprint` |
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
| recoverable pre-processing failure, published dispatch without durable progress, or expired queued owner | non-terminal `queued` | `recovery_required` | both transport boundaries valid; exact recoverable reason; no importer/evidence; parents preserved at their exact boundary; no claim about payload observation |
| irrecoverable pre-processing delivery/deadline exhaustion | `terminal_failed` | `terminal_failed` | exact transport reason; every applicable parent closed atomically; no importer/evidence |
| failed eighth initial dispatch publication, separately terminalized failed recovery publication, or separately resolved irreconcilable dispatch binding | `terminal_failed` | `terminal_failed` | exact publication reason and action-specific authority; every applicable parent closed atomically; `republish_same_key` never performs this terminalization; no importer/evidence |

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
matrix has exactly 66 data rows and 11 columns.
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
| 26. Recoverable callback commits before a transport boundary later expires | both | unchanged `pending` or `running` until a separately authorized terminal action; legacy: N/A | unchanged until that terminal action | unchanged until that terminal action | `queued` with all-null owner | `recovery_required` with exact recoverable reason | none | `republish_same_key` may start only while all boundaries remain valid; after expiry it is rejected or action-stopped, and a new `terminalize_stale_dispatch` authorization is required | cross-action terminalization, stale ownership, source work, or importer replay | issue exactly the action valid for the current boundary; never reuse republish authority to terminalize |
| 27. In-handle exception while current attempt owns `processing` | both | orchestrated: atomically `failed`; legacy: N/A | atomically `failed` | atomically `failed` | atomically `terminal_failed` with `capture_processing_failed` and cleared ownership | unchanged `published` | none | no replay; later execution needs new authorization | importer replay, republish, or inferred evidence | inspect failure, then separately authorize any new execution |
| 28. Transport-only `failed()` sees `processing` | both | orchestrated: unchanged `running`; legacy: N/A | unchanged `running` | unchanged `started` | unchanged `processing` with complete original ownership tuple | unchanged `published` | none | after verified expiry, dedicated abandoned-owner recovery acquires a new supplier lock and CASes the persisted tuple without the old raw token | outbox regression, owner replacement, live-owner token bypass, direct terminal updates, lock release, or importer replay | authorize the abandoned-processing reconciler after verified expiry |
| 29. Publication attempt 8 succeeds | both | unchanged at the exact pre-processing boundary; orchestrated may remain `pending`; legacy: N/A | unchanged at the exact pre-processing boundary; may be absent or `pending` | absent | initial publication changes `pending_dispatch -> queued`; recovery publication preserves `queued` | literal `published` with `attempt_count=8` | none | normal same-key delivery; terminal checks still precede all work | terminalizing solely because ordinal is eight or permitting attempt nine | none |
| 30. Publication attempt 8 fails or remains irreconcilably ambiguous | both | unchanged at pre-processing boundary until separate terminal authorization; legacy: N/A | unchanged | unchanged | unchanged `pending_dispatch` or `queued` | preserved `pending` or `recovery_required`, `attempt_count=8` | none | `republish_same_key` records `publish_failed/dispatch_publication_attempts_exhausted`; no ninth publication; a new `terminalize_stale_dispatch` authorization atomically closes the exact tuple | terminalization by republish authority, another publication, new job under old key, or importer replay | inspect queue transport, then issue exact terminal authorization |
| 31. Stale queued attempt lease | both | orchestrated: unchanged `pending` before history or `running` after history; legacy: N/A | unchanged absent, `pending`, or `running` according to allocation/history boundary | unchanged absent or `started` | `queued` with a complete expired pre-processing owner | `published` with durable watchdog | none | open boundaries require `recover_expired_queued_ownership` to complete-tuple CAS into `recovery_required`; exhausted boundaries require separate `terminalize_stale_dispatch`; neither action can become the other | live-owner takeover, `forceRelease()`, second parent/history, lost-token fabrication, source work, clear-first mutation, or ownership while outbox is `recovery_required` | issue exactly one action matching the observed complete tuple and current boundary |
| 32. Stale processing attempt lease | both | orchestrated: unchanged `running`; legacy: N/A | unchanged `running` | unchanged `started` | expired `processing` with complete persisted tuple | unchanged `published` | none | new supplier lock plus `outbox -> claim -> parents` abandoned-owner CAS; winner of live-owner/finalization race is authoritative | outbox regression, owner replacement, old raw-token requirement, download, importer replay, or direct terminal repair | authorize bounded abandoned-processing recovery |
| 33. Different source fingerprint under same key | both | orchestrated: atomically `failed`; legacy: N/A | atomically `failed` without importer replay | atomically `failed` when already started | atomically `terminal_frozen` with first digest retained | unchanged `published` | no generation | no replay; new logical execution requires new authorization | digest replacement, parser/staging, or evidence generation | investigate source identity, then authorize a new execution if appropriate |
| 34. Watchdog becomes due with null durable owner/progress, whether delivery was lost, admitted then released on supplier-lock contention, or admitted then crashed before ownership | both | orchestrated: unchanged `pending`; legacy: N/A | unchanged absent or `pending` | absent | `queued` with null owner; `delivery_attempt_count` may prove zero or more admissions | `published` with due `delivery_watchdog_at` | none | the indexed domain-read-only monitor raises canonical privacy-safe alerts; only current 600-second monitor/sink and 120-second generation-bound observer health permits issuance/start; one action may republish before the objective or a separately authorized action may terminalize | autonomous/cross-action mutation, treating null ownership as proof of nondelivery, stale health, new key/event/job, source access, importer, evidence, or ninth publication | review durable state and create one exact 900-second action authorization; without action it remains nonterminal and critically alerted |
| 35. Complete expired `queued/published` pre-processing owner | both | orchestrated: unchanged `pending` or `running`; legacy: N/A | unchanged absent, `pending`, or `running` | unchanged absent or `started` | `queued` with complete expired tuple | `published` with due watchdog | none | the monitor detects without mutation; open boundaries permit only `recover_expired_queued_ownership`; exhausted boundaries permit only `terminalize_stale_dispatch`, each binding the exact complete tuple | autonomous owner clearing, clear-first/authorize-later, clearing a live/half-bound/successor owner, old-token fabrication, importer replay, evidence, or parent drift | review, issue and execute exactly the current action; absent action the state remains visible |
| 36. Explicit publication-mismatch terminal resolution and rerun | both | applicable orchestrated run atomically `failed`; legacy: N/A | applicable bound job atomically `failed` | applicable same-execution history atomically `failed` | eligible pre-processing claim atomically `terminal_failed` | atomically `terminal_failed` | none | exact `terminalize_publication_mismatch` authorization tuple under supplier and row locks records `dispatch_publication_mismatch`; identical rerun returns `already_terminal_noop` | broad selection, active-owner race, parent/key/operator guess, source/import/staging/evidence work, or conflicting terminal rewrite | run the one-execution dry-run, issue the exact authorization in authenticated admin, then separately apply with authorization ID and protected stdin nonce if still canonical |
| 37. Republish start commits, then a boundary closes before B1 reservation | both | unchanged | unchanged | unchanged | `queued`, null owner | `recovery_required`, exact resume fingerprint still matches and no new physical-attempt reservation exists | none | same authorization makes no Redis call and commits only action-compatible `action_stopped`; after that terminal result a new current-state authorization may issue | terminalization, boundary override, attempt increment, reuse of Phase A, another key or another action under the old authorization | issue a new `republish_same_key` only if all boundaries are valid, otherwise issue `terminalize_stale_dispatch` |
| 38. Crash before Phase B0 validation or B1 reservation | both | unchanged | unchanged | unchanged | `queued`, null owner | `recovery_required`; sequence-1 start and original resume fingerprint are durable; publication-attempt tuple remains at its prior resolved baseline | none | the same authorization reruns B0; only successful B0 plus atomic B1 may reserve an ordinal | Redis call, counter increment outside B1, second start, or fictional changed fingerprint | retry the same authorization while its boundaries remain valid |
| 39. Phase B1 reservation commits before Redis | both | unchanged | unchanged | unchanged | `queued`, null owner | `recovery_required`; `attempt_count=N+1`, fresh publication generation/token, `reserved`, call boundary null | none | only the exact unexpired reservation may attempt `reserved -> call_boundary_entered`; the ordinal is already consumed | Redis before commit, second physical call under the reservation, decrement/reuse, or competing generation | continue only through the exact reserved generation |
| 40. Crash after reservation and before call-boundary CAS | both | unchanged | unchanged | unchanged | `queued`, null owner | exact `reserved` tuple; call boundary and durable result null | none | after proven owner loss/expiry, exact CAS classifies the consumed ordinal `outcome_unknown` without Redis | assuming no call, reusing the ordinal, direct next call, or stale-token mutation | classify the exact tuple, then evaluate whether a next ordinal is legal |
| 41. Call-boundary CAS commits and worker disappears before durable result | both | unchanged | unchanged | unchanged | `queued`, null owner | exact `call_boundary_entered` tuple; external call may or may not have happened | none | after proven owner loss/expiry, exact CAS classifies `outcome_unknown`; no call may occur under that generation again | guessing success/failure, replaying the same reservation, or clearing authority without CAS | preserve uncertainty and inspect current boundaries |
| 42. Redis success occurs before durable success transaction | both | unchanged | unchanged | unchanged | `queued`, null owner | remains `recovery_required/call_boundary_entered` until database result; external acceptance is not durable locally | none | unresolved expiry becomes `outcome_unknown`; only a new ordinal may later publish the same key idempotently | writing success without exact generation/token, reusing the reservation, or inferring handler work | allow exact success CAS while authority is current; otherwise classify unknown |
| 43. Redis authoritative failure occurs before durable failure transaction | both | unchanged | unchanged | unchanged | `queued`, null owner | remains `recovery_required/call_boundary_entered` until database result | none | exact current generation may commit `durable_failure/publish_failed`; after owner loss it becomes `outcome_unknown`, not invented failure | writing failure from stale authority, decrementing count, or silently retrying same reservation | persist exact failure while authoritative or classify unknown after expiry |
| 44. Unresolved publication attempt lease expires | both | unchanged | unchanged | unchanged | `queued`, null owner | exact `reserved` or `call_boundary_entered` generation remains with consumed count and no resolution | none | one successor CAS binds every attempt field, writes `outcome_unknown`, clears token, and performs no Redis call | clear-first repair, counter rollback, same-ordinal retry, or guessed external result | classify uncertainty before any next-attempt decision |
| 45. Next physical publication attempt is allowed after unknown result | both | unchanged | unchanged | unchanged | `queued`, null owner | prior latest generation is `outcome_unknown`, count below 8, no terminal result | none | B1 atomically reserves exactly the next ordinal/generation under the same logical action/key after all boundaries are revalidated | unchanged-fingerprint fiction, ordinal reuse/skip, new key/payload, or call-before-reservation | continue only through the new exact reservation |
| 46. Final publication attempt becomes outcome unknown | both | unchanged | unchanged | unchanged | `queued`, null owner | attempt eight is `outcome_unknown`, no durable ACK/failure, no active token | none | no further reservation; record only `publish_failed/dispatch_publication_attempts_exhausted`; separate terminal authorization is required | attempt nine, false success/failure, or terminalization under republish authority | inspect transport, then issue exact terminal action if still canonical |
| 47. Stale publication worker returns after classification or successor reservation | both | unchanged | unchanged | unchanged | `queued`, null owner | current attempt generation/state/token differs from stale worker | none | stale call-boundary/result CAS affects zero rows and worker exits without Redis | late Redis call, success/failure write, counter mutation, or successor overwrite | investigate repeated stale workers without weakening generation fencing |
| 48. Unknown publication result followed by a closed action boundary | both | unchanged | unchanged | unchanged | `queued`, null owner | remains `recovery_required`; consumed attempt is `outcome_unknown` | none | Phase B reserves no next attempt and commits action-compatible `action_stopped`; later action requires new authorization | guessing outcome, cross-action terminalization, or another external call after boundary | inspect canonical rows, then authorize only the current action |
| 49. Expired-owner recovery crashes before or during its atomic transaction | both | unchanged | unchanged | unchanged | original complete expired `queued` tuple | original `published` watchdog tuple | none | rollback leaves no start/result/domain mutation; same unexpired `recover_expired_queued_ownership` may retry exact CAS | partial owner clearing, recovery reason without result, Redis or source work | retry only after rollback and tuple revalidation |
| 50. Expired-owner authorization loses CAS to a successor/live owner | both | unchanged | unchanged | unchanged | successor complete `queued` tuple | `published` | none | exact old token/hash/timestamp predicate affects zero rows and records only a nonce-proven Phase-A rejection when canonical; successor continues | clearing successor, matching only expiry, half-bound repair, or reuse of stale fingerprint | inspect successor; old authorization cannot retry mutation |
| 51. Expired-owner recovery commits before response or duplicate retry | both | unchanged | unchanged | unchanged | `queued`, all-null owner | `recovery_required` with `queued_ownership_lease_expired`, watchdog null | none | immutable `ownership_recovery_succeeded` proves complete commit; duplicate returns result; Redis requires a new `republish_same_key` authorization | second clear, implicit republication, terminalization or parent/evidence mutation under release authority | separately issue republish only if current boundaries and health permit |
| 52. Monitor lease crash before successful cycle persistence | monitor coordination | unchanged | unchanged | unchanged | unchanged | unchanged | no supplier evidence; monitor row retains generation-bound complete lease and prior successful state | after the 240-second lease expires, one successor may acquire a new generation; derived health becomes stale or unknown at its exact freshness boundary | clearing the lease without generation/token CAS, advancing success fields, creating alerts from an incomplete cycle, or domain mutation | inspect monitor state; keep capture and recovery admission fail closed until a complete successor cycle and observer heartbeat commit |
| 53. Successful monitor cycle persists but lease renewal or next acquisition does not | monitor coordination | unchanged | unchanged | unchanged | unchanged | unchanged | complete cycle, sink-health timestamp and required alert intents are durable; successful completion cleared the lease before process loss | current evidence remains healthy only inside the 600-second window and after matching observer binding; a later scheduler instance acquires the next generation normally | treating process liveness as durable health, extending timestamps, or mutating claims/outboxes | restore scheduling if needed and require the next complete cycle before freshness expires |
| 54. Stale monitor wakes after a successor generation is acquired | monitor coordination | unchanged | unchanged | unchanged | unchanged | unchanged | monitor row contains successor generation and owner hash; old generation/token is stale | old worker exits; successor alone may complete or fail its generation | old worker query results, alerts, heartbeat or state writes | none unless successor also fails; preserve fail-closed freshness derivation |
| 55. Stale monitor attempts heartbeat or state mutation | monitor coordination | unchanged | unchanged | unchanged | unchanged | unchanged | successor monitor generation, owner tuple and prior success evidence remain unchanged | exact generation/token/lease CAS affects zero rows and the stale worker exits | overwriting successor, refreshing success timestamps, clearing successor lease or changing admission state | investigate repeated stale writers without weakening the CAS |
| 56. Alert-delivery lease crash before external attempt at count below 8 | alert coordination | unchanged | unchanged | unchanged | unchanged | unchanged | alert intent is `delivering` with incremented count, generation-bound complete lease and no ACK; process loss makes call occurrence unprovable | retain uncertainty; after lease expiry a successor may reserve only the next attempt with the same `alert_identity` | marking acknowledged/failed, claiming durable non-attempt, changing identity or domain mutation | allow bounded lease recovery while count remains below 8 |
| 57. Alert worker crashes after external attempt below count 8 but before ACK persistence | alert coordination | unchanged | unchanged | unchanged | unchanged | unchanged | intent remains `delivering` with complete lease and no durable ACK; external outcome is unknown | after expiry a successor retries idempotently under a new generation/attempt | guessing delivered or undelivered, creating a new identity, or writing a synthetic ACK | verify sink idempotency and allow bounded generation retry |
| 58. Alert-delivery lease below count 8 expires | alert coordination | unchanged | unchanged | unchanged | unchanged | unchanged | expired `delivering` tuple retains attempt count, generation and no ACK | one successor atomically increments generation/attempt and replaces the complete owner tuple | clear-first repair, attempt rollback, identity replacement or simultaneous takeover | none unless the next ordinal is eight |
| 59. Successor alert worker acquires a new delivery generation | alert coordination | unchanged | unchanged | unchanged | unchanged | unchanged | intent remains `delivering` with successor generation/token and stable immutable payload/identity | successor performs at most the current idempotent attempt and owns ACK/retry CAS | old-generation mutation, duplicate intent, payload drift or domain mutation | monitor successor result and health gate |
| 60. Old alert worker returns after successor acquisition | alert coordination | unchanged | unchanged | unchanged | unchanged | unchanged | successor delivery tuple and any successor result remain authoritative | old generation/token ACK, retry or failure CAS affects zero rows and expired local authority forbids a call | accepting a late ACK, making a late call, or overwriting successor result | inspect sink by stable identity only; do not mutate local evidence manually |
| 61. Alert acknowledgement persists before worker loss | alert coordination | unchanged | unchanged | unchanged | unchanged | unchanged | intent is durably `acknowledged`, ACK time is non-null and owner/retry fields are null | duplicate delivery observes terminal acknowledged state and performs no external call | reacquiring delivery lease, redelivering or changing ACK evidence | none |
| 62. Independent observer fails or stops updating | observer coordination | unchanged | unchanged | unchanged | unchanged | unchanged | monitor cycle/sink may remain fresh, but observer sequence/timestamp stop; after 120 seconds derived health is stale | restart probe; one short transaction must revalidate latest monitor generation/cycle and commit a fresh observer binding | monitor self-authorizing observer freshness, copying timestamps, or admitting capture/recovery while stale | keep protected admission closed until the new observer transaction succeeds |
| 63. External alert ACK is uncertain without a durable local ACK below attempt 8 | alert coordination | unchanged | unchanged | unchanged | unchanged | unchanged | durable intent preserves stable identity and unacknowledged attempted state; sink may or may not have accepted | retain outcome unknown; retry only after lease rules permit, at the next ordinal, and with the same sink idempotency identity | converting uncertainty to acknowledged/permanent failure or suppressing bounded retry | inspect sink health; keep monitor integrity failed/stale where required until ACK succeeds |
| 64. Alert attempt 8 is reserved and worker disappears before the external call | alert coordination | unchanged | unchanged | unchanged | unchanged | unchanged | exact `delivering`, `attempt_count=8`, complete generation/lease, null ACK; durable state cannot prove the call boundary was not crossed | wait for lease expiry; no successor acquisition or increment is legal | assuming not attempted, reusing ordinal eight, incrementing to nine, or synthetic failure/ACK | keep monitor admission failed and classify the exact tuple after expiry |
| 65. Alert attempt 8 may have reached the sink and crashes before durable ACK | alert coordination | unchanged | unchanged | unchanged | unchanged | unchanged | exact attempt-eight `delivering` tuple, expired lease, null ACK and no authoritative sink result | one no-call CAS binds generation/token/timestamps and writes `delivery_outcome_unknown_exhausted`, count 8, null owner/retry/ACK | `delivered`, `permanent_failed`, attempt nine, new lease, identity replacement or external retry | preserve auditable uncertainty; reconcile only with exact authoritative positive sink evidence |
| 66. Alert unknown-exhausted state is revisited | alert coordination | unchanged | unchanged | unchanged | unchanged | unchanged | `delivery_outcome_unknown_exhausted`, count exactly 8, no active lease/retry/ACK | automatic retry is terminally prohibited; provider-neutral design leaves it unresolved and monitor gate unhealthy | ninth call, counter reset, failure invention, ACK invention or automatic state clearing | explicit operational remediation only; a future evidence-backed reconciliation requires separate design/authorization |

Rows 52 through 66 are coordination-only crash domains. They never mutate a
SupplierImportRun, ImportJob, ImportHistory, execution claim, dispatch outbox,
snapshot evidence, Product, `supplier_products`, schedule or Catalog Sync
state. The durable alert classes are exact: `pending` before lease acquisition
is not attempted; any crashed `delivering` generation without a persisted ACK
is outcome unknown, even when the last in-process boundary was before the call;
and `acknowledged` is the only delivered proof. Rows 56 and 57 remain separate
crash boundaries but intentionally converge on the same conservative durable
unknown classification after process loss. Rows 64 through 66 close the eighth
attempt without a ninth call, false acknowledgement or false permanent failure.
Silence or transport ambiguity is never an acknowledgement.

Rows 34 through 51 use these mandatory authorization crash subcases in addition
to the canonical 66-row by 11-column matrix:

| Crash/retry subcase | Exact durable result and next action |
| --- | --- |
| immediately before first `started` transaction | no event or domain mutation; the unexpired authorization may retry Phase A |
| during first `started` transaction | transaction rollback leaves the same pre-start state and no result; retry uses Phase A |
| immediately after committed republish `started` | one exact start row plus `queued/recovery_required`; same authorization uses B0 before its first durable B1 attempt reservation |
| after start but before reservation | if every boundary remains valid, B0 and B1 atomically validate then reserve; otherwise there is no increment/call and exact `action_stopped` commits |
| after reservation but before call boundary | the ordinal is consumed; owner-loss classification writes `outcome_unknown`; the reservation can never be reused |
| after call boundary or Redis effect but before completion evidence | exact current authority may persist result; after loss/expiry the generation becomes `outcome_unknown`; only a newly reserved next ordinal may later republish while all boundaries remain open |
| retry by the same authorization | terminal event is returned, or the exact started/action plus durable publication-attempt tuple continues; the original resume fingerprint is not falsely treated as unchanged after reservation |
| retry/start by a competing authorization | changed pre-state and complete tuple ownership reject it with zero domain mutation |
| already-completed replay | existing terminal result is returned byte-for-byte; no event or domain row changes |

For the four database-only actions, start, exact domain mutation and compatible
terminal result are one transaction, so only the first two and final two
subcases apply. There is no externally visible incomplete start for those
actions. `recover_expired_queued_ownership` is a recovery release, not a
terminalization, and cannot call Redis.

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
php artisan suppliers:reconcile-abandoned-import-executions \
    --apply \
    --authorization-id=<recovery-authorization-id> \
    --nonce-stdin
```

It is absent and unscheduled in this phase. Only a Release/Operations operator
with an exact `terminalize_abandoned_processing` authorization and protected
nonce may apply it. Dry-run is default and permits limits from 1 through 50.
Apply forbids a page/limit and derives its single exact claim/outbox/key/parent
target and authorized human from the authorization; no operator ID or target
override is accepted. The command calls a dedicated abandoned-owner API, not
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

All ten tables in the canonical proposed-table inventory may not contain raw
supplier SKU, EAN/GTIN, MPN,
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
- dispatch an import job or enable APCOM; the only planned schedule addition is
  the 300-second watchdog monitor, whose writes are restricted to dedicated
  heartbeat and alert-intent coordination tables; and
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

This is the canonical 103-row fine-grained checkpoint matrix. Every
authorization row records an
explicit human/repository-owner decision and performs no technical action.
Every action row permits only its named action. Review is not push or PR; review is
not merge; merge is not deployment; deployment is not enablement; enablement is
not import; candidate creation is not approval; approval is not preview; result
review is not closeout. A failed row blocks every later row.

Every one of the five PR-producing chains uses this invariant in separate
checkpoints: candidate/implementation creation, validation, independent review,
remediation or recorded not-required, fresh independent PASS, exact push
authorization, push, exact remote-branch SHA verification, Draft PR creation,
PR base/head verification, CI, independent PR review, merge authorization and
merge. No `implement/validate` or `create/validate` checkpoint is permitted.
Remote verification proves that the expected remote branch exists, its
SHA equals the authorized local commit, no unexpected commit is present, and
the intended base is still correct before PR creation. A Draft PR is never
treated as proof that the authorized branch was pushed.

| # | Checkpoint | Prerequisite | Separately responsible authorization | Permitted action | Result/artifact | Failure behavior | Next |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | Establish current local design candidate | complete local design branch at the reviewed starting SHA | Documentation owner | identify the exact existing local diff/commit as the candidate; no new technical action | pinned design candidate SHA and scope | stop on dirty tree, moved base or scope drift | 2 |
| 2 | Validate local design candidate | checkpoint 1 candidate | QA/Documentation owner | run exact documentation contract, matrix, vector, link, secret and diff validation | validation evidence pinned to candidate | remediate locally; no review/push | 3 |
| 3 | Independent local design review | checkpoint 2 green validation | independent Security, Database and Catalog Sync Safety reviewers | review exact candidate/evidence only | `PASS` or `BLOCKED` pinned to exact SHA | no push or PR | 4 |
| 4 | Remediate blocked design findings or record not-required | checkpoint 3 verdict | Documentation owner | if BLOCKED, remediate and rerun affected validation; if PASS, record `NOT_REQUIRED` | final candidate tied to verdict | remain local | 5 |
| 5 | Fresh independent design re-review/PASS | checkpoint 4 candidate or not-required evidence | independent Security, Database and Catalog Sync Safety reviewers | review exact final candidate and validation | final independent `PASS` pinned to exact SHA | remediate through checkpoint 4; no push | 6 |
| 6 | Authorize design branch push | checkpoint 5 PASS | repository owner | authorize only the exact reviewed commit and branch | recorded push-only authorization | remain local | 7 |
| 7 | Push exact design branch | checkpoint 6 authorization | Release/DevOps operator | push only the authorized branch/commit; no PR action | remote branch exists pending independent verification | stop on push failure | 8 |
| 8 | Verify design remote branch | checkpoint 7 push | independent Release/DevOps verifier | prove remote SHA equals authorized local SHA, no extra commit exists and intended base is still `main` | exact remote branch verification evidence | stop on divergence; no PR | 9 |
| 9 | Create design Draft PR | checkpoint 8 verified remote branch | Release/DevOps operator | open one Draft PR against `main` | Draft PR created; no readiness/merge action | close erroneous PR without broadening scope | 10 |
| 10 | Verify design PR base/head | checkpoint 9 Draft PR | independent Release/DevOps verifier | verify exact base, head branch and head SHA | pinned PR base/head evidence | keep Draft and stop on mismatch | 11 |
| 11 | Run design PR CI | checkpoint 10 verified PR | GitHub Actions CI | run required documentation/security checks | all required checks terminal-success | remediate in separately reviewed commit | 12 |
| 12 | Independent design PR review | checkpoint 11 green CI and unchanged head | independent reviewers | review exact PR diff only | approval with no unresolved findings | keep PR open; remediate through full review chain | 13 |
| 13 | Authorize design merge | checkpoint 12 approval | repository owner | authorize merge only | explicit merge authorization | PR remains open | 14 |
| 14 | Merge design PR | checkpoint 13 authorization | Release/DevOps operator | merge exact approved PR | merge commit in `main` | stop without schema work | 15 |
| 15 | Authorize schema implementation | checkpoint 14 merged design | repository owner with Database/Security scope | authorize additive schema work only | scoped implementation authorization | schema remains absent | 16 |
| 16 | Implement schema candidate locally | checkpoint 15 authorization | implementation owner | add claim, outbox, evidence migrations/repositories and MySQL tests | local schema candidate | keep capture absent/disabled | 17 |
| 17 | Validate schema candidate | checkpoint 16 candidate | QA/Database owner | run migration, MySQL, rollback, CAS and syntax validation | schema validation evidence | remediate; no runtime implementation | 18 |
| 18 | Authorize capture/idempotency/outbox implementation | checkpoint 17 validated schema | repository owner with Security/Catalog Sync Safety scope | authorize runtime implementation only | scoped implementation authorization | runtime remains absent | 19 |
| 19 | Implement capture candidate locally | checkpoint 18 authorization | implementation owner | implement disabled stable-key/outbox/coordinator/streaming/capture behavior and tests | local implementation candidate | keep feature disabled | 20 |
| 20 | Validate complete implementation candidate | checkpoint 19 candidate | QA/Database/Security owners | run focused, MySQL, concurrency, crash, privacy and zero-mutation validation | complete implementation validation evidence | remediate; no review/push | 21 |
| 21 | Independent implementation review | checkpoint 20 green validation and exact diff | independent Database, Security and Catalog Sync Safety reviewers | review exact candidate only | `PASS` or `BLOCKED` pinned to exact SHA | no push or PR | 22 |
| 22 | Remediate blocked implementation findings or record not-required | checkpoint 21 verdict | implementation owner | if BLOCKED, remediate and rerun affected validation; if PASS, record `NOT_REQUIRED` | final implementation candidate | remain local | 23 |
| 23 | Fresh independent implementation re-review/PASS | checkpoint 22 candidate or not-required evidence | independent Database, Security and Catalog Sync Safety reviewers | review exact final candidate/evidence | final independent `PASS` pinned to exact SHA | remediate through checkpoint 22; no push | 24 |
| 24 | Authorize implementation branch push | checkpoint 23 PASS | repository owner | authorize exact reviewed commit and branch only | recorded push-only authorization | remain local | 25 |
| 25 | Push exact implementation branch | checkpoint 24 authorization | Release/DevOps operator | push only authorized branch/commit; no PR action | remote branch exists pending verification | stop on push failure | 26 |
| 26 | Verify implementation remote branch | checkpoint 25 push | independent Release/DevOps verifier | prove exact remote SHA, no extra commit and intended `main` base | exact remote branch verification evidence | stop on divergence; no PR | 27 |
| 27 | Create implementation Draft PR | checkpoint 26 verified remote branch | Release/DevOps operator | open one Draft PR against `main` | Draft PR created | keep Draft; no deployment | 28 |
| 28 | Verify implementation PR base/head | checkpoint 27 Draft PR | independent Release/DevOps verifier | verify exact base, head branch and head SHA | pinned PR base/head evidence | stop on mismatch; no CI assumption | 29 |
| 29 | Run implementation PR CI | checkpoint 28 verified PR | GitHub Actions CI | run required MySQL/concurrency/crash/privacy checks | all required checks terminal-success | remediate through full review chain | 30 |
| 30 | Independent implementation PR review | checkpoint 29 green CI and unchanged head | independent Database, Security and Catalog Sync Safety reviewers | review exact PR diff only | approval with no unresolved findings | keep PR open; remediate through full chain | 31 |
| 31 | Authorize implementation merge | checkpoint 30 approval | repository owner | authorize merge only | explicit merge authorization | PR remains open | 32 |
| 32 | Merge implementation PR | checkpoint 31 authorization | Release/DevOps operator | merge exact approved PR | merge commit in `main` | stop; capture remains disabled | 33 |
| 33 | Authorize staging deployment | checkpoint 32 merge and deploy plan | repository owner | authorize exact merged commit deployment only | deployment authorization | no VPS action | 34 |
| 34 | Deploy implementation disabled | checkpoint 33 authorization | Release/DevOps operator | deploy exact `origin/main` with capture/reconcilers disabled | staging deployment evidence | operational rollback preserves schema/evidence | 35 |
| 35 | Independent post-deployment verification | checkpoint 34 deployment | independent Release/QA reviewer | read-only staging verification | containers/schema/flags/importer/Super Admin evidence | capture remains disabled | 36 |
| 36 | Monitor/observer design review approval | checkpoint 35 plus exact canonical monitor, observer, alert identity, schema, CAS and rollout design | independent Database, Security, Release and Catalog Sync Safety reviewers | review design only | approval pinned to exact design commit and vectors/schema | remediate design; no implementation | 37 |
| 37 | Authorize monitor/observer implementation | checkpoint 36 approval | repository owner with Database/Security/Catalog Sync Safety scope | authorize only disabled monitor, observer, sink adapter, schema and tests | scoped implementation authorization | implementation remains absent | 38 |
| 38 | Verify monitor implementation branch/repository state | checkpoint 37 authorization | implementation owner | create/verify exact branch from approved `origin/main`, clean tree and allowed scope | recorded base/head/scope checkpoint | stop on divergence; no edits | 39 |
| 39 | Implement monitor/observer candidate locally | checkpoint 38 verified state | implementation owner | implement disabled exact schema, monitor lease/CAS, observer, alert identity/sink and admission gate | local implementation candidate; no push or PR | keep schedule/capture/recovery disabled | 40 |
| 40 | Run focused monitor/observer validation | checkpoint 39 candidate | QA/implementation owner | run exact zero-domain-mutation, lease/race, alert-vector and failure tests | focused green evidence | remediate locally; no push | 41 |
| 41 | Validate monitor database/migrations | checkpoint 40 green tests | independent Database reviewer | inspect additive schema, guarded empty-schema down, MySQL checks/indexes/FKs and CAS integration | database validation PASS or findings | remediate; no push | 42 |
| 42 | Validate monitor security and Catalog Sync safety | checkpoint 41 PASS | independent Security and Catalog Sync Safety reviewers | audit nonce/hash/privacy, sink boundaries, fail-closed gates and zero supplier/catalog mutation | security/safety PASS or findings | remediate; no push | 43 |
| 43 | Independent monitor implementation review | checkpoints 40 through 42 evidence and exact local diff | independent Release/QA plus prior mandatory reviewers | review implementation only | `PASS` or `BLOCKED` pinned to exact commit | no push or PR | 44 |
| 44 | Remediate blocked monitor findings or record not-required | checkpoint 43 verdict | implementation owner | if BLOCKED, remediate and rerun affected validation; if PASS, record `NOT_REQUIRED` | final candidate tied to verdict | remain local until independent PASS | 45 |
| 45 | Independent monitor re-review/PASS | checkpoint 44 candidate or not-required evidence | independent Database, Security, Release and Catalog Sync Safety reviewers | review exact final local commit and evidence | final independent `PASS` pinned to exact commit | remediate through checkpoint 44; no push | 46 |
| 46 | Authorize monitor branch push | checkpoint 45 PASS | repository owner | authorize push of exact reviewed commit and branch only | push-only authorization | remain local | 47 |
| 47 | Push exact monitor branch | checkpoint 46 authorization | Release/DevOps operator | push only exact approved branch/commit; no PR action | remote branch exists pending verification | stop on push failure | 48 |
| 48 | Verify monitor remote branch | checkpoint 47 push | independent Release/DevOps verifier | prove exact remote SHA, no extra commit and intended `main` base | exact remote verification evidence | stop on divergence; no PR | 49 |
| 49 | Create monitor Draft PR | checkpoint 48 verified remote branch | Release/DevOps operator | open one Draft PR against `main` | Draft PR created | keep Draft; no merge/deploy | 50 |
| 50 | Verify monitor PR base/head | checkpoint 49 Draft PR | independent Release/DevOps verifier | verify exact base, head branch and head SHA | pinned PR base/head evidence | stop on mismatch | 51 |
| 51 | Run monitor Draft PR CI | checkpoint 50 verified PR | GitHub Actions CI | run required backend/MySQL/security checks | all required checks terminal-success | remediate through full review chain | 52 |
| 52 | Independent monitor PR review | checkpoint 51 green CI and unchanged head | independent reviewers | review exact PR diff only | approvals with no unresolved findings | remain Draft/open until merge authorization | 53 |
| 53 | Authorize monitor PR merge | checkpoint 52 approval and exact unchanged head | repository owner | authorize merge of exact approved PR only | explicit merge authorization | PR remains open | 54 |
| 54 | Merge monitor PR | checkpoint 53 authorization | Release/DevOps operator | merge exact approved PR using repository strategy | merge commit in `main`; schedules/capture/recovery disabled | stop; no deployment | 55 |
| 55 | Authorize disabled monitor staging deployment | checkpoint 54 merge plus reviewed deployment/rollback plan | repository owner | authorize exact merged commit deployment with schedules and capture/recovery disabled | exact deployment authorization | no VPS action | 56 |
| 56 | Deploy monitor disabled | checkpoint 55 authorization | Release/DevOps operator | deploy exact `origin/main`, run migrations with monitor/observer/sink disabled | schema/code present, singleton `unknown`, no scheduled monitor activity | operational rollback keeps schema/evidence and all gates disabled | 57 |
| 57 | Independent disabled-deployment verification | checkpoint 56 deployment | independent Release/QA/Database/Security reviewers | read-only schema/container/flag/privacy/zero-domain-mutation verification | deployment PASS pinned to exact commit/schema | rollback operational state or remediate; no enablement | 58 |
| 58 | Authorize monitor schedule/sink enablement | checkpoint 57 PASS and configured approved sink without documented secrets | repository owner with Security/Catalog Sync Safety approval | authorize only 300-second monitor and independent 60-second observer | exact enablement authorization | schedules remain disabled | 59 |
| 59 | Enable and verify monitor/sink/observer | checkpoint 58 authorization | Release/Operations operator | enable monitor/observer and verify canonical synthetic alert/ACK plus dual freshness; keep capture/recovery disabled | positive generations/sequences, fresh 600/120-second evidence and zero-domain-mutation proof | disable monitor/observer and keep capture/recovery disabled | 60 |
| 60 | Authorize APCOM capture enablement | checkpoint 59 currently fresh derived `healthy` state and acknowledged sink/observer | repository owner with Catalog Sync Safety approval | authorize capture enablement only while continuous gate remains healthy | authorization bound to current monitor/observer sequences | capture stays disabled | 61 |
| 61 | Enable and verify capture | checkpoint 60 authorization plus revalidated healthy gate | Release/Operations operator | enable APCOM-specific gate and verify rejection on stale/failed/unknown health; do not import | enabled only while healthy and default-off import verified | disable capture; no protected generation may start | 62 |
| 62 | Authorize one future APCOM import | checkpoint 61 or prior verified import | repository owner/operator for one named execution | authorize exactly one manual import | pinned one-import authorization | no import | 63 |
| 63 | Execute/verify authorized import | checkpoint 62 authorization | Supplier Import operator | run exactly one import and verify claim/outbox/generation | one qualified/frozen/failed generation or gap | no automatic retry; recover fail closed | 62 or 64 |
| 64 | Verify warm-up/readiness | sufficient checkpoint 63 generations | independent Product Data Quality/Catalog Sync Safety reviewer | read-only readiness evaluation | baseline plus three comparable absences and 48-hour proof, or not-ready result | wait for separately authorized imports | 65 |
| 65 | Authorize evidence-producer implementation | checkpoint 64 ready evidence | repository owner | authorize producer code only | scoped authorization | producer remains absent | 66 |
| 66 | Implement producer candidate locally | checkpoint 65 authorization | implementation owner | implement bounded read-only V1 producer and tests | local producer candidate | no evidence candidate | 67 |
| 67 | Validate producer candidate | checkpoint 66 candidate | QA/Security/Product Data Quality owners | run bounded read-only, privacy, deterministic and zero-mutation tests | producer validation evidence | remediate; no review/push | 68 |
| 68 | Independent producer review | checkpoint 67 green validation and exact diff | independent Security/Product Data Quality/Catalog Sync Safety reviewers | review exact candidate only | `PASS` or `BLOCKED` pinned to exact SHA | no push or PR | 69 |
| 69 | Remediate blocked producer findings or record not-required | checkpoint 68 verdict | implementation owner | if BLOCKED, remediate and rerun affected validation; if PASS, record `NOT_REQUIRED` | final producer candidate | remain local | 70 |
| 70 | Fresh independent producer re-review/PASS | checkpoint 69 candidate or not-required evidence | independent Security/Product Data Quality/Catalog Sync Safety reviewers | review exact final candidate/evidence | final independent `PASS` pinned to exact SHA | remediate through checkpoint 69; no push | 71 |
| 71 | Authorize producer branch push | checkpoint 70 PASS | repository owner | authorize exact reviewed commit and branch only | push-only authorization | remain local | 72 |
| 72 | Push exact producer branch | checkpoint 71 authorization | Release/DevOps operator | push only exact branch/commit; no PR action | remote branch exists pending verification | stop on push failure | 73 |
| 73 | Verify producer remote branch | checkpoint 72 push | independent Release/DevOps verifier | prove exact remote SHA, no extra commit and intended `main` base | exact remote verification evidence | stop on divergence; no PR | 74 |
| 74 | Create producer Draft PR | checkpoint 73 verified remote branch | Release/DevOps operator | open one Draft PR against `main` | Draft PR created | keep Draft | 75 |
| 75 | Verify producer PR base/head | checkpoint 74 Draft PR | independent Release/DevOps verifier | verify exact base, head branch and head SHA | pinned PR base/head evidence | stop on mismatch | 76 |
| 76 | Run producer PR CI | checkpoint 75 verified PR | GitHub Actions CI | run required checks | all required checks terminal-success | remediate through full review chain | 77 |
| 77 | Independent producer PR review | checkpoint 76 green CI and unchanged head | independent reviewers | review exact PR diff only | approval with no unresolved findings | keep PR open | 78 |
| 78 | Authorize producer merge | checkpoint 77 approval | repository owner | authorize merge only | merge authorization | PR remains open | 79 |
| 79 | Merge producer PR | checkpoint 78 authorization | Release/DevOps operator | merge exact approved PR | merge commit in `main` | stop | 80 |
| 80 | Authorize producer staging deployment | checkpoint 79 merge | repository owner | authorize exact deployment only | deployment authorization | no VPS action | 81 |
| 81 | Deploy producer | checkpoint 80 authorization | Release/DevOps operator | deploy read-only producer from exact `origin/main` | deployment evidence | operational rollback preserves schema/evidence | 82 |
| 82 | Producer post-deployment verification | checkpoint 81 deployment | independent Release/QA reviewer | read-only verification | bounded/read-only/zero-mutation proof | block candidate work | 83 |
| 83 | Authorize evidence-candidate preparation | checkpoint 82 proof | repository owner/human decision owner | authorize one candidate preparation only | candidate-preparation authorization | no candidate | 84 |
| 84 | Prepare exact candidate | checkpoint 83 authorization | authorized evidence operator | create one pinned privacy-safe candidate | path, SHA-256 and evaluation timestamp | destroy/reject invalid candidate | 85 |
| 85 | Human approval of exact candidate | checkpoint 84 artifact | named human decision owner | approve exact path/hash/timestamp only | recorded exact-candidate approval | reject/destroy candidate | 86 |
| 86 | Authorize operational preview | checkpoint 85 approval | repository owner | authorize exactly one preview run | one-run authorization | no preview | 87 |
| 87 | Execute one operational preview | checkpoint 86 authorization | authorized operator | run exactly one read-only C3D.1 preview | report and zero-mutation evidence | stop; rerun needs new authorization | 88 |
| 88 | Independent operational-result review | checkpoint 87 report | independent Security/Product Data Quality/Catalog Sync Safety reviewers | review results only | approved result or findings | C3D.1 remains open | 89 |
| 89 | Authorize documentation closeout | checkpoint 88 approval | repository owner | authorize documentation edits only | closeout authorization | no edits | 90 |
| 90 | Implement closeout documentation candidate | checkpoint 89 authorization | Documentation owner | update status/evidence docs only | local closeout candidate | C3D.1 remains open | 91 |
| 91 | Validate closeout documentation candidate | checkpoint 90 candidate | QA/Documentation owner | run documentation contract, links, secret and diff validation | closeout validation evidence | remediate; no review/push | 92 |
| 92 | Independent closeout review | checkpoint 91 green validation and exact diff | independent Documentation/Safety reviewers | review exact candidate only | `PASS` or `BLOCKED` pinned to exact SHA | no push or PR | 93 |
| 93 | Remediate blocked closeout findings or record not-required | checkpoint 92 verdict | Documentation owner | if BLOCKED, remediate and rerun affected validation; if PASS, record `NOT_REQUIRED` | final closeout candidate | remain local | 94 |
| 94 | Fresh independent closeout re-review/PASS | checkpoint 93 candidate or not-required evidence | independent Documentation/Safety reviewers | review exact final candidate/evidence | final independent `PASS` pinned to exact SHA | remediate through checkpoint 93; no push | 95 |
| 95 | Authorize closeout branch push | checkpoint 94 PASS | repository owner | authorize exact reviewed commit and branch only | push-only authorization | remain local | 96 |
| 96 | Push exact closeout branch | checkpoint 95 authorization | Release/DevOps operator | push only exact branch/commit; no PR action | remote branch exists pending verification | stop on push failure | 97 |
| 97 | Verify closeout remote branch | checkpoint 96 push | independent Release/DevOps verifier | prove exact remote SHA, no extra commit and intended `main` base | exact remote verification evidence | stop on divergence; no PR | 98 |
| 98 | Create closeout Draft PR | checkpoint 97 verified remote branch | Release/DevOps operator | open one Draft PR against `main` | Draft PR created | keep Draft | 99 |
| 99 | Verify closeout PR base/head | checkpoint 98 Draft PR | independent Release/DevOps verifier | verify exact base, head branch and head SHA | pinned PR base/head evidence | stop on mismatch | 100 |
| 100 | Run closeout PR CI | checkpoint 99 verified PR | GitHub Actions CI | run required documentation/security checks | all required checks terminal-success | remediate through full review chain | 101 |
| 101 | Independent closeout PR review | checkpoint 100 green CI and unchanged head | independent reviewers | review exact PR diff only | approval with no unresolved findings | keep PR open | 102 |
| 102 | Authorize closeout merge | checkpoint 101 approval | repository owner | authorize merge only | merge authorization | PR remains open | 103 |
| 103 | Merge closeout PR | checkpoint 102 authorization | Release/DevOps operator | merge exact approved documentation PR | closeout merge in `main` | C3D.1 remains open if merge fails | no later supplier phase without separate authorization |

The 103-row dependency audit checks 104 prerequisite edges and has zero missing
prerequisite references, zero forward prerequisite references and zero cycles.
All five PR-producing chains have separate candidate/implementation, validation,
independent review, remediation-or-not-required, fresh independent PASS, push
authorization, push, remote-SHA verification, Draft PR, PR-base/head
verification, CI, review, merge authorization and merge rows. In particular,
monitor implementation authorization precedes branch verification and edits;
local tests, database validation, safety audit and independent PASS precede push
authorization; push precedes Draft PR creation; CI and review plus merge authorization
precede merge; and deployment authorization plus disabled verification precede
schedule/sink enablement. Checkpoint 44 is explicit: it is either a remediation
artifact after BLOCKED or recorded `NOT_REQUIRED` after checkpoint 43 PASS, and
checkpoint 45 independently pins the final exact commit in both cases. The
design, main implementation, monitor, producer and closeout chains all apply the
same candidate/validation/review/remediation/fresh-PASS ordering. No PR, commit,
push, approval, candidate, validation or implementation is assumed to exist
before its creating or authorizing row.

Monitor review is not monitor merge. Monitor merge is not deployment. Disabled
monitor deployment is not schedule/sink enablement. Monitor enablement authorizes
only watchdog evaluation plus bounded heartbeat/alert-intent coordination and
never reconciliation, import, evidence, Catalog Sync or automatic domain
mutation. One healthy cycle is not permanent authority: the 600-second
monitor/sink and separately persisted 120-second observer freshness gates are
re-evaluated for every protected admission. Deployment is not capture
activation.
Capture activation is not import authorization. Import completion is not
evidence approval.

### Forward-only operational rollback and bounded schema downgrade

Operational rollback and migration `down()` are different operations. The only
rollback permitted after this phase is deployed or has processed any protected
state is **forward-only operational rollback**. It disables capture, new
protected-generation admission, recovery issuance/execution, monitor scheduling
and observer activity through their reviewed forward configuration/state
controls. It leaves all ten tables, FKs, triggers, rows and uncertain states in
place. Recovery authorization/results, cohort authorization, snapshot history,
alert intents and the last monitor/observer state remain queryable. Operational
rollback never drops schema, rewrites history, clears an uncertain ACK, modifies
staging, touches Products or changes Catalog Sync. Failure before a transaction
commit rolls back that transaction's inserts and temporary state; failure after
commit requires a forward fix because committed evidence is immutable.

A schema downgrade is only an install-time/local-test escape hatch for an unused
schema. Future destructive `down()` code must evaluate the entire guard before
the first trigger, FK, index or table is dropped. It may proceed only when all
of these predicates are true:

1. the runtime environment is exactly `local` or `testing`;
2. an explicit process-scoped one-run confirmation
   `SUPPLIER_SNAPSHOT_EMPTY_SCHEMA_DOWN_CONFIRMED=true` was supplied to that
   migration invocation and is false/absent by default;
3. capture, protected-generation admission, recovery, monitor and observer
   schedules are all disabled;
4. all ten canonical tables and their expected guard-visible columns exist, so
   a missing/partial/unreadable schema cannot be interpreted as zero rows;
5. execution claims, dispatch outboxes, alert intents, recovery authorizations,
   recovery results, cohort authorization members, snapshot generations,
   snapshot enrollments and snapshot observations each contain zero rows; and
6. the monitor table contains exactly one pristine install row with `id=1`,
   both generations/sequences zero, `integrity_state=unknown`, every success,
   failure and lease field null, and no other row.

Any false, unknown, unreadable or partially evaluated predicate rejects the
whole downgrade before DDL. `production`, `staging` and every unrecognized
environment reject destructive down regardless of row counts or a supplied
confirmation. Data emptiness is mandatory and environment naming alone is
never sufficient. The confirmation authorizes only that one empty-schema
invocation and is not a persistent feature flag.

When and only when the complete predicate passes, development/test downgrade
may remove the named alert FK, guarded triggers and ten empty/pristine tables in
dependency-safe reverse order. That mechanical order is not an operational
rollback recipe. No operator command, deploy rollback or feature disablement
may invoke it after protected evidence exists.

The retention hierarchy is exact:

| Class | Members | Retention rule |
| --- | --- | --- |
| immutable/audit-retained | recovery authorizations/results, cohort authorization members, snapshot generations/enrollments/observations, every alert intent including unknown-ACK and permanent-failure state | preserve through operational rollback; deletion needs a later explicit retention design |
| operational coordination retained after activation | execution claims/outboxes and the monitor singleton including generations, freshness, failure and lease state | mutable only by canonical CAS while active; preserve through operational rollback and incident review |
| disposable before activation only | nine empty tables plus the exact pristine monitor singleton described by the guard | may be removed only by the complete local/testing one-run empty-schema predicate |

Future migration tests are mandatory and use synthetic data only:

1. **Empty schema:** migrate up; verify all ten tables and the exact pristine
   monitor singleton; supply the one-run testing confirmation; migrate down;
   prove success; migrate up again.
2. **Evidence exists:** separately seed each protected domain named in predicate
   5 and each non-pristine monitor-state class; every destructive down is
   rejected before DDL and all rows, tables, FKs and triggers remain intact.
3. **Operational rollback:** with synthetic evidence present, disable the
   forward gates/workers; prove no protected table is dropped and every row is
   still queryable.
4. **Partial evidence/schema:** test mixed populated domains, a missing expected
   table/column, unreadable counts, malformed/pristine-monitor mismatch and a
   partially prepared schema; every case fails closed before the first drop.
5. **Environment/confirmation:** production, staging, unknown environment,
   absent confirmation and persistent-config-only confirmation all reject even
   when tables are empty.

## Future Implementation Map

Proposed later files, subject to separate implementation review:

```text
database/migrations/*_create_supplier_import_execution_claims_table.php
database/migrations/*_create_supplier_import_dispatch_outbox_table.php
database/migrations/*_create_supplier_import_dispatch_monitor_health_table.php
database/migrations/*_create_supplier_import_dispatch_alert_intents_table.php
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
app/Models/SupplierImportDispatchMonitorHealth.php
app/Models/SupplierImportDispatchAlertIntent.php
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
app/Services/Suppliers/SupplierImportDispatchMonitorGate.php
app/Services/Suppliers/SupplierImportDispatchAlertSink.php
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
app/Console/Commands/ObserveSupplierImportDispatchMonitorHealth.php
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
above. No reverse migration may drop any of those objects unless the complete
local/testing one-run empty-schema predicate passes before the first DDL. A
failed guard leaves every trigger, FK, index, table and row intact. Only after a
passing empty-schema guard may mechanical dependency order remove the alert FK,
append-only guards, child tables and parents; it restores no redundant run
index. Migration tests must audit `SHOW CREATE
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
MySQL/Redis integration tests must prove every 64-item acceptance criterion and
all 53 focused watchdog/authorization/mismatch cases above. These are planned
implementation requirements only; this documentation commit changes no
runtime, queue, Docker, environment, schema, worker, or feature-flag value.

Implementation remains split by the 103 checkpoints above. Review, push,
remote-branch verification, Draft PR creation, PR base/head verification,
merge, deployment, enablement, import, candidate preparation, candidate
approval, preview, result review, and closeout never share one authorization.

## Non-approval Boundary

This design does not authorize a migration, model, parser refactor, producer,
import hook, feature flag, real evidence candidate, supplier import, APCOM
schedule change, Catalog Sync action, Product mutation, retention cleanup,
deployment, or C3D.1 operational preview. Supplier #3 selection must not begin
while this prerequisite remains unresolved.

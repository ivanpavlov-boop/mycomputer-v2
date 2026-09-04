# APCOM Operational Offer Lifecycle Preview

<!-- watchdog-document-context classification=CURRENT_SCHEMA_STATUS column_occurrences=0 index_occurrences=0 contract=watchdog-current-state-v1 -->

## Scope

Phase 9C.6.5C.3D adds the first input-driven operationally shaped preview for
APCOM missing-offer lifecycle decisions. The implementation is CLI-only,
deterministic, read-only and non-persistent. It composes the existing snapshot
qualification, missing-offer, reappearance, multi-supplier aggregation,
visibility, deletion and retention policies; it does not create a second
policy engine.

The implementation was merged through PR #210 and deployed at
`c22fc9a8dddf3c6778ab0b88e5a50cbc02fe3f21`, but it has not processed real
APCOM evidence or run the operational preview. Operational execution remains
separately gated. The current database has no immutable per-generation
offer-observation history, and mutable staging, aggregate import reports and
logs cannot be reconstructed as qualified history.

The canonical persistence prerequisite is defined in
[Immutable Supplier Offer Snapshot Persistence Design](IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md).
Its architecture uses a durable capture-start authorization
header plus immutable hashed seed members, immutable generation headers,
first-enrollment cohort rows and exhaustive physical presence/absence
observations. A separate stable parent-execution claim makes both importer job
paths idempotent across concurrent and sequential duplicate delivery through a
terminal no-op. One transactional `supplier_import_dispatch_outbox` row is the
durable database-to-Redis handoff for each claim. Claims move through
`pending_dispatch`, `queued`, `processing`, and one immutable terminal state.
The orchestrated claim is deliberately pair-null through dispatch and queueing;
only the queue owner allocates and atomically binds one feed/ImportJob pair.
The legacy path remains pair-bound at authorization through the same allocation
contract. Before `processing`, same-key delivery may safely retry; after the
first non-repeatable staging side effect, importer replay is prohibited and
abandoned work closes as a visible failed gap. The future timing contract is
exactly `3600 < 3900 < 4200 < 4320`: supplier job timeout, dedicated
`redis_supplier_import` / `supplier-imports` retry-after, MySQL ownership lease,
and Redis supplier-lock TTL. The unrelated Redis queues and worker retain
`retry_after=1300` and cannot consume `supplier-imports`. Both jobs use
`$tries=8` without `retryUntil()`. A separate canonical outbox
`transport_deadline_at`, created from MySQL UTC plus exactly 24 hours at
original authorization, and a durable eight-delivery counter are never reset
by release or republication. One MySQL UTC CAS writes the complete
hashed-token/claim-time/lease-expiry ownership tuple before work; the 60-second
bootstrap and lease-expiry-plus-30 delay remain inside the Redis TTL padding.

Owner-proven exception closeout runs only inside active `handle()`
`try/catch/finally`, where the raw token and lock object exist. Newly
deserialized Laravel `failed()` is transport-only: it cannot close
`processing`, release the original lock, replay the importer, or rewrite
evidence. `recovery_required` is a recoverable outbox-only state while deadline
and delivery budget remain valid. Delivery eight or deadline expiry directly
terminalizes a pre-processing claim, outbox and applicable parents; a
recoverable row that later exhausts requires a newly issued exact terminal
authorization and is never terminalized by republish authority. Exact
ownership/outbox checks,
transactional cross-state rules, and the 66-row by 11-column canonical crash
matrix prevent stranded or contradictory SupplierImportRun, ImportJob,
ImportHistory, monitor, alert-delivery and observer states.

<!-- watchdog-current-state-reference:start contract=watchdog-current-state-v1 -->
```text
classification=CURRENT_SCHEMA_STATUS
column_name=delivery_watchdog_at
column_state=PRESENT / DEPLOYED
index_name=ix_import_dispatch_outbox_state_watchdog_id
index_state=PRESENT / DEPLOYED
index_ordered_columns=state,delivery_watchdog_at,id
runtime_state=INACTIVE / UNWIRED
future_work=RUNTIME ENABLEMENT ONLY; NO SCHEMA ADDITION
```
<!-- watchdog-current-state-reference:end contract=watchdog-current-state-v1 -->

These deployed schema artifacts do not activate the later watchdog runtime.
Acknowledged publication sets the watchdog from MySQL UTC plus exactly 4,320
seconds, and every departure from exact `queued/published` clears it atomically.
Observation, admission, lock contention, release, duplicate delivery and
`failed()` never refresh it. A due null-owner row proves only lack of durable
processing progress, never that the payload was unobserved; the canonical reason
is `dispatch_durable_progress_stalled`.

The future 300-second monitor remains read-only for supplier/import/catalog
domain rows but writes bounded dedicated heartbeat and alert-intent coordination
using generation-bound monitor/delivery leases and exact MySQL checks/indexes/FK.
`healthy` requires a fresh successful cycle and sink acknowledgement no older
than 600 seconds plus a separately persisted observer heartbeat no older than
120 seconds from the independent 60-second container probe;
`stale`, `failed` and `unknown` reject capture, protected-generation start,
authorization issuance and mutating recovery start. Alert delivery is durable
at-least-once intent with at-most-one logical external effect per stable
`alert_identity`, not false at-most-once API transport. A concrete adapter is
eligible only with provider/gateway native generation fencing or durable
provider-enforced idempotency; unsupported providers keep readiness unhealthy.
An eighth alert attempt without durable ACK or authoritative negative evidence
becomes `delivery_outcome_unknown_exhausted` at count eight only under that
external-effect contract, remains neither acknowledged nor permanently failed,
opens no automatic retry or ninth attempt, and keeps the safety gate unhealthy.
No alert provider or credentials are selected here.
The exact NUL-delimited alert domain, six-key JSON object and two synthetic hash
vectors are defined only in the canonical persistence design.

Every mutating recovery uses one immutable complete action/operator/claim/
outbox/key/parent authorization issued only in authenticated Filament by an
active Super Admin. The server computes the pre-state fingerprint. Five actions
cover same-key publication, complete-expired-queued-owner release, stale
terminalization, publication mismatch and abandoned processing. CLI accepts
only authorization ID plus a 32-byte
single-display nonce through stdin and never invents a current Laravel user.
Result rows bind the complete tuple by composite FK and canonical fingerprint.
Republish uses distinct pre-start validation and one immutable post-start resume
fingerprint. Phase B0 validates that baseline only before the first physical
attempt; Phase B1 durably increments the attempt count/generation and commits a
token-bound reservation. A Redis-native atomic monotonic fence must then be
installed, and its one-use Function checks generation/token/payload while
consuming authority and publishing in one server operation. The local call-
boundary CAS is audit state, not the external fence. Unknown classification
requires Redis retirement first; after successor fence advancement, stale A is
rejected at Redis with zero publish effect and also cannot write a DB result. A
boundary failure action-stops the
republish without terminalizing and releases the target for a newly issued exact
action. The other four database-only actions commit start, their exact mutation
and compatible result together. Complete expired queued ownership has one legal
first mutation through `recover_expired_queued_ownership`; it cannot be cleared
before authorization. Before the 1,800-second response objective, authority may
permit bounded same-key recovery; afterward an unstarted republish is forbidden,
a started republish action-stops, and only a newly issued exact fail-closed
terminalization action may terminalize. The dry-run-first publication-
mismatch and abandoned-processing apply commands also require their exact
action-specific authorization and protected nonce. Queue-delivery, logical-processing and
outbox-publication attempts remain separate. A successful eighth publication is
valid; a failed or ambiguous eighth publication closes only the republish result
and a separate terminal authorization performs terminalization; no ninth attempt
exists. Processing requires the canonical
outbox to be `published`; recovery must complete
authorized `recovery_required -> published` acknowledgement before a handler can acquire
ownership. Live-owner finalization requires the raw token and owned Redis lock;
the separate abandoned-owner API uses a new supplier lock and an expired
persisted `processing/published` tuple without the lost raw token. Final evidence, ImportHistory, claim, published outbox, and
authoritative parent transitions share one fixed-order database transaction.
Exact `ascii`/`ascii_bin` lowercase-hexadecimal checks, byte-exact immutable
`execution_path` values,
`uq_import_execution_claim_run`, the exact
`chk_import_execution_claim_path_parent` parent-shape check, retained one-job
uniqueness, a separate named three-column child FK index, a common supplier
lock and a behavior-equivalent streaming traversal are required. The selected
future append-only source-execution/revision design must prove immutable
original-source provenance for every application candidate before source work;
supplier/feed equality alone cannot
authorize membership, including after one feed ID changes from source A to
source B. Immutably proven exact-source-only additions are the only later
expansion, and
finalization never rereads mutable membership. An authorized expansion makes
its complete enrollment generation the new non-comparable baseline; only a
deterministic authorization mismatch emits `capture_cohort_changed` and freezes.
The dedicated import worker adds no import or automatic-recovery schedule; the
only planned automatic cadence is the watchdog monitor and independent health
observer, neither of which mutates supplier/catalog domain state. Phase I's
persistence schema, including claim, outbox, monitor/alert, recovery-
authorization/result, cohort-member, generation, enrollment, and observation
tables and migrations, is implemented, merged, CI-verified, and deployed to
staging. It remains behaviorally inactive: later runtime claim/outbox/recovery
execution is unwired, and no watchdog service, recovery repository, command,
parser change, capture implementation, historical backfill, or evidence
producer exists. Phase II's guarded models/canonical byte contracts are also
implemented, merged, CI-verified, deployed to staging, and uncalled. Phase
III-P0 Slice 1's P0-01/P0-02 source-profile foundation is implemented through
PR #219, CI #478 verified, deployed at
`30b05f4aaacad38f3c6f4b782a5d90004c8740ff`, and dormant. Snapshot persistence
remains unimplemented and not implementation-authorized.
<!-- phase-iii-architecture-status-reference authority=phase-iii-architecture-contract-v1 -->
The exact current readiness map is owned only by the
[canonical Phase III architecture contract](IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md#phase-iii-provenance-and-bounds-architecture-decision).
This preview does not mirror that map. The exact next item is Phase III-P0
Slice 2, limited to P0-03 and the immutable source-execution/resolved-context
foundation. It is defined but not implementation-authorized; P0-04 through
P0-09 remain unsliced and unauthorized. All ten numeric production bounds
remain `NOT SPECIFIED`. C3D.1 remains blocked
until the canonical remaining gate, the fine-grained checkpoints below, and
future qualified warm-up complete. Supplier #3 remains unselected and
unstarted. No evidence candidate exists and no operational preview is
authorized.

## Immutable Persistence Rollout Checkpoints

The sole canonical rollout sequence is the
[103-row fine-grained checkpoint matrix](IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md#fine-grained-rollout-checkpoints).
Across all five PR chains it separately controls candidate/implementation,
validation, independent review, remediation-or-not-required, fresh independent
PASS, push authorization, push, exact remote-SHA
verification, Draft PR creation, PR base/head verification, CI,
merge authorization, merge, implementation, monitor design approval,
implementation authorization, local validation, security/database review,
remediation/re-review, monitor CI
and review, merge, disabled monitor deployment, explicit monitor/sink/observer
enablement, post-deployment
verification, enablement, every import, producer work, candidate preparation,
human approval, one preview, result review and documentation closeout. No row
combines review with merge, merge with deployment, candidate creation with
approval, approval with preview, or result review with closeout. A failed row
blocks every later checkpoint.

## Command

```powershell
php artisan suppliers:preview-apcom-offer-lifecycle `
    --supplier=apcom `
    --evidence=C:\safe\synthetic-evidence.json `
    --expected-sha256=<64-lowercase-hex> `
    --evaluated-at=2026-08-12T12:00:00+03:00 `
    --format=json `
    --limit=20 `
    --no-interaction `
    --no-ansi
```

Only a regular local file is accepted. Remote locations, network paths, stream
wrappers, symbolic links, malformed JSON and a SHA-256 mismatch fail closed.
The reader opens the file once, verifies the opened regular-file identity,
reads at most 8 MiB in bounded chunks and incrementally hashes those exact
captured bytes. Only the bytes that produced the pinned SHA-256 digest are
parsed. The command does not contain apply, persist, import, sync, link,
schedule, cleanup, fetch or download controls.

`evaluated_at` is mandatory and must be an ISO-8601 timestamp with `Z` or an
explicit numeric offset. Policy evaluation never uses the implicit system
clock.

## Evidence Contract

The immutable input schema is:

```text
supplier-offer-lifecycle-operational-evidence-v1
```

It includes:

- the exact policy-version set and supplier scope;
- stable exact decoded source identity under the current bounded V1 validator;
- an explicit per-supplier approved freshness policy;
- ordered capture and authoritative snapshot timestamps;
- unique snapshot fingerprints and record-count/drop evidence;
- successful/full/schema-valid/truncation and fatal-integrity evidence;
- supplier identity and comparability evidence;
- exhaustive exact offer-presence observations represented only by
  domain-separated SHA-256 identities;
- optional explicit continuous-zero-active-offer evidence for a catalog
  Product represented by its domain-separated hash.

Source identities are compared as exact decoded JSON strings. Case,
leading/trailing whitespace and Unicode representation are preserved; no
case-folding, trimming or Unicode normalization is applied before the
per-supplier stability check. Reader, direct bundle construction and the
per-supplier identity map share one fail-closed validator: identities must be
valid UTF-8, non-empty, not Unicode-whitespace-only and no longer than 128
Unicode code points. Validation returns the original string unchanged.

The future persistence producer applies an additional snapshot-specific opaque
ASCII grammar to its own stored source identity. That stricter contract does
not alter the existing V1 reader validator. It rejects paths, URLs, controls,
whitespace and normalization while preserving the accepted value exactly.

The bundle has no fields for raw supplier SKU, EAN/GTIN, MPN, source paths or
raw source records. The current broad V1 source-identity validator does not by
itself classify path- or URL-shaped strings; future persisted evidence closes
that producer boundary through the stricter snapshot-specific grammar above.
`received_at`, `last_seen_at`, `updated_at`, current database presence and
implicit current time are not accepted as historical evidence.

Duplicate snapshot fingerprints remain valid immutable evidence but are
frozen by the existing qualification policy and are never counted twice.

JSON object keys must be unique within every object scope. Validation is
token-aware, decodes JSON string escapes before comparing keys and rejects
escaped-equivalent duplicates. Equal keys in separate sibling objects and
repeated array values remain valid.

Count, quantity, EOL and freshness fields are JSON integers with explicit
database/policy bounds. Drop percentages and prices are JSON integers or
canonical unsigned decimal strings with bounded precision and range. Float,
exponent, signed, whitespace-padded, ambiguous leading-zero, overflow and
over-precise representations fail closed. Product-drop qualification uses
exact normalized decimal-string comparison rather than binary floating point.

## Report Contract

The report schema is:

```text
supplier-offer-lifecycle-operational-preview-v1
```

It contains the evidence and catalog-state fingerprints, ordered snapshot
fingerprints, policy versions, explicit evaluation time, qualification and
offer/product aggregate counts, recommendation and reason-code counts,
bounded hashed samples, the evaluation-only gate, the existing retention plan,
Catalog Sync safety flags, `persisted=false`, `write_allowed=false`, zero
records-changed counters and zero dispatched-job/event counters.

Rows are stable-sorted and canonical JSON output is deterministic for the same
evidence, `evaluated_at` and catalog state. No raw supplier identifiers, source
path, URL, credentials or complete records are emitted.

## V4 Semantics

- One APCOM absence is not EOL. Three consecutive qualified missing snapshots
  and at least 48 elapsed hours may emit `would_deactivate_offer` for the APCOM
  offer only.
- The future persistence baseline is a comparison anchor, not one of those
  three V4 snapshots. Under the current V1 `comparable=true` requirement, the
  minimum immutable sequence is one qualified baseline followed by three
  qualified comparable absences; the 48-hour clock starts at the first of the
  three comparable absences.
- Failed, partial, malformed, truncated, anomalous, duplicate, below-minimum,
  non-comparable or supplier-unconfirmed snapshots freeze tracking. A frozen
  snapshot neither increments nor resets the lifecycle.
- A qualified present offer with an unambiguous exact supplier SKU resets the
  missing count, first-missing timestamp and confirmed-missing state. Presence
  is independent of sellability: zero/invalid price and out-of-stock offers
  reset missing state but remain non-sellable review outcomes. Reactivation is
  stricter and requires a positive exact price plus valid mapping and no
  blocking issue or identifier conflict.
- A source-only row is `potential_create` in preview only. It cannot create,
  match, link or sync a Product.
- APCOM `partno` remains supplier SKU only. MPN is not inferred from it, EAN,
  names, descriptions or heuristics.
- APCOM `fd_price=0` is a present but non-sellable `manual_review` offer. It is
  not free, not missing and cannot start an archival timeline.
- APCOM evidence is fresh through 24 hours inclusive. Evidence older than 24
  hours is stale, excluded from sellable aggregation and classified for
  review, but it is not missing.
- APCOM freshness is not a supplier default. Another supplier requires its own
  explicit approved freshness policy in the evidence bundle; otherwise the
  evaluation fails closed to `manual_review`.
- One fresh valid alternative offer with `in_stock`, `limited`, `on_request` or
  `last_units` keeps the Product recommendation `keep_active`.

## Visibility And Protection

Visibility is recommendation-only. A zero-active-offer timeline is evaluated
only when the bundle explicitly proves continuous qualified absence and its
start timestamp. Otherwise the result is `manual_review` with
`unprovable_continuous_absence`.

The supported recommendations are:

- `keep_active`;
- `would_deactivate_offer`;
- `would_reactivate_offer`;
- `would_mark_unavailable`;
- `would_mark_archived_noindex`;
- `would_mark_cold_archive_candidate`;
- `manual_review`.

Day 0 through complete day 59 plans unavailable/non-purchasable behavior only;
day 60 plans HTTP 200 with `noindex, follow` and sitemap exclusion; 24 complete
months plans a cold-archive candidate. No Product, storefront, query, Scout,
sitemap, robots, noindex, URL, redirect, deletion or retention behavior is
implemented.

Manual Products, explicit manual overrides, unresolved maintenance ownership,
non-public workflows, deleted records, disabled suppliers and incomplete offer
coverage fail closed. Product content, workflow and relations remain untouched.

## Stable Reason Codes

Existing codes are reused where applicable. The orchestration adds only:

- `active_import_state`;
- `unknown_import_state`;
- `missing_supplier_freshness_policy`;
- `unprovable_continuous_absence`;
- `manual_product_excluded`;
- `manual_override`;
- `unresolved_manual_maintenance_protection`;
- `non_public_workflow_state`;
- `duplicate_offer`;
- `disabled_or_deleted_record`;
- `stale_snapshot`;
- `snapshot_not_comparable`;
- `snapshot_chronology_invalid`.

Input and safety gates also use stable validation codes documented by the test
contract, including evidence/schema/hash/source/scope/version/baseline errors.

## Safety Gate

The additive V4 runtime decision register and preview profile authorize only
evaluation. All catalog, staging, lifecycle, visibility, link, profile,
schedule, import, automatic-execution and Catalog Sync write permissions remain
false. V1, V2 and V3 remain available and unchanged as historical contracts.

At both evaluation boundaries the command requires:

- a matching active supplier scope;
- APCOM schedule disabled;
- import activity state `clear` for every scoped supplier;
- exact supplier snapshot count/drop baselines;
- `CATALOG_SYNC_CREATE_ENABLED=true` (informational only);
- `CATALOG_SYNC_UPDATE_ENABLED=false`;
- `CATALOG_SYNC_SYNC_ALL_ENABLED=false`;
- `CATALOG_SYNC_AUTO_ENABLED=false`.

Every real APCOM XML import enters `XmlImportEngine::import()`, which creates
one importer-owned, auto-increment `import_histories.id` generation record in
`started` state before feed reading or staging writes. The same row transitions
to `finished` (including `completed_with_errors`) or `failed`; its ID and
supplier scope do not change. Import History is list/view-only in Filament,
backend policy denies manual mutation, model guards reject unsupported
creation/update/delete, and supplier deletion cannot cascade generation
evidence away. No application prune or direct-delete path is supported.

The preview captures the per-supplier maximum history ID before evaluation.
After the immutable report has been fully assembled from captured values, the
final gate reloads supplier baselines, reruns import activity inspection,
compares the generation, then reloads and compares catalog/protected-state
fingerprints. There are no database, filesystem, relation or external-state
reads after that final fingerprint comparison. An import beginning later
cannot change the already captured report state; an import visible at or before
the final boundary aborts through activity, generation or fingerprint drift.
`completed_with_errors` is terminal/inactive for concurrency only and does not
make its evidence a qualified successful snapshot. Supplier queries and
fingerprint payloads are ordered by stable database ID.

Protected database counts and high-water timestamps are also fingerprinted
before and after evaluation. The report includes a reproducibility fingerprint
of the relevant supplier, staging, offer and Product state. Tests reject
mutation SQL and prove no HTTP request, job, queue, cache or storage write.

No supplier import, feed fetch, Catalog Sync, CREATE/UPDATE execution, Product
or `supplier_products` mutation, offer write, linking, schedule, cleanup, job,
event, migration or persistence is part of this implementation.

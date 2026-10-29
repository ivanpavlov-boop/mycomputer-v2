# P0-07 Staging Source Revision Pointers: Canonical Adoption Candidate

Status: **PROPOSED / UNAPPLIED - DOCUMENTATION CANDIDATE**.
This is proposed normative text for a separate review, not an adopted oracle,
implementation work order, migration authority or runtime activation.
Only this new document and the companion [synthetic evidence record](PHASE_III_P0_STAGING_SOURCE_REVISION_POINTERS_MYSQL_DESIGN_EVIDENCE_2026_10_23.md)
are proposed additions. Existing canonical sources and all historical records
remain unchanged.

## Authority and Boundaries

Baseline: `b2d7e0bb428745dfe9d0e39a6973fd34a5cf371c`, branch
`codex/p0-06-staging-p0-07-proposal-docs`. File-read HEAD/index tree is
`0bc87a434f09897a4ebfebeb39f8c0be4cf06b85`. Cached `origin/main` is
`0e2cf9bcd28d040df18696dd294f4554982e59e1`, not a fresh remote observation.
The new pair is external-only because this sandbox cannot write the sibling
worktree; repository application remains BLOCKED. There is no automatic
branch substitution, staging or source repair.

Authoritative current documents are [AGENTS](../AGENTS.md),
[canonical persistence design](IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md),
[runtime plan](PHASE_9C6_5C3D1_RUNTIME_IMPLEMENTATION_PLAN.md),
[Data Ownership](DATA_OWNERSHIP.md), [Supplier Import](SUPPLIER_IMPORT.md),
[Sync Safety](SYNC_SAFETY.md) and [Catalog Sync Safety](CATALOG_SYNC_SAFETY.md).
Proposed resolutions are transcribed from
[R2](PHASE_III_P0_STAGING_SOURCE_REVISION_POINTERS_D1_D3_RESOLUTION_PROPOSAL.md),
SHA-256 `f55ad48293f6ccb15ec8bf8116cdbce0f05ab4140cabe4823e93a3f2f6a39af0`.
R2 remains PROPOSED / UNAPPLIED. Its historical NOT RUN entries are not edited;
the new evidence record provides only the later bounded experimental result.

Coordinator/Documentation, Catalog Sync Safety, backend/QA and file-handling
Security are logical review lenses, not independent approvals. The requested
named workflow/safety skills are absent from the available skill inventory.
Repository rules apply. No catalog data, source staging, import/sync behavior,
flags, admin access or sessions are changed by this candidate.

## Proposed Normative Deltas

Line numbers below refer to the checked baseline. Each numbered delta contains
current authority, concrete proposed normative text, basis and a remaining
acceptance gate. The quoted text becomes normative only after separate adoption.

### D1. Legacy Compatibility and Byte-sensitive Bound Protection

Current authority:
[concrete schema oracle](IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md#concrete-phase-iii-p0-schema-oracle),
lines 4445 and 4761; [selected schema](IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md#selected-future-execution-logical-head-and-revision-schema),
lines 5985-5988. Proposed replacement/addition:

> A legacy SupplierProduct with both source pointers NULL remains writable by
> the existing staging path, subject to inherited constraints, and remains
> ineligible for protected admission. The pair-only CHECK is unchanged. INSERT
> and UPDATE guards reject a pointer-bearing row with NULL supplier/feed scope.
> UPDATE rejects half-pairs, clearing a non-NULL revision, or replacement of a
> bound head/supplier/feed. With a bound unchanged revision, guarded strings
> are compared byte-sensitively using binary NULL-safe comparisons, and typed
> fields by NULL-safe stored-value comparison. Workflow-only/no-op changes
> remain allowed. An authorized transition must validate the full new revision,
> product, head, supplier, feed and binary SKU tuple.

Guarded strings remain exactly supplier_sku, ean, mpn, name, brand_name,
category_name, external_availability_status, external_availability_label,
currency and payload_hash. Typed fields remain price, supplier_price_raw,
recommended_price, quantity, availability_status_id, raw_data and received_at.
Stored JSON/decimal equality is not preservation of original lexical bytes.
Keep strict sql_mode, cp866/cp866_general_ci creation session and environment-
derived database collation; no CI default substitution.

Basis: frozen D1/D3 cases, R2 legacy/byte/no-op/workflow and scope cases in the
companion evidence matrix. Gate: independently authored trigger signature and
literal golden tests; full-schema negatives under both database defaults and
unchanged application import/preview/selected-sync regressions.

### D2. Constructible Ownership and Atomic Full-pair Publication

Current authority: [selected schema](IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md#selected-future-execution-logical-head-and-revision-schema),
lines 5937-5958 and 5975-5988; oracle lines 4650, 4713 and 4736.

> The revision's reverse product ownership is the existing tuple
> (product, supplier, feed), not (product, head, supplier, feed). Its head and
> execution ownership remain non-null and exact. A writer may insert a valid
> scoped revision while the parent is still NULL/NULL, then atomically publish
> the complete (head, revision) pair with source fields and attributes inside
> its owning transaction. The forward current-revision FK still binds the full
> (revision, product, head, supplier, feed) tuple. No half-pair statement,
> disabled constraint, deferred-FK assumption or permanent intermediate pair
> is permitted. Compatible existing P6 revisions are preserved, not backfilled
> or treated as proof of historical publication.

Basis: frozen D2 failures and initial/revision CAS=1 in the reduced graph.
Gate: full P6 population preservation, independent exact P7 signatures, original
column/timestamp equality, no new catalog authority, and guarded real DDL.

### Publication History Is Not Reuse Authority

Current authority: [import/retry rules](IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md#import-retry-and-concurrency-rules),
lines 6118-6120 and 6137-6148; [admission](IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md#exact-candidate-admission-contract),
lines 6171-6204.

> Structural validity or an execution/head unique match does not establish
> publication history, admission eligibility or permission to republish.
> A non-current revision can be superseded history or never-published evidence;
> pointer absence cannot distinguish them. Reuse requires an independently
> authorized protocol proving exact ownership, predecessor and byte-identical
> provenance/projection. Without it, fail conflict. Same-revision idempotence
> is a verified no-write result, not an UPDATE that happens to affect zero rows.

Basis: G-noncurrent-revision, G-wrong-semantic-digest and
G-direct-republish-old are allowed by SQL in the run. Gate: future protected
writer/admission tests with immutable receipts/executions and canonical recipes;
those services are not implemented here.

### Committed Unbound Heads and Existing-head Lock Order

Current authority: [import/retry rules](IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md#import-retry-and-concurrency-rules),
lines 6123-6157, and [concurrency matrix](IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md#concurrency-decision-matrix),
line 6514.

> Allocate one new logical key INSERT-first within a caller-owned row
> transaction. Catch only its exact logical-key duplicate, then lock/re-read
> the committed winner. A pre-existing committed head without its unique valid
> product binding is corrupt: fail closed, without takeover, repair, deletion
> or cleanup. A newly created head may be bound only in its creating transaction.
> For multiple existing heads acquire locks in ascending head ID, then resolved
> products in ascending product ID, then attributes by parent ID/attribute ID
> with the indexed parent range. Do not acquire another head after those child
> locks. Do not substitute supplier/feed/SKU lexical ordering. Mixed batches of
> new and existing heads require a separately complete acquisition protocol.

> A genuine transition uses the exact expected-old head/revision CAS and must
> affect one row. Stale CAS=0 is conflict, not idempotent success. This R2
> protocol chooses the conflict branch, not automatic replay against a newer
> predecessor. Participants never commit/rollback independently or retry locally.
> Connection loss, deadlock, timeout and uncertain commit outcome propagate
> and stop; no rollback or successful publication is inferred from disconnect.

Basis: real B-to-A wait, exact 1062/23000, winner tuple and loser stale-zero;
known whole-unit rollback. Gate: production acquisition protocol and real
multi-key/uncertain-outcome acceptance. The synthetic run does not exercise all
those cases and does not authorize an importer caller.

### D3. SQL Structure Versus Semantic Projection and Caller CAS

Current authority: [selected schema](IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md#selected-future-execution-logical-head-and-revision-schema),
lines 5983-6013.

> SQL guarantees the complete pair, exact structural ownership, non-null bound
> scope, unique head assignment, immutable revision rows and specified direct
> source-update guards. It does not recompute canonical semantic fingerprints,
> intercept every independent attribute/FK action, or know a caller's
> expected-old predicate. The writer validates source, attributes, receipt,
> execution and exact versioned bytes before publication. Admission independently
> recomputes the canonical projection in its defined view. Any mismatch fails
> closed without repair, fallback to current feed configuration or invented
> history. The fixed canonical field order, versions and fingerprint recipes
> remain unchanged; toy experimental hashes are not their oracle.

Basis: gap demonstrations and SET NULL mismatch. Gate: independent literal
fingerprint golden fixtures, full provenance closure and runtime implementation
tests. Neither synthetic DDL output nor implementation output may generate
the expected canonical oracle used to judge that same implementation.

### One Capture-start Snapshot and Authorization Coordination

Current authority: [admission](IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md#exact-candidate-admission-contract),
lines 6169-6204; [claim source binding](IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md#durable-five-field-claim-source-binding),
lines 6233-6240; capture timing line 3638 and concurrency line 6505.

> Admission starts one REPEATABLE READ transaction WITH CONSISTENT SNAPSHOT at
> capture start before enumeration/download. Every source pointer, revision,
> execution, receipt, projection, relevant attribute and mutable lookup comes
> from that cut, or an explicitly immutable version authority. Writer current
> reads and later claim/fence locks do not refresh that source cut. Never mix
> a T0 revision with T1 attributes/lookups. A pointer committed later does not
> invalidate a valid T0 view or authorize use of the newer source values.
> Persist the validated member set and complete claim tuple atomically under
> existing coordination. After successful authorization, retries verify/use
> persisted members and immutable evidence, not current staging recapture.
> A pre-commit retry may establish a new transaction; an uncertain outcome
> requires reconciliation, never blind replay.

Basis: import snapshot T0=T2=A while current=B. Gate: future application
authorization/member persistence with full provenance and retry tests; the
synthetic view does not implement claim coordination.

### SET NULL and Semantic Mismatch

Current authority: inherited FK signatures in the
[schema oracle](IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md#concrete-phase-iii-p0-schema-oracle),
lines 4653-4656, plus [admission](IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md#exact-candidate-admission-contract).

> Preserve supplier CASCADE, feed SET NULL, availability SET NULL and catalog
> product SET NULL with existing ON UPDATE NO ACTION. Do not credit the child
> UPDATE trigger with intercepting cascaded FK actions. An availability SET
> NULL committed before T0 is visible with the old revision and must fail
> admission on projection mismatch. After T0 it leaves the existing view
> unchanged; that matching T0 revision can qualify only if all other admission
> rules hold. A newer logical cut must detect the mismatch. Catalog product_id
> is workflow-only, not by itself a source projection input. Protected feed
> scope remains restricted by the combined head/revision graph.

Basis: before/after snapshot fixtures and inherited-action assertions. Gate:
full-schema and complete canonical projection tests, including lookup/attribute
semantics; no new destructive supplier operation is authorized.

### Dormant Foundation Is Not Writer or Admission Activation

Current authority: [runtime allocation](PHASE_9C6_5C3D1_RUNTIME_IMPLEMENTATION_PLAN.md#future-phase-iii-p0-provenance-migration-allocation),
P0-07 row, and [R2 responsibility matrix](PHASE_III_P0_STAGING_SOURCE_REVISION_POINTERS_D1_D3_RESOLUTION_PROPOSAL.md#d3-responsibility-matrix).

> Canonical adoption and a later exact P6-to-P7 dormant schema coordinator are
> separate approvals. Neither installs protected writer/admission callers,
> importer adapters, jobs, service bindings, public write endpoints, capture or
> feature activation. Forward migration performs no binding/backfill/rehash.
> UPDATE, Sync All and automatic sync remain disabled; manual catalog ownership
> and existing active Super Admin/access safeguards remain unchanged.

Gate: independently reviewed canonical literals first; separately authorized
foundation work and A1-A12 afterward; runtime writer/admission approval later.

## Exact R2 Registry and Down Proposal

Source: [R2 registry/down section](PHASE_III_P0_STAGING_SOURCE_REVISION_POINTERS_D1_D3_RESOLUTION_PROPOSAL.md#exact-proposed-registry-and-downgrade-delta).
The existing frozen oracle remains untouched. Four signatures change, one new
INSERT trigger is added. The pair-only CHECK signature does NOT change.

Use exact object sets below, not counts as authority. `S` is the complete
existing frozen P6 object set (339 objects, including its literal signatures).
`R` consists only of these two original P6 objects:

```text
foreign_key:supplier_product_source_revisions:fk_supplier_product_revision_product_id
index:supplier_product_source_revisions:ix_supplier_product_revision_product_id_fk
```

The proposed additions/replacements `N` have exactly these 13 object IDs:

| Object ID | Proposed signature treatment | Membership beyond successor suffixes |
| --- | --- | --- |
| `trigger:supplier_products:trg_supplier_product_source_pointer_insert_guard` | NEW BEFORE INSERT nullable-scope guard | P7 only |
| `trigger:supplier_products:trg_supplier_product_source_projection_guard` | CHANGED ordered UPDATE guard from R2 D1 | P7, P7_DOWN_1 |
| `foreign_key:supplier_product_source_revisions:fk_supplier_product_revision_product_scope` | CHANGED (product,supplier,feed) -> (id,supplier,feed), RESTRICT/RESTRICT | P7, P7_DOWN_1, P7_DOWN_2 |
| `index:supplier_product_source_revisions:ix_supplier_product_revision_product_scope_fk` | CHANGED ordered (product,supplier,feed), nonunique BTREE | P7, P7_DOWN_1, P7_DOWN_2 |
| `index:supplier_products:uq_supplier_product_source_revision_scope` | CHANGED ordered (id,supplier,feed), unique BTREE | P7 and all three P7_DOWN states |
| `check:supplier_products:chk_supplier_product_source_revision_pointers` | UNCHANGED pair-only enforced CHECK | P7 and all three P7_DOWN states |
| `column:supplier_products:027:supplier_product_identity_head_id` | UNCHANGED frozen nullable unsigned bigint | P7 and all three P7_DOWN states |
| `column:supplier_products:028:current_source_revision_id` | UNCHANGED frozen nullable unsigned bigint | P7 and all three P7_DOWN states |
| `foreign_key:supplier_products:fk_supplier_product_current_revision` | UNCHANGED full (r,p,h,s,f) tuple, RESTRICT/RESTRICT | P7 and all three P7_DOWN states |
| `foreign_key:supplier_products:fk_supplier_product_identity_head` | UNCHANGED (h,s,f), RESTRICT/RESTRICT | P7 and all three P7_DOWN states |
| `index:supplier_products:ix_supplier_product_current_revision_fk` | UNCHANGED ordered (r,id,h,s,f) | P7 and all three P7_DOWN states |
| `index:supplier_products:ix_supplier_product_identity_head_fk` | UNCHANGED ordered (h,s,f) | P7 and all three P7_DOWN states |
| `index:supplier_products:uq_supplier_product_identity_head_assignment` | UNCHANGED unique h | P7 and all three P7_DOWN states |

Here p/h/r/s/f are notation only, not replacement identifiers. The four changed
signatures are the reverse FK, its supporting index, product unique scope index
and UPDATE guard. Every N object also belongs to exactly these successor states:
`P8`, `P8_DOWN_1`, `P8_DOWN_2`, `P9`, `P9_DOWN_1`, `P9_DOWN_2`.
For P7 and its three proposed DOWN states only, `S` minus R is inherited with
its exact P6 signatures. R is absent through P7_DOWN_2 and restored in
P7_DOWN_3 with its original P6 signatures. The S-based equations below do not
define any P8/P9 successor. No P0-P6 membership, signature or accepted hash
changes. Inherited/base objects gain only the required new intermediate-state
membership for the proposed P7 down sequence.

For each successor X, let `B_X` be that SAME state's complete frozen baseline
map from object ID to its full literal signature, as recorded in the pinned
oracle at `b2d7e0bb428745dfe9d0e39a6973fd34a5cf371c`. Do not use P6 as B_X:

| Successor X | Frozen baseline map B_X |
| --- | --- |
| P8 | P8 |
| P8_DOWN_1 | P8_DOWN_1 |
| P8_DOWN_2 | P8_DOWN_2 |
| P9 | P9 |
| P9_DOWN_1 | P9_DOWN_1 |
| P9_DOWN_2 | P9_DOWN_2 |

Let `C` contain exactly these four replacement IDs, already present in each B_X:

```text
trigger:supplier_products:trg_supplier_product_source_projection_guard
foreign_key:supplier_product_source_revisions:fk_supplier_product_revision_product_scope
index:supplier_product_source_revisions:ix_supplier_product_revision_product_scope_fk
index:supplier_products:uq_supplier_product_source_revision_scope
```

For each X, the proposed map is `B_X` with ONLY C's signatures replaced by their
R2 proposals above, plus the NEW object
`trigger:supplier_products:trg_supplier_product_source_pointer_insert_guard`.
Every object outside C retains its exact B_X signature and membership; no other
object is added or removed. Unrelated variant memberships across the six states
remain exactly frozen. This includes state-specific CHECK variants: equal object
IDs or equal counts do NOT prove equal signatures. R stays absent in successors
because it is absent in each B_X, not because the successors inherit S minus R.

Counterexamples verified against the pinned oracle's full literal signatures:

| CHECK name | P6 variant | P8 / P8_DOWN_1 variant | P8_DOWN_2 variant | P9 / P9_DOWN_1 / P9_DOWN_2 variant |
| --- | --- | --- | --- | --- |
| chk_import_claim_cohort_authorization_tuple | @0f8a729c49a26917 | @68932313f061c210 | @68932313f061c210 | @68932313f061c210 |
| chk_import_claim_processing_owner | @41ea20110e74a67d | @f318c32cb7301601 | @f318c32cb7301601 | @152f2f87b26014c0 |
| chk_import_claim_processing_time_order | @094afba8b5ecbc5a | @7a98517f4cc7ae01 | @7a98517f4cc7ae01 | @7a98517f4cc7ae01 |
| chk_snapshot_generation_qualification_tuple | @08e50d9848873b9d | @e49bfc1ee14ed90c | @08e50d9848873b9d | @e49bfc1ee14ed90c |

These suffixes identify existing registry variants, not complete SHA-256 values
or replacement goldens. Compare the full literal signature, not a suffix alone.
In particular, P8_DOWN_2 retains its original generation-qualification variant;
substituting the P8/P9 variant there is also invalid. The external verification
compares each unaffected full record and rejects P6 CHECK substitutions without
changing IDs or counts. This is proposal verification only: it does not generate
new canonical hashes, author replacement production goldens or adopt the maps.

Let I/U be the INSERT/UPDATE trigger object IDs above, and Q be the reverse
scoped FK plus its supporting index. The exact P7/down equations and P6 endpoint are:

| Proposed state | Exact set | Count (cross-check only) |
| --- | --- | ---: |
| P7 | (S minus R) union N | 350 |
| P7_DOWN_1 | (S minus R) union (N minus I) | 349 |
| P7_DOWN_2 | (S minus R) union (N minus I minus U) | 348 |
| P7_DOWN_3 | S union (N minus I minus U minus Q) | 348 |
| P6 | S, exactly the existing frozen registry | 339 |

Four separately recorded down operations, not three:

1. `P0-07-DOWN-01`: DROP only I. P7 -> P7_DOWN_1.
2. `P0-07-DOWN-02`: DROP only U. P7_DOWN_1 -> P7_DOWN_2.
3. `P0-07-DOWN-03`: one atomic ALTER on revisions replaces Q with R.
   P7_DOWN_2 -> P7_DOWN_3.
4. `P0-07-DOWN-04`: one atomic ALTER on products removes exactly the two
   product FKs, pair CHECK, four product indexes and two columns in N.
   P7_DOWN_3 -> P6.

Two DROP TRIGGER statements are not one atomic DDL operation. Each operation
requires separately authored pre/result hashes, exact affected signatures and
next_operation. Old P7_DOWN_1/2 names cannot retain their old digests after their
memberships change. P7_DOWN_3 is new. Proposed successors: P8=356,
P8_DOWN_1=355, P8_DOWN_2=353, P9=369, P9_DOWN_1=368, P9_DOWN_2=362.
Proposed totals: 376 object signatures, 18 states, 16 down operations.
These remain R2 design arithmetic, not accepted canonical hashes.

P6 stays `ea265e4717c2f61abba6731f08be576ff6fcd01fe6b673d61d41011a888056aa`.
No new accepted hash is supplied here or derived from synthetic DDL. The
canonical oracle and literal golden object/state/down expectations must be
independently authored and reviewed together. Counts, metadata normalization
or self-generated expectations are not substitutes for exact membership.

Forward prerequisites retain exact P6, live session/ownership guards, inbound
dependency checks and an independent check that every populated revision's
product exists and supplier/feed match NULL-safely before any DDL. Compatible
populated P6 evidence survives; no provenance is invented from it. Down remains
pristine all-null terminal/exact-declared-partial only, with no incompatible
suffix or inbound dependencies. Every partial continuation needs fresh exact
authority. Uncertain DDL/session outcomes stop without automatic repair/retry.

## Separate Future Allowlists

Both lists are copied from R2 and compared in order. Neither is an edit
permission in this task. List A includes PHP oracle/tests and therefore exceeds
the current two-document scope. List B is a later, separately authorized phase.

### A. Canonical Adoption

1. `docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md`
2. `docs/PHASE_9C6_5C3D1_RUNTIME_IMPLEMENTATION_PLAN.md`
3. `docs/PHASES.md`
4. `docs/PHASE_III_P0_SOURCE_REVISION_FOUNDATION_PROPOSAL.md`
5. `docs/PHASE_III_P0_STAGING_SOURCE_REVISION_POINTERS_FOUNDATION_PROPOSAL.md`
6. `docs/PHASE_III_P0_STAGING_SOURCE_REVISION_POINTERS_D1_D3_RESOLUTION_PROPOSAL.md`
7. `database/migrations/support/CanonicalSupplierPhaseThreeP0Oracle.php`
8. `tests/Unit/Suppliers/SourceProfiles/CanonicalSupplierPhaseThreeP0OracleTest.php`
9. `tests/Feature/SupplierOfferLifecycleDocumentationContractTest.php`

### B. Dormant Foundation Implementation

1. `database/migrations/2026_08_28_090006_add_source_revision_pointers_to_supplier_products_table.php` (NEW)
2. `database/migrations/support/CanonicalSupplierPhaseThreeP0Schema.php`
3. `tests/Feature/PhaseThreeP0StagingSourceRevisionPointersFoundationMysqlTest.php` (NEW)
4. `tests/Feature/SupplierOfferLifecycleDocumentationContractTest.php`
5. `tests/Feature/PhaseThreeP0SliceOneMysqlTest.php`
6. `tests/Feature/PhaseThreeP0SliceTwoMysqlTest.php`
7. `tests/Feature/PhaseThreeP0SliceThreeMysqlTest.php`
8. `tests/Feature/PhaseThreeP0SliceFourMysqlTest.php`
9. `tests/Feature/PhaseThreeP0SourceRevisionFoundationMysqlTest.php`

Inspector/comparator/enum remain reuse-only dependencies. Any needed change
outside these future lists requires a new exact scope. No list authorizes
importer, protected writer, admission, capture, service binding or feature flag.

## Proposed Current-status Reconciliation for P0-06

The actual checked source contains
[`2026_08_28_090005_create_supplier_product_source_revisions_table.php`](../database/migrations/2026_08_28_090005_create_supplier_product_source_revisions_table.php),
which dispatches guarded P0_06 forward/down, and the immutable read/hydrate-only
[`SupplierProductSourceRevision`](../app/Models/SupplierProductSourceRevision.php).
The [staging record](PHASE_III_P0_SLICE_5_STAGING_EVIDENCE_2026_10_20.md#migration-and-data)
attributes exact P6 to the owner-executed 2026-10-20 deployment. That document's
SHA-256 is `bd26517319aea3fa8fbfc481cff73208a456ff48f1f7d2a7b1019d84f88555dc`.
This task checks implementation presence and the attributed record, not current
VPS health or a new raw VPS/database observation.

Separately propose replacing only current-status claims that P0-06 remains
unimplemented: runtime plan lines 60-62, 621-623 and 1358-1361; PHASES line 233
and adjacent status; P0-06 proposal opening/closing status; canonical current
status around lines 3773-3775, 5190-5191 and the Slice 5 authority checkpoint.
Do not rewrite the historical design-definition/authorization checkpoint as
though it had already granted implementation permission at the time.

> P0-06 schema/read-model foundation is IMPLEMENTATION_PRESENT_DORMANT at
> b2d7e0bb428745dfe9d0e39a6973fd34a5cf371c. The owner-executed staging record
> dated 2026-10-20 reports exact P6. Deferred P0-06 writer/integration and
> P0-07 through P0-09 implementation/activation are separate, unperformed
> phases. Presence and historical deployment do not authorize runtime use.

The current canonical design, runtime plan, PHASES, proposals, oracle and tests
are NOT edited by this task. Review the quoted proposal with their strict
documentation contracts before any later adoption.

## Remaining Acceptance Gates

The new synthetic evidence supports, but does not replace, full-schema A1-A12
as amended in R2: actual migration discovery, populated preservation,
constructible pair and separate INSERT/UPDATE negatives, inherited actions,
both database defaults, four down operations/three partial boundaries,
connection/uncertain outcomes, real application regressions and independent
golden contracts. Existing canonical fingerprints require their own literal
tests; toy projection success is not their acceptance.

No new tests were run here. Offline evidence and document validation are
recorded separately. PHP documentation-contract execution is NOT RUN because
the checked worktree lacks its PHP/vendor prerequisites. No dependencies were
installed or borrowed. Historical failures and the old observer's unknown cause
remain preserved; they do not erase the narrower successful synthetic result.

Next single step: independent review of this documentation candidate, not
automatic canonical adoption or foundation implementation.

CANONICAL ACCEPTANCE / ADOPTION: NOT PERFORMED.
P0-07 IMPLEMENTATION / RUNTIME ACTIVATION: NOT PERFORMED.
NEW DOCKER / MYSQL EXECUTIONS: 0.
REPLAY / CLEANUP / VPS ACTIONS: NONE.
COMMIT / PUSH / PR / MERGE: NOT PERFORMED.

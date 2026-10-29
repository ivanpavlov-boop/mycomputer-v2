# P0-07 Staging Source Revision Pointers: D1-D3 Resolution Proposal

## Candidate reconciliation — 2026-10-26

2026-10-26 isolated canonical candidate: the five-document design package is OWNER APPROVED. New P7/successor oracle literals are CANDIDATE — NOT OWNER APPROVED. Original-worktree adoption and P0-07 implementation/runtime activation are NOT PERFORMED.

D1-D3/R2 decisions are accepted as design inputs. Their exact candidate
transcription now appears in the sole [canonical authority](IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md#p0-07-d1-d3r2-candidate-reconciliation-2026-10-26).
The original PROPOSED/UNAPPLIED and NOT RUN statements below record the R2
checkpoint; they do not claim execution of this new patch. New literal hashes
still require review. Compatible populated P6, four down operations, three
partial boundaries and each successor own B_X supersede the older foundation.
A2/A8/A9 and coordinator scope follow that correction, without implementing B.

## Original R2 checkpoint (historical)

Status: **PROPOSED / UNAPPLIED - R2**.

This corrects R1 findings F1-F5. It is not canonical adoption, implementation,
runtime authority, independent acceptance, or a permission to run a migration.
MySQL design proof is NOT RUN. The one Engine check failed before provisioning
with a missing desktop-linux pipe. No existing database was contacted.

## Baseline and Preservation

Branch: `codex/p0-06-staging-p0-07-proposal-docs`.
HEAD: `b2d7e0bb428745dfe9d0e39a6973fd34a5cf371c`.
Read-only remote-main inspection on 2026-10-21 returned this same commit.
Cached `origin/main` is `0e2cf9bcd28d040df18696dd294f4554982e59e1`;
it is not the current remote observation. No fetch or ref update was performed.
The index is empty. This is the only repository file changed from R1.

The two other untracked documents remain byte-identical (+541/-0 together):

| Document | Bytes | SHA-256 |
| --- | ---: | --- |
| [Staging evidence](PHASE_III_P0_SLICE_5_STAGING_EVIDENCE_2026_10_20.md) | 11786 | `bd26517319aea3fa8fbfc481cff73208a456ff48f1f7d2a7b1019d84f88555dc` |
| [Foundation proposal](PHASE_III_P0_STAGING_SOURCE_REVISION_POINTERS_FOUNDATION_PROPOSAL.md) | 22099 | `20d5cc46585fd12a34b1eb14548088001f80c83cb574bf5c4247374d1c9137d8` |

R1 of this document is preserved externally as `r1/proposal.md`, SHA-256
`927c6ef0d480982732ebd6d988b92037da3afc5fe823a851f1257bcda9b58e94`.
Historical R1 reports, runner and nine SQL scripts are retained, not rewritten
or relabeled as R2 execution. Their earlier conclusions are superseded only
where this document says so. No logical role is an independent approval.

## Authority and Source Map

Line numbers refer to the pinned baseline, not future modified canonical files.

| Source | Relevant contract |
| --- | --- |
| [AGENTS](../AGENTS.md), [agent rules](AI_AGENTS.md) | Scope and authority; requested named workflow/safety skills unavailable |
| [Canonical design](IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md) | 4445 pair CHECK; 4650-4656 FKs; 4713/4736 indexes; 4761 UPDATE trigger; 4789-4792 state registry; 4889-4891 down operations |
| Same canonical design | 5233-5235 no committed unbound head; 5937-6013 pointer ownership, SQL claims and fixed fingerprint recipes |
| Same canonical design | 6116-6167 Import/retry/concurrency; 6149-6153 corrupt unbound head and ascending head-ID locks |
| Same canonical design | 3638 capture-start view; 6169-6204 admission; 6233-6240 authorization/member persistence; 6505/6514 snapshot timeline and lock order |
| [Runtime plan](PHASE_9C6_5C3D1_RUNTIME_IMPLEMENTATION_PLAN.md) | P0-07 foundation separate from writer/admission activation |
| [P0-06 proposal](PHASE_III_P0_SOURCE_REVISION_FOUNDATION_PROPOSAL.md) | Non-null immutable revisions, dormant foundation, predecessor preservation |
| [Data Ownership](DATA_OWNERSHIP.md), [Supplier Import](SUPPLIER_IMPORT.md), [Sync Safety](SYNC_SAFETY.md), [Catalog Sync Safety](CATALOG_SYNC_SAFETY.md), [Testing](TESTING.md) | Staging-only imports, manual content protection, isolated tests |
| `database/migrations/support/CanonicalSupplierPhaseThreeP0Oracle.php` | Frozen independent object/state/down registries, not generated from actual DDL |
| `database/migrations/support/CanonicalSupplierPhaseThreeP0Schema.php` | Exact initial/partial states, transaction/session ownership, dispatch currently through P0-06 |
| `database/migrations/support/CanonicalSupplierPhaseThreeP0SchemaComparator.php` | Exact signature comparison, not a permissive subset |
| `tests/Unit/Suppliers/SourceProfiles/CanonicalSupplierPhaseThreeP0OracleTest.php` | Literal golden contract and classification |
| `tests/Feature/SupplierOfferLifecycleDocumentationContractTest.php` | Closed-world document/registry expectations, no weakening |
| `tests/Feature/PhaseThreeP0SourceRevisionFoundationMysqlTest.php` | Predecessor scope, immutable evidence and both database defaults |
| `app/Services/Suppliers/ControlledSupplierStagingImportService.php` | Existing source fill/save/create, no pointer publication |
| `app/Services/Imports/XmlImportEngine.php` | Parent updateOrCreate and separate staged attribute deletion/recreation |
| `app/Services/Attributes/AttributeNormalizationService.php` | Independent raw/normalized attribute mutations |
| `app/Services/Products/ProductSyncService.php` | Workflow fields and separately relevant attribute changes |
| `app/Data/Suppliers/Imports/SupplierProductLogicalKey.php`, `app/Repositories/Suppliers/SupplierProductIdentityHeadRepository.php` | Raw validation/trim, INSERT-first, exact duplicate handling; caller-owned transaction |

R2 uses documentation/coordinator, backend, QA, Catalog Sync Safety and security
as logical review lenses only. No runtime agents or independent approvals exist.

## Decision and Notation

`p/h/r/s/f` mean staging product, head, revision, supplier and feed IDs.
Recommend revision ownership of existing `(p,s,f)`, followed by one atomic
publication of the full `(h,r)` pair. No half-pair statement or disabled
constraint is permitted. A scope-valid immutable revision can structurally
exist before publication; SQL does not prove publication history or authorize
reuse. A non-current revision may be superseded historical evidence OR evidence
never published. Current pointer absence alone cannot distinguish them.

The protected writer commits new head, new revision, attributes/projection and
publication together. A pre-existing committed unbound head is corrupt and
fails closed. R2 does not grant takeover, repair, deletion or cleanup authority.
Compatible populated P6 evidence can survive migration without becoming valid
protected runtime input. D1 legacy behavior, D2 constructibility and D3 semantic
admission remain separate obligations.

## F1: Pair Integrity Without Changing Legacy Referential Actions

R1 added supplier/feed non-null terms to a CHECK despite the inherited
`supplier_products_supplier_feed_id_foreign ON DELETE SET NULL`.
MySQL prohibits this CHECK/referential-action combination; see
[MySQL CHECK rules](https://dev.mysql.com/doc/refman/8.4/en/create-table-check-constraints.html).
This is documentation-supported incompatibility, not an executed R2 MySQL result.

Keep these exact inherited signatures, all with ON UPDATE NO ACTION:

| FK on supplier_products | Parent | ON DELETE |
| --- | --- | --- |
| `supplier_products_supplier_id_foreign` | suppliers(id) | CASCADE |
| `supplier_products_supplier_feed_id_foreign` | supplier_feeds(id) | SET NULL |
| `supplier_products_availability_status_id_foreign` | availability_statuses(id) | SET NULL |
| `supplier_products_product_id_foreign` | products(id) | SET NULL |

Restore the unchanged canonical pair-only CHECK. Bound nullable-scope protection
moves to explicit INSERT/UPDATE guards; it is not removed:

```sql
CONSTRAINT chk_supplier_product_source_revision_pointers CHECK (
 (supplier_product_identity_head_id IS NULL AND current_source_revision_id IS NULL)
 OR (supplier_product_identity_head_id IS NOT NULL AND current_source_revision_id IS NOT NULL)
);
-- NEW object, proposed name; exact creation metadata must be independently frozen.
CREATE TRIGGER trg_supplier_product_source_pointer_insert_guard
BEFORE INSERT ON supplier_products FOR EACH ROW
BEGIN
 IF (NEW.supplier_product_identity_head_id IS NOT NULL OR NEW.current_source_revision_id IS NOT NULL)
    AND (NEW.supplier_id IS NULL OR NEW.supplier_feed_id IS NULL) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Bound source pointer scope cannot be NULL';
 END IF;
END;
```

The CHECK rejects INSERT half-pairs even with non-null scope. The INSERT guard
rejects nullable scope on any attempted pointer-bearing row. Existing column
NOT NULL restrictions also remain. A bound INSERT with ordinary scope must pass
the full pointer FK; there cannot already be a valid revision for a nonexistent
parent ID. Initial binding creates a legacy parent first, not an impossible
preseed. Legacy NULL/NULL INSERT remains permitted by inherited constraints.

### D1 UPDATE Guard

Keep `trg_supplier_product_source_projection_guard`, with ordered branches:

1. Reject half-pairs using NULL boolean XOR.
2. If OLD.h is non-null, reject NULL-safe changes to h, s or f.
3. Reject OLD.r non-null becoming NULL.
4. Only if NEW.h is non-null, require NEW.s/f non-null.
5. For a changed r require one revision/head join matching NEW `(r,p,h,s,f)`
   and `head.supplier_sku_bytes = CAST(NEW.supplier_sku AS BINARY)`.
6. Otherwise, for a bound unchanged-r row reject guarded source changes.
   For NULL/NULL legacy rows this source branch is not executed.

Guarded typed fields: price, supplier_price_raw, recommended_price, quantity,
availability_status_id, raw_data, received_at. Compare via `NOT(OLD.x <=> NEW.x)`.
Guarded strings: supplier_sku, ean, mpn, name, brand_name, category_name,
external_availability_status, external_availability_label, currency, payload_hash.
Compare `NOT(CAST(OLD.x AS BINARY) <=> CAST(NEW.x AS BINARY))` for exact stored bytes.
Stored JSON/decimal semantics are not original lexical serialization.
Canonical fingerprint recipes remain unchanged and require separate validation.

Both triggers retain the strict canonical sql_mode and cp866/cp866_general_ci
creation session; database_collation remains ENVIRONMENT_DERIVED. No CI default
is substituted. Complete proposed DDL is in external `sql/20-proposed.sql`.

No-op/workflow-only writes remain allowed (product_id, status, synced_at,
mapping_notes outside the projection guard). An unchanged importer changing
bound source fields fails closed; no protected importer is activated here.
Independent attributes and FK actions require the F2/F4 checks below.

## D2: Constructible Binding and F3 Writer Authority

Frozen reverse ownership `(p,h,s,f)` makes immediate constraints cyclic:
head-first fails pair integrity, revision-first lacks the parent head, and
pair-first lacks its revision. A transaction does not defer MySQL foreign keys.
R2 retains full publication integrity but changes reverse ownership:

```sql
UNIQUE KEY uq_supplier_product_source_revision_scope (id,supplier_id,supplier_feed_id);
KEY ix_supplier_product_revision_product_scope_fk (supplier_product_id,supplier_id,supplier_feed_id);
CONSTRAINT fk_supplier_product_revision_product_scope
 FOREIGN KEY (supplier_product_id,supplier_id,supplier_feed_id)
 REFERENCES supplier_products(id,supplier_id,supplier_feed_id)
 ON UPDATE RESTRICT ON DELETE RESTRICT;
```

The exact full current-revision FK, head-scope FK, unique head assignment,
non-null revision scope, immutable revisions and revision/head/execution
relations remain. The existing P6 product-ID FK/index are retired by P7.
The alternative persistent half-pair design is rejected. A new publication
relation is a separately reviewable larger architecture, not hidden in R2.

### State and Decision Table

| Observed state | Protected writer decision | Admission decision |
| --- | --- | --- |
| Head/revision newly created in this caller transaction | Publish complete pair and source/attributes before caller commit; rollback entire unit on error | Uncommitted data is not another transaction's authority |
| Committed head, uniquely bound product, exact valid current revision | Lock/revalidate; genuine transition uses expected-old CAS; exact idempotent replay is a verified no-write return | Evaluate all facts in one capture-start view |
| Committed head without bound product | Fail closed as corrupt; no takeover, repair or automatic deletion | Reject unbound evidence |
| Committed non-current revision | History UNKNOWN from pointer alone; no automatic reuse/republish; conflict unless separately authorized protocol proves required ownership/predecessor | Not selected merely because its digest/execution matches |
| Current revision, valid provenance and projection | May proceed under writer protocol | Eligible only if ALL canonical admission rules hold in the view |
| Current revision, invalid provenance or projection | Reject; never rewrite history from current content | Reject without repair or fallback |
| Incompatible product/head/supplier/feed/SKU | Reject before publication; no recency tie-break | Reject |
| Scope-compatible populated P6 data | Preserve during migration; does not validate historical publication | NULL/NULL pointers remain ineligible |
| Uncertain commit/session outcome | STOP, no retry or inferred rollback | No new authorization based on an uncertain result |

### Lock Order and Allocation

For **existing heads**, lock by ascending **head ID**, exactly canonical
6153/6514. Do not replace it with supplier/feed/SKU lexical ordering.
Then lock resolved staging products in ascending product ID, then attributes
by parent ID and attribute ID with the indexed parent range (including gaps).
Never acquire another head after taking product/attribute locks.

New-head allocation is INSERT-first for one logical key in a caller-owned row
transaction. Validate raw UTF-8/forbidden code points before and after the
existing trim boundary; preserve bytes without normalization/case folding.
On a successful new INSERT, resolve exact legacy s/f/binary-SKU candidates:
zero -> create ordinary NULL/NULL parent; one -> bind it; multiple -> explicit
ambiguity failure. New head, revision and publication share the same commit.
An exact logical-key duplicate waits then locks/re-reads the existing head;
it MUST already have its valid committed binding. Otherwise fail closed.
Other duplicate errors propagate. No participant commit/rollback/local retry.

Mixed multiple-new/existing-head batches are not newly authorized by this
one-key allocation protocol. They need a separate complete acquisition design;
do not invent a competing lexical order to claim deadlock safety.
The existing-head-only multi-key path retains canonical ascending IDs.

After locks, validate receipt/execution closure, expected old h/r, exact scope,
raw attributes and canonical deterministic projection. Insert the immutable
revision, write all attributes/source and publish:

```sql
UPDATE supplier_products
 SET supplier_product_identity_head_id=:h,current_source_revision_id=:new_r,
     price=:validated_price
 WHERE id=:p AND supplier_id=:s AND supplier_feed_id=:f
   AND supplier_product_identity_head_id <=> :expected_old_h
   AND current_source_revision_id <=> :expected_old_r;
-- Exactly one affected row for a genuine transition. Owning caller commits.
```

Stale CAS=0 rolls back the entire caller unit; it is not idempotent success.
Competing bindings serialize and revalidate the winning binding after waiting.
This proposal chooses the conflict branch of canonical loser handling; it
does not silently retry an execution against a new predecessor. A non-current
execution/head duplicate is not sufficient reuse authority. Same-r idempotence
requires independently verified byte-identical provenance/projection and no
write. Connection loss/deadlock/timeout propagate, without replay.

## F2: Admission Retains the Canonical Snapshot Cut

Writer locking current reads are NOT admission source reads. Admission starts
one REPEATABLE READ transaction WITH CONSISTENT SNAPSHOT at capture start,
before enumeration/download per canonical 3638. Every source pointer, revision,
execution, receipt, product projection and relevant attribute is read from that
same consistent view. Do not use FOR UPDATE to refresh admission source fields.
No cached relation from another transaction or newer mapping lookup may be mixed.
Mappings/lookups use the same view or explicit immutable version authority.

| Time | Concurrent reality | Admission observation |
| --- | --- | --- |
| T0 | Current revision A/source A/attributes A committed; establish capture snapshot | A and its matching source/attributes/lookup view |
| T1 | Writer locks, publishes B with source B/attributes B, commits | Existing T0 view unchanged |
| T2 | Admission continues while live pointer is B | Still A + source A + attributes A; validate A, never mix B attributes |

Facts not committed/visible at T0 do not become eligible by taking a later lock.
A later changed pointer does not invalidate an otherwise valid T0 cut, nor
authorize using newer inputs. If A already mismatches its visible projection
at T0, fail closed. This is snapshot-defined eligibility, not "latest wins".

Canonical authorization then uses its existing claim/fence coordination:
persist the validated member set and claim tuple atomically. The later claim
lock does not replace the source snapshot with current reads. Pre-commit
failure/retry may establish a new transaction; after successful authorization,
retries use the persisted member set/claim and immutable source evidence,
not feed re-enumeration, current staging recapture, or a new admission cut.
Uncertain authorization outcome requires reconciliation, never blind replay.
These are future runtime obligations, not an implemented admission service.

## F4: Referential Actions and Responsibility Boundaries

MySQL cascaded FK actions do not activate the child table UPDATE trigger;
see [MySQL FK behavior](https://dev.mysql.com/doc/refman/8.4/en/create-table-foreign-keys.html).
In particular, deleting an availability status can SET NULL a bound source
field without changing the revision pointer. The product trigger cannot be
credited with preventing that source change. No legacy FK is altered to hide it.

| Action | Structural protection / possible result | Snapshot admission consequence |
| --- | --- | --- |
| Delete feed for unbound legacy row | Inherited SET NULL remains legal when other dependencies allow it | NULL/NULL legacy row is ineligible |
| Delete feed for bound row | P5 immutable head/feed-owner RESTRICT and proposed revision parent scope RESTRICT protect bound scope | No nullable-scope bypass; verify actual combined graph in MySQL |
| Delete supplier | Legacy CASCADE preserved; referenced bound products/revisions can block deletion | Deleted legacy row absent at newer cut; no invented protected evidence |
| Delete availability status | SET NULL may leave pointer unchanged, structurally legal | Recompute snapshot projection; mismatch rejects |
| Delete linked catalog product | product_id SET NULL is workflow-only | Not by itself a source fingerprint change |
| Direct source UPDATE with unchanged r | Proposed trigger rejects | SQL protection limited to this write channel |
| Independent attribute INSERT/UPDATE/DELETE | Structurally legal with parent FK | Same-view attributes must match canonical digest |

If availability SET NULL commits **before T0**, admission sees NULL and the
unchanged revision digest; a mismatch fails closed. If it commits **after T0**,
admission still sees the old status/source and may accept the matching revision
provided all other rules hold. A new logical execution at a newer cut sees the
mismatch and rejects. Do not re-read a newer lookup midway through T0 admission.
The existing member retry contract does not recapture mutable source.

### D3 Responsibility Matrix

| Invariant | SQL | Writer | Admission | Remaining proof |
| --- | --- | --- | --- | --- |
| Complete pair, non-null bound scope | Pair CHECK, INSERT guard, UPDATE guard, full FKs | Ordinary parent then scoped revision then joint pair | Reject missing scope/pair in snapshot | Actual MySQL INSERT/UPDATE/FK-action negatives |
| Product/head/revision ownership | Exact tuple FKs, head uniqueness, immutable bound scope | Ascending existing-head IDs, exact resolution, reject corrupt unbound head | Exact T0 identity/provenance closure | Full schema/runtime integration |
| Receipt/execution/projection meaning | Existing execution FK/domain, not a hash recomputation | Canonical versioned byte recipe | Recompute exact T0 projection including attributes | Reduced fixture cannot prove canonical provenance |
| Expected-old CAS | Trigger does not know caller expectation | Exact old h/r predicate, observed one-row transition | Snapshot-selected revision, not latest locked pointer | Real competing sessions + later writer tests |
| Source invariance | Direct UPDATE protected, FK actions/attributes may bypass | Same transaction source/attribute publication | Reject T0 mismatch; preserve T0 consistency after concurrent commit | Referential-action and snapshot experiments NOT RUN |
| No check-to-use mixture | MVCC permits one read view; SQL alone does not choose application read mode | Locking current reads for writes only | All source inputs one view/immutable authority; persisted members after authorization | Future admission implementation and retry tests |
| Byte sensitivity | Binary SKU and stored-string comparisons | Raw validation/trim without normalization | Exact canonical serialization | Both DB defaults; JSON lexical input not recoverable from stored JSON |

## Proposed Canonical Prose Deltas

These are explicit proposals, not edits to canonical files. Fixed fingerprint
recipes and capture-start semantics are retained, not rewritten around R2.

| Baseline section | Exact normative replacement/addition for later adoption |
| --- | --- |
| 5937-5958 pointer ownership | Revision references existing (p,s,f). Pair publication references full (r,p,h,s,f). Structural non-selection proves neither never-published history nor runtime reuse authority. Preserve compatible P6 evidence without admission authority. |
| 5975-5988 SQL guarantees | Pair-only CHECK plus explicit INSERT/UPDATE scope guards. Direct unchanged-r source writes reject. Attributes and referential actions are not universally intercepted. SQL does not compute semantic projection or enforce caller expected-old CAS. |
| 5990-6013 recipes | Keep exact canonical field order/version/serialization. Add: availability and attribute values used for admission come from the same capture-start view or immutable authority; mismatch rejects without repair. |
| 6116-6153 Import/retry | Preserve INSERT-first and ascending existing-head ID order. New head may bind in its creating transaction; committed unbound head fails closed. Non-current revision is not automatically reusable. Define parent/attribute order and conflict outcome as above. |
| 6153-6167 concurrency | Preserve canonical loser revalidation; this narrower protocol chooses conflict, not automatic retry. No participant commit/rollback and no replay after uncertain outcome. |
| 6169-6204 admission | Keep capture-start RR cut; require one consistent source/attribute/lookup view. Do NOT adopt R1 locking-current-read replacement. Add explicit T0/T1/T2 timeline and before/after SET NULL behavior above. |
| 6233-6240 authorization | Keep claim lock, atomic member persistence and post-authorization reuse; that coordination must not refresh source inputs. No current staging recapture on authorized retry. |
| 3638, 6505 and 6514 | Retain capture-start timing, snapshot revision selection and head-ID lock order; cross-reference this distinction, not a second ordering/cut. |
| Runtime plan P0-07 allocation | Foundation remains dormant; writer/admission/retry integration is a separate phase and approval. |

## Exact Proposed Registry and Downgrade Delta

Relative to frozen P7 this is **four changed signatures plus one NEW trigger**,
not R1's five changed signatures. The CHECK signature remains unchanged.
Changed: revision product-scope FK, its supporting index, product unique scope
index, and UPDATE guard. Added: `trg_supplier_product_source_pointer_insert_guard`.
No inherited FK action, P0-P6 signature, canonical hash or oracle is edited now.

P7 adds 13 objects and retires the two P6 product-ID FK/index objects:
339 + 13 - 2 = **350**. Exact membership, not counts alone, identifies a state.

| Proposed state | Count | Required membership difference |
| --- | ---: | --- |
| P7 | 350 | Both proposed guards plus changed scoped ownership |
| P7_DOWN_1 | 349 | NEW INSERT guard removed; UPDATE guard still present |
| P7_DOWN_2 | 348 | Both guards removed; scoped reverse FK/index still present |
| P7_DOWN_3 (NEW) | 348 | Reverse FK/index replaced by original P6 ID-only pair |
| P6 | 339 | P7 product FKs/CHECK/indexes/columns removed |

There must be **four** separately recorded down operations:

1. P0-07-DOWN-01 drops only the INSERT guard: P7 -> P7_DOWN_1.
2. P0-07-DOWN-02 drops only the UPDATE guard: P7_DOWN_1 -> P7_DOWN_2.
3. P0-07-DOWN-03 atomically replaces reverse scoped FK/index with P6 ID-only
   FK/index: P7_DOWN_2 -> P7_DOWN_3.
4. P0-07-DOWN-04 atomically drops product full-pointer/head FKs, CHECK, four
   P7 indexes and two columns: P7_DOWN_3 -> P6.

Two DROP TRIGGER statements are NOT one atomic DDL boundary. Rebind each
operation's pre/result hashes, affected-object signatures and next_operation.
Old names P7_DOWN_1/2 no longer denote their old memberships/digests.
INSERT guard belongs to P7 and all existing P8/P9 normal/down suffix states,
not P7_DOWN_1/2/3. UPDATE guard also belongs to P7_DOWN_1. Changed reverse FK/index
belong through P7_DOWN_2; the product scope index and remaining P7 product
objects belong through P7_DOWN_3. Inherited/base objects gain membership in the
new intermediate state without signature changes.

Successor counts are proposed P8=356, P8_DOWN_1=355, P8_DOWN_2=353;
P9=369, P9_DOWN_1=368, P9_DOWN_2=362. Subject to exact independent registry
authoring, total registry cardinalities become 376 object signatures, 18 states,
16 down operations (one new object/state/operation; four signature replacements).
These are design arithmetic, not accepted/generated new canonical hashes.
Old P7/P7_DOWN digests remain historical only; unchanged P6 is
`ea265e4717c2f61abba6731f08be576ff6fcd01fe6b673d61d41011a888056aa`.

Forward P6->P7 performs no binding, backfill, rehash or source normalization.
Before DDL reject any existing revision whose product is absent or whose s/f
differs NULL-safely from its parent. Under existing exclusive/named-lock
authority, verify exact P6 and inbound dependencies, preserve all old data,
timestamps and admin access, and inspect after each atomic operation.
Compatible populated revisions survive; NULL/NULL stays inadmissible.

Down requires pristine all-null pointers, exact terminal/declared partial state,
correct session/owner, no incompatible suffix or inbound dependencies. Preserve
predecessor data. Partial resume requires fresh authority for that exact step,
not automatic retry. Unknown DDL outcome or session loss stops without cleanup
masking; counts alone never authorize destructive operations.

## Proposed Future Allowlists

Neither list permits changes now. Historical staging evidence is not editable.

### A. Separate Canonical Adoption

1. `docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md`
2. `docs/PHASE_9C6_5C3D1_RUNTIME_IMPLEMENTATION_PLAN.md`
3. `docs/PHASES.md`
4. `docs/PHASE_III_P0_SOURCE_REVISION_FOUNDATION_PROPOSAL.md`
5. `docs/PHASE_III_P0_STAGING_SOURCE_REVISION_POINTERS_FOUNDATION_PROPOSAL.md`
6. `docs/PHASE_III_P0_STAGING_SOURCE_REVISION_POINTERS_D1_D3_RESOLUTION_PROPOSAL.md`
7. `database/migrations/support/CanonicalSupplierPhaseThreeP0Oracle.php`
8. `tests/Unit/Suppliers/SourceProfiles/CanonicalSupplierPhaseThreeP0OracleTest.php`
9. `tests/Feature/SupplierOfferLifecycleDocumentationContractTest.php`

Independently author literal object/state/down expectations including the new
INSERT trigger and P7_DOWN_3. Preserve P0-P6 records. Do not derive accepted
goldens from implementation output. Explicitly supersede proposals, not history.

### B. Later Dormant Foundation Implementation

1. `database/migrations/2026_08_28_090006_add_source_revision_pointers_to_supplier_products_table.php` (NEW)
2. `database/migrations/support/CanonicalSupplierPhaseThreeP0Schema.php`
3. `tests/Feature/PhaseThreeP0StagingSourceRevisionPointersFoundationMysqlTest.php` (NEW)
4. `tests/Feature/SupplierOfferLifecycleDocumentationContractTest.php`
5. `tests/Feature/PhaseThreeP0SliceOneMysqlTest.php`
6. `tests/Feature/PhaseThreeP0SliceTwoMysqlTest.php`
7. `tests/Feature/PhaseThreeP0SliceThreeMysqlTest.php`
8. `tests/Feature/PhaseThreeP0SliceFourMysqlTest.php`
9. `tests/Feature/PhaseThreeP0SourceRevisionFoundationMysqlTest.php`

Coordinator recovery and tests must implement FOUR down operations and THREE
partial boundaries. Inspector/comparator/enum are reuse dependencies only;
if changes are needed there, request an exact expanded allowlist first.
No importer, writer, model, service binding, endpoint, capture or admission
activation belongs to either list. A/B are not owner approval for those phases.

Preserve prior A1-A12 intent with explicit amendments: compatible populated P6
guard before DDL; actual constructible pair; separate INSERT/UPDATE negatives;
exact inherited FK actions and both schema defaults; byte-sensitive changes;
all four down operations/three partial boundaries; actual concurrency/CAS;
canonical independent goldens; real regression. Add snapshot/referential-action
negatives to later runtime acceptance. No test/contract is weakened here.

## F5: External Runner and Evidence Limits

R2 `run-design.cjs` uses async spawn through `runner-core.cjs`, not spawnSync
while clients run. Monotonic deadlines are checked before/after observations
and immediately before stdin publication. A positive result at 13000ms for a
12000ms deadline, or exactly at the deadline, is rejected. A shared failure
latch prohibits subsequent sends and retains the first error separately from
secondary exits/errors. Spawn, stdin write/end, stream, exit and close are
handled. A successful marker cannot override a failed exit or missing close.
Final success requires exact persisted SQL tuple/cardinality assertions.

Every command streams UTC/monotonic events, stdout/stderr, input SHA-256,
observed process exit and close. Abandoning a local Docker CLI means remote
outcome UNKNOWN, not rollback proof. No remote kill, retry, repair or automatic
container cleanup is performed. Collision and failed isolation are STOP.
The bounded prepare/package helper's spawnSync is not the concurrency runner.

## External Package and Actual Validation

Evidence root: `p0-07-d1-d3-resolution-r2-20261021-01`.
The standalone ZIP includes this R2 proposal, unchanged R1, baseline full diff,
incremental diff, manifest, runner/helpers/tests, all SQL and sanitized evidence.
`r1/` is historical, not R2 execution. Canonical extraction records bind the
actual inherited FKs, scope FKs and original down operations.

The SQL fixture is a reduced synthetic graph, NOT a full P6/P7 schema. It
includes the four exact inherited product FK actions and the head/feed-owner
restriction. It checks FK metadata before/after proposed DDL. Receipt/execution
closure is reduced; toy snapshot hashes/dummy digests are NOT canonical recipes.
Prepared experiments cannot prove actual writer/admission code, which is absent.

| Check | Actual result and boundary |
| --- | --- |
| Controlled-clock/process-double runner tests | 27 PASS, 0 FAIL/SKIP; only runner control flow and exact result validators |
| Engine readiness | Query exit 1, missing pipe; no provisioning or MySQL execution |
| F1 inherited FK/CHECK compatibility; direct INSERT/UPDATE | NOT RUN; prepared 00/01/02/20/40 scripts |
| Frozen D1/D2 reproduction | NOT RUN; 10/30 scripts, original definitions retained |
| Concurrency/CAS/final state | NOT RUN; 50-55 scripts |
| Snapshot import and before/after SET NULL | NOT RUN; 60-66 scripts; canonical admission remains unimplemented |
| Documentation contract | NOT RUN; worktree vendor/autoload.php and bundled PHP absent |
| UTF-8, links, scope, hashes, diff reconstruction, archive readback | Recorded separately in validation-summary.json |

The unexecuted documentation command is
`.\.tools\php\php.exe artisan test tests/Feature/SupplierOfferLifecycleDocumentationContractTest.php`.
Its configuration is testing/SQLite :memory:, not permission to borrow unverified
shared dependencies. No application/browser suite or dependency installation.
Local Node tests do not substitute for MySQL, documentation contract or P0-07
acceptance. Exact commands/exits, preserved first results and archive readback
are in the external logs. No-index diff exit 1 means differences, not failure.

Supplier import remains staging-only. Preview/audit performs no protected writes
or mutation dispatch. CREATE retains its existing authorized state;
UPDATE/SYNC_ALL/AUTO remain false. No supplier content/image/category/attribute
overwrite, auth/session change, catalog data write or runtime activation.

Next single step: independent R2 review, including the explicit MySQL proof gap.
Do not automatically adopt canonical changes or begin P0-07 implementation.

DESIGN STATUS: PROPOSED / UNAPPLIED - R2.
RUNNER TESTS: 27 PASS / 0 FAIL / 0 SKIP.
MYSQL DESIGN PROOF: NOT RUN.
P0-07 IMPLEMENTATION: NOT STARTED.
CANONICAL ADOPTION: NOT PERFORMED.
RUNTIME ACTIVATION: NOT PERFORMED.
VPS ACTIONS: NONE.
COMMIT / PUSH / PR / MERGE: NOT PERFORMED.

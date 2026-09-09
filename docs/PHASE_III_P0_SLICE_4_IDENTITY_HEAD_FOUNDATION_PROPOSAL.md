# Phase III-P0 Slice 4: logical identity-head foundation proposal

## Proposed decision, not active authority

Proposed phase: **Phase 9C.6.5C.3D - Phase III-P0 Slice 4**.
Proposed name: **Immutable Supplier Product Logical Identity-Head Foundation - P0-05 only**.
Proposed status: `DEFINED_NOT_IMPLEMENTATION_AUTHORIZED`.

At its creation this was a NON-AUTHORITATIVE PROPOSAL awaiting independent design review and
canonical adoption. That creation checkpoint left P0-05 through P0-09 unsliced.
After separately authorized adoption, only the canonical sequence defines Slice 4;
this subordinate proposal remains non-authoritative and grants no implementation.
P0-06 through P0-09 remain unimplemented, unsliced and unauthorized. This proposal does not declare a
new CURRENT registry or readiness map. P0-05 implementation is NOT AUTHORIZED.
No P0-05 file, migration execution or runtime caller is added by this task.

Verified base: `05750da719b933937b80af83858a4a7f0803296f`, PR #224 merge,
tree `279ae04b0780ff887e741f28a007907b701ce87e`. See
[dated Slice 3 staging evidence](PHASE_III_P0_SLICE_3_STAGING_EVIDENCE_2026_09_08.md).
Slice 3's operational completion, its code-presence contract, local R6 results,
merge CI and runtime authorization are separate facts. Slice 1/2 history and
the dated Slice 3 proposal/implementation record are not rewritten.

## Canonical sources

The [persistence design](IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md#phase-iii-provenance-and-bounds-architecture-decision)
alone owns the schema, semantics and readiness decisions. Its supplier identity
head section and [Import, retry and concurrency rules](IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md#import-retry-and-concurrency-rules)
govern the whole transaction. The
[runtime plan](PHASE_9C6_5C3D1_RUNTIME_IMPLEMENTATION_PLAN.md) is subordinate.
Use the existing P0-05 rows in migration/artifact/SQL/object/state/prefix and
downgrade registries, including `phase-iii-p0-downgrade-operations-v1` and
`phase-iii-p0-prefix-downgrade-contract-v1`; this document is not a duplicate registry.

Implementation precedents inspected without modification:
[coordinator](../database/migrations/support/CanonicalSupplierPhaseThreeP0Schema.php),
[step enum](../database/migrations/support/P0MigrationStep.php),
[oracle](../database/migrations/support/CanonicalSupplierPhaseThreeP0Oracle.php),
[inspector](../database/migrations/support/CanonicalSupplierPhaseThreeP0SchemaInspector.php),
[comparator](../database/migrations/support/CanonicalSupplierPhaseThreeP0SchemaComparator.php),
[receipt repository](../app/Repositories/Suppliers/SupplierImportSourcePayloadReceiptRepository.php),
[Slice 3 MySQL tests](../tests/Feature/PhaseThreeP0SliceThreeMysqlTest.php).
P0_05 is already an enum/oracle concept, not an implemented coordinator step.

## Minimal future artifacts

Every path and PHP API below is PROPOSED, not an existing or authorized runtime
surface. Canonical database names are fixed; PHP helper naming is subordinate.
No service binding, provider boot action or production caller is part of the
foundation. The transaction participant is deliberately not a standalone writer.

| Proposed path / name | Responsibility / canonical dependency | Allowed future writes and transaction owner | Failure / acceptance / deferred integration |
| --- | --- | --- | --- |
| `database/migrations/2026_08_28_090004_create_supplier_product_identity_heads_table.php` | One thin P0-05 wrapper using existing `P0MigrationStep::P0_05`; exact P4 -> P5 | Canonical head table and its 14 subordinate objects only; existing dedicated DDL session/mutex | F1/F2/F9; malformed/session/unknown state rejects; no P0-06/P0-07 or runtime DDL |
| `database/migrations/support/CanonicalSupplierPhaseThreeP0Schema.php` (minimal future extension only) | Add exact P0-05 forward/down routing and literal approved SQL; reuse invocation-scoped guard/consumption/evidence | Coordinator owns DDL, never caller-supplied SQL; no registry/oracle refresh | F1/F9/F10; complete pristine/shared predicates before DDL, uncertainty remains blocked |
| `app/Models/SupplierProductIdentityHead.php` | Append-only `supplier_product_identity_heads`; existing guarded model conventions, no updated_at/soft delete | Read/hydrate on explicit connection; insertion only through reviewed transaction participant | F2/F4; reject save/update/touch/touchQuietly/delete/force/unguarded/rebind/upsert paths; no mutable CRUD |
| `app/Data/Suppliers/Imports/SupplierProductLogicalKey.php` | Minimal immutable supplier ID/feed ID/canonical SKU value; validation before transaction, unchanged UTF-8 bytes | Pure computation only; no DB, source read, timestamp, hash domain or allocator | F3/F8; invalid raw input and coercion reject; no legacy fallback or live selector reread |
| `app/Repositories/Suppliers/SupplierProductIdentityHeadRepository.php`, proposed `insertOrLockInTransaction(Connection, SupplierProductLogicalKey)` | Narrow step-2/3 participant, not a whole-import repository; exact tuple/byte comparison | Caller owns outer transaction on that same connection; one head INSERT attempt and locked reuse only; never commit/rollback/start transaction | F5/F6/F7/F8; outside transaction rejects before SQL; exact duplicate only; complete binding/revision/pointer integration REQUIRED before live use |
| `tests/Feature/PhaseThreeP0SliceFourMysqlTest.php` | Future real MySQL 8.4/process F1/F2/F4-F10, based on existing Slice suites | Task-owned disposable schema/rows and necessary synthetic parents only | No real Product/staging writes; no claim that isolated head-only test commits are production-safe |
| `tests/Unit/Suppliers/Imports/SupplierProductLogicalKeyContractTest.php` | Future immutable/raw-validation F3/F8 vectors; stable scope/bytes and rejection | None | No production validator created merely to generate proposal vectors |

No separate orchestrator, source/profile/receipt fields, source fetcher, generic
CRUD service or new head fingerprint belongs here. Future implementation must
retain a private/dormant call graph. If the participant cannot remain within
these transaction constraints, reduce implementation to schema/model/value;
do not substitute an independently committing repository.

## Frozen schema and local-only downgrade

The following are existing oracle facts, not newly blessed expectations:

| State | Classification | Objects | Existing SHA-256 |
| --- | --- | ---: | --- |
| P4 | NORMAL_PREFIX | 294 | `1fc28641da815cd4737b50b5b8ed065918b3f416f970ba2cccef82e5342e8d57` |
| P5 | NORMAL_PREFIX | 309 | `8cc5afed3a68c4152bf1456a10741c5e23bd077bf4dae39cae639208872ca56f` |

P0-05 adds exactly 15 objects: one table, five columns, five indexes, one FK,
one CHECK and two triggers. Order is
`id > supplier_id > supplier_feed_id > supplier_sku_bytes > created_at`.
IDs are non-null unsigned BIGINT; id is auto-increment PRIMARY. SKU is non-null
VARBINARY(1020), not a collated string or SKU hash. created_at is non-null UTC
TIMESTAMP(6), default CURRENT_TIMESTAMP(6), no update expression.
Table: InnoDB, Dynamic, utf8mb4_unicode_ci, empty create_options, ownership
comment `mycomputer:phase-iii-p0:v1:owner=P0-05`.

The five ordered BTREE indexes are PRIMARY(id),
uq_supplier_product_identity_head(supplier_id > supplier_feed_id > supplier_sku_bytes),
uq_supplier_product_identity_head_scope(id > supplier_id > supplier_feed_id),
uq_supplier_product_identity_head_revision_scope(id > supplier_id > supplier_feed_id > supplier_sku_bytes),
and nonunique ix_supplier_product_identity_head_feed_owner_fk(supplier_feed_id > supplier_id).
No prefix lengths, expressions, extra index or column may be substituted.
fk_supplier_product_identity_head_feed_owner maps
(supplier_feed_id, supplier_id) to supplier_feeds(id, supplier_id), RESTRICT
on update and delete. chk_supplier_product_identity_head_sku_bytes enforces
byte length 1-1020, not full application UTF-8/character validation.

The BEFORE/ROW triggers are trg_supplier_product_identity_head_no_update and
trg_supplier_product_identity_head_no_delete. Both SIGNAL SQLSTATE '45000',
respectively `Immutable supplier product identity head cannot be updated` and
`Immutable supplier product identity head cannot be deleted`, including no-op
updates. Preserve the exact registered SQL mode and trigger-creation session:
cp866 / cp866_general_ci; database collation is the attested
ENVIRONMENT_DERIVED_DATABASE_COLLATION token, not a wildcard for changed table
or connection collation. Preserve UTC and every existing session prerequisite.
Complete signature/member sets and exact DOWN SQL are read from the registries.
The review package includes a non-executed forward SQL transcription derived
from those signatures; it is not an existing forward coordinator implementation
or a new SQL registry. No candidate database defines these expectations.

P0-05-DOWN-01 is one MySQL 8.4 atomic DROP TABLE statement, including dependent
head objects, exact terminal P5 -> P4, no reachable P5 partial state. It is
local/testing-only, never an operational recovery command. Head-empty alone
is NOT sufficient: require the full canonical pristine/dependency protocol,
the P5 row requires ZERO head rows, absent revision/pointer children and absent
P6-P9 groups, with exact schema/session and no unknown object. Preserve existing
P0-02/P0-03/P0-04 parent rows; do not demand that retained parent tables be empty.
The shared-table added-field-null/no-dependent-evidence predicates apply when
removing the later P7/P8/P9 groups, not as invented P5 columns or blanket empty
Product/staging requirements. Their absence at P5 is independently inspected.
Use the registered predicates verbatim, not a simplified new allowlist.
Consume fresh destructive authorization before gates/inspection/DDL, clear its
process visibility, prohibit same-process reuse and nested calls, retain the
dedicated-session named lock through re-enumeration, and preserve evidence on
every pre-DDL rejection. Uncertain DDL never means success or permits blind
retry; a new authorized invocation reclassifies before any action. Final P4
fingerprint must match. Operational recovery is forward-only and evidence-preserving;
this proposal provides no VPS downgrade commands.

## Logical-key validation and fixed vectors

Exactly three fields: supplier_id, supplier_feed_id, supplier_sku_bytes.
No source identity, profile/execution/receipt ID, timestamp, EAN, MPN or payload
hash participates. Same supplier/feed/SKU survives source A -> B unchanged;
later revisions, not heads, preserve source history.

Validation order follows the canonical requirement of a validated SKU before
trim and before any transaction: reject null/non-string, malformed UTF-8,
raw control-bearing input, or raw input above 255 Unicode characters / 1020
UTF-8 bytes; then PHP trim(); then reject empty output and recheck valid UTF-8,
character/byte limits and absence of controls. Never trim away invalid controls
or truncate an oversized raw value to make it valid. Ordinary edge ASCII spaces
trim; remaining whitespace, case and code points remain unchanged. No Unicode
normalization, case folding, text-collation equality or existing identity-hash
fallback is permitted. ID values must be exact positive supplier/feed IDs within
the application's integer representation; reject coercion and ownership mismatch.

Control classification detail for design review: the authority says
"control-bearing" but does not name a Unicode property or version. The fixed
C0/DEL/C1 rejection vectors below are unambiguous; this proposal does not silently
approve or reject all Unicode format (Cf) characters as a new canonical rule.
An explicit canonical interpretation for that edge set must precede a runtime
validator. This is a bounded pre-implementation design question, not permission
to accept unclassified input or weaken the raw-control prohibition.

Vectors below are literal input hex -> canonical output hex or rejection.
Repeated notation means exact byte repetition, not a runtime generator contract.
PHP trim/mb_check_encoding/mb_strlen and an independent Node byte decoder/count
check verify these review fixtures only; no production validator was created.

| Input bytes / value | Canonical bytes or result | Meaning |
| --- | --- | --- |
| `2020534b552d312020` | `534b552d31` | ordinary edge spaces |
| `4162` / `6162` | `4162` / `6162`, distinct | case preserved |
| `41202042` | `41202042` | internal double space preserved |
| `c2a041c2a0` | `c2a041c2a0` | NBSP is not PHP trim's ASCII space |
| `c3a9` / `65cc81` | `c3a9` / `65cc81`, distinct | precomposed/decomposed Unicode |
| null / empty / `2020` | REJECT | null/raw empty/trimmed empty |
| `0941`, `410a`, `0041`, `417f`, `41c285` | REJECT before trim | raw TAB/LF/NUL/DEL/C1 controls |
| `c328` | REJECT | malformed UTF-8 |
| `41` repeated 255 | same 255 bytes | inclusive character limit |
| `41` repeated 256 | REJECT | no truncation |
| `20` + `41` repeated 255 | REJECT before trim | raw 256 characters |
| `f09f9880` repeated 255 | same 1020 bytes | inclusive character and byte limits |
| `f09f9880` repeated 256 | REJECT | 256 characters / 1024 bytes |

The SQL CHECK cannot validate arbitrary binary strings as canonical SKU.
The immutable value and controlled insertion boundary must enforce this grammar.
There is no hash-collision alternate identity; exact duplicates preserve original
ID, created_at and bytes. Any scope/byte inconsistency fails closed as canonical
supplier_product_logical_identity_conflict, never an UPDATE of the winner.

## Mandatory whole-transaction dependency

Canonical production commit = head + uniquely bound SupplierProduct + immutable
revision + current pointer/source projection in ONE transaction. A committed
head without its uniquely bound SupplierProduct is corrupt, not recoverable by
cleanup, takeover, auto-delete or a "bind later" exception.

The future primitive validates its immutable key before the caller starts the
row transaction; it requires that same explicit connection's active transaction
before SQL. It never starts/commits/rolls back an outer transaction or savepoint.
Attempt head INSERT first; catch only MySQL 1062 for
uq_supplier_product_identity_head. Then SELECT the same supplier/feed/SKU tuple
FOR UPDATE and compare exact bytes/ownership. Do not swallow another unique,
FK, CHECK, connection, deadlock or lock-timeout error. Do not use INSERT IGNORE,
REPLACE, UPSERT or absent-row/gap-lock-only serialization. The unique-key wait
is the canonical first-insert primitive. Model and SQL immutability remain active.

The caller retains the lock to outer commit/rollback and owns full rollback on
failure. Propagated exceptions are not permission to commit partial evidence.
A retry after known rollback restarts canonical step 2 with the same immutable
key and original source authority. Deadlocks require whole-transaction retry,
not continuation on an aborted transaction. Unknown commit must reconcile exact
persisted authority or remain blocked, never allocate another key. The helper
does not reread mutable feed/template selectors. Multi-row import lock ordering
and bound-row/revision/pointer comparison are deferred integration, not relaxed.

P0-05 alone cannot provide P0-06/P0-07 atomic binding. Direct helper calls may
exist only in isolated tests until those later gates are implemented, reviewed
and separately activated. A committed head-only synthetic fixture is solely a
disposable schema-test fixture, never a supported production protocol state.
No public "create head now, bind later" repository is proposed. The dormant table
alone neither prevents legacy duplicate SupplierProducts nor proves actual source
acquisition/provenance.

## Planned foundation acceptance

F1-F10 are FUTURE implementation tests, not executed migration/process results
of this documentation task. All writes below use verified task-owned disposable
databases only; synthetic parent setup is separate from operations under test.

| ID | Requirement / planned test | Fixture/environment | Expected result | Allowed writes / boundary |
| --- | --- | --- | --- | --- |
| F1 | Fresh and populated P4 -> P5; exact independent inspection, +15 objects; wrong session/malformed/unknown prefix | MySQL 8.4, frozen oracle | exact P5 or zero precondition DDL | Head DDL only; foundation |
| F2 | Exact five ordered columns/indexes/CHECK/FK; null/length/feed-owner failures | MySQL synthetic supplier/feed parents | exact ownership, all invalid inputs rejected | Disposable head inserts only; foundation |
| F3 | All fixed raw/canonical vectors and immutable value typing; no EAN/MPN/hash fallback | Pure PHP and independent byte fixtures | exact bytes/distinction or rejection before SQL | No DB writes; foundation; Unicode edge interpretation resolved before implementation |
| F4 | Raw/model no-op UPDATE, DELETE, REPLACE/upsert/rebind, force/unguarded/touch; exact duplicate reuse | MySQL + model fixtures | rejects mutation; unchanged original ID/created_at/row snapshots | Disposable initial inserts; foundation |
| F5 | INSERT then same-tuple FOR UPDATE, exact reuse/conflict; inject unrelated SQL failures | MySQL explicit connection/transaction | relevant duplicate only; unrelated failures propagate | Caller-owned head insert or zero; foundation |
| F6 | Independent-process same-key/different-key races; first contender rollback and retry | Linux PHP pcntl, separate MySQL sessions, performance_schema lock evidence | same canonical key converges; distinct keys remain distinct; loser can insert after rollback | Disposable heads/parent setup; foundation; barriers/observed waits, not sleeps alone |
| F7 | Outside transaction, wrong connection, exception after INSERT, deadlock/uncertainty ownership | MySQL observer + caller transaction | no partial commit; caller retains commit/rollback responsibility and retry key | Rolled-back head evidence only; foundation |
| F8 | Supplier/feed scope and source A -> B same-key stability; selector mutation on retry | Pure key plus synthetic persisted fixtures | same head for same tuple; different tuple distinct; no source fields/reread | Disposable head evidence only; foundation |
| F9 | Exact pristine terminal P5 -> P4; unknown/nonterminal/malformed/nonempty/shared predicate; consumed authorization and repeat/nested refusal | MySQL dedicated coordinator; known/uncertain outcome injection | full validation before first DDL; reject preserves P5/evidence; successful exact P4 | Local/testing-only registered head DDL; foundation |
| F10 | Unchanged P0-01-P0-04/Phase I/II/oracle; actual filesystem discovery; full parent/Product/staging snapshots and same genuine active Super Admin | Existing suites, populated disposable fixtures, refreshed User::ROLE_SUPER_ADMIN identity/relationships/panel/manage-users predicates | preserved bytes/access before and after every transition; missing/extra/deferred files reject | Synthetic setup only outside head operation; foundation regression |

Full backend/CI on exact future head, lint/Pint and scope review remain required
for a separately authorized implementation. SQLite skips are not MySQL evidence;
this task runs only documentation/pure checks and does not recreate the old DNS/
Docker environment or run P0-05 migrations.

## Deferred integration acceptance

| Deferred requirement | Future test / guarantee | Separate gate, not Slice 4 permission |
| --- | --- | --- |
| Atomic head + unique SupplierProduct + revision + pointer/projection | failure after each step rolls ALL writes back; no committed orphan; exact full commit | P0-06/P0-07 plus complete protected writer review/authorization |
| Committed unbound head | fail closed as corrupt; no delete/takeover/repair | Complete writer integrity gate |
| Legacy zero/one/multiple unheaded exact SKU rows | ascending-ID locks; zero insert, one bind/recapture, multiple legacy_supplier_product_identity_ambiguous | Legacy binding integration; no historical inference or cleanup |
| Composite ownership, revision retry and pointer CAS | immutable execution/head revision; stale predecessor rejected; original history retained | P0-06/P0-07 integration tests |
| Multi-row imports | ascending head-ID lock order and real competing transactions | Whole-import transaction/lock-order review |
| Source A -> B and retry | original immutable context/execution/receipt retained, no mutable-selector reread | Complete provenance writer with original receipt authority |
| Downloader/redirect/handle/parser EOF | exact no-redirect/bounded handle/accepted-byte/EOF contract | Separate source-acquisition integration, operational bounds and activation gate |

P0-06 through P0-09 remain unimplemented, unsliced and unauthorized. No revision
table, staging pointer, five-field claim/generation source binding or policy-v2
implementation is in the proposed foundation. Also excluded: first full staging
insert, source projection updates, runtime retry orchestration, importer wiring,
protected downloads, BoundedImmutableSourcePayload ownership, parser/EOF,
capture/backfill, queue dispatch, scheduler, commands/routes/listeners/observers.
No operational recovery or actual receipt/source acceptance is authorized.

## Adoption and continuing safety

The complete active documentation candidate preserves both protected authority
documents and every existing expectation/parser/method. The external
`slice4-authority-alignment.UNAPPLIED.patch` is against that completed candidate,
not against the old owner checkout. It proposes only lifecycle/next-slice prose,
references and corresponding fixed contract expectations after independent
measurement. OLD and PROPOSED measurements are not APPROVED values. No active
patch application, re-blessing, parser relaxation or historical inventory update.
Separate exact owner authorization remains necessary after independent review;
2026-09-06/07/08 approvals are historical and not reusable. Canonical adoption
would still NOT authorize P0-05 implementation, runtime activation or deployment.

Use the canonical readiness reference above: PH3-RDY-003 remains BLOCKED;
all ten operational bounds remain NOT SPECIFIED; runtime activation UNAUTHORIZED.
No competing readiness map is introduced. Preserve manual controlled CREATE=true,
UPDATE=false, SYNC_ALL=false, AUTO=false and all seven disabled capture gates.
No supplier input may overwrite catalog content/images/categories/attributes;
supplier import remains staging-only, with no Product mutation or Catalog Sync.
Existing Super Admin accounts/sessions remain untouched.

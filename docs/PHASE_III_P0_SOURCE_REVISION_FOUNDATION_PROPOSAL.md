# Immutable Supplier Product Source Revision Foundation

Date: 2026-10-17. Status: **NON-AUTHORITATIVE PROPOSAL / NOT IMPLEMENTED**.
"Slice 5" is only a proposed review label, not an adopted canonical slice.
P0-06 through P0-09 remain `NOT_SLICED_NOT_AUTHORIZED`.

## Authority and Baseline

The sole schema/semantic/readiness authority is the
[canonical persistence design](IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md).
The [runtime plan](PHASE_9C6_5C3D1_RUNTIME_IMPLEMENTATION_PLAN.md) and this
proposal are subordinate. In any discrepancy, stop and resolve it against the
canonical source; do not update an oracle from implementation output.

Read together with the [Slice 4 foundation proposal](PHASE_III_P0_SLICE_4_IDENTITY_HEAD_FOUNDATION_PROPOSAL.md),
[dated P0-05 staging record](PHASE_III_P0_SLICE_4_STAGING_EVIDENCE_2026_10_16.md),
[phase index](PHASES.md), [data ownership](DATA_OWNERSHIP.md),
[Catalog Sync safety](CATALOG_SYNC_SAFETY.md) and [testing](TESTING.md).

Review baseline is merge/deployed commit
`be849c48279b65257fdcbcb217f4fa41a58a3104`, independently still GitHub main
on 2026-10-17. Its tree equals PR head
`7b2b5cd8c048af2c2e6622cc9d83f6dadef0c102`:
`2892872f87f0a93aa816d7b8e62cadf339da90c9`.
This is a fixed review baseline, not a moving-main assumption.

Current P0-05 code presence remains `IMPLEMENTATION_PRESENT_DORMANT`.
Deployment evidence does not supply implementation or activation authority for
P0-06. The existing enum and frozen oracle already describe later prefixes;
the coordinator's implemented forward/down maps stop at P0-05. Schema
expectations in an oracle are not executable support or owner authorization.

## Recommended Smallest Foundation

Propose only **exact P5 -> P6 schema support, a guarded read/hydrate-only model,
and focused tests**. There is no importer integration or application INSERT
entry point in this foundation. Test-only SQL inserts use synthetic isolated
fixtures to verify constraints; they do not establish a safe operational writer.

The model would reuse existing immutable-model conventions for reads and
immutable timestamp casts, but explicitly reject `save()` including creation,
as the identity-head model does. No writable factory, public create helper,
observer, service binding, route, command, job or schedule is proposed.

Do **not** introduce a revision value builder, fingerprint service or repository
participant yet. Those would need independently specified parser/projection
inputs, receipt binding and the complete P0-07 transaction boundary. A generic
"accept six hashes and insert" helper would falsely imply semantic validation.
Read/hydration has a small, testable responsibility; write authorization remains
absent. SQL guards remain essential because model guards do not protect raw SQL.

## Existing P6 Expectations, Not a New Registry

The following is a review map into existing canonical records, not a second
schema registry. Exact field signatures, ordering, SQL, normalization and hashes
remain in `phase-iii-p0-object-signatures-v1`,
`phase-iii-p0-state-signatures-v1` and `phase-iii-p0-downgrade-operations-v1`
in the canonical design,
as transcribed in
[`CanonicalSupplierPhaseThreeP0Oracle`](../database/migrations/support/CanonicalSupplierPhaseThreeP0Oracle.php).
No generated expectations, new golden hashes or relaxed comparator are proposed.

| Boundary | Existing canonical expectation |
| --- | --- |
| Predecessor | exact P5 / NORMAL_PREFIX; 309 objects; `8cc5afed3a68c4152bf1456a10741c5e23bd077bf4dae39cae639208872ca56f` |
| Result | exact P6 / NORMAL_PREFIX; 339 objects; `ea265e4717c2f61abba6731f08be576ff6fcd01fe6b673d61d41011a888056aa` |
| P0-06 delta | 1 table, 16 columns, 6 indexes, 3 FKs, 2 checks, 2 triggers; 30 objects |
| Complete P6 inventory | 9 tables, 172 columns, 71 indexes, 25 FKs, 49 checks, 13 triggers |
| Table | `supplier_product_source_revisions`; InnoDB / Dynamic / `utf8mb4_unicode_ci`; owner comment `mycomputer:phase-iii-p0:v1:owner=P0-06` |

The 16 ordered columns are `id`, `supplier_product_identity_head_id`,
`supplier_product_id`, `supplier_import_source_execution_id`, `supplier_id`,
`supplier_feed_id`, `supplier_sku_bytes`, `source_identity`,
`source_descriptor_fingerprint`, `source_execution_fingerprint`,
`source_member_identity_hash`, `source_row_fingerprint`,
`staging_projection_fingerprint`, `revision_fingerprint`, `observed_at`,
`created_at`. There is no `updated_at`, pointer or backfill in this table step.
All columns are non-null. The first six are unsigned BIGINT; `id` is the
auto-increment primary key. SKU is `VARBINARY(1020)`, source identity is
`VARCHAR(128)` ASCII/`ascii_bin`, and the six fingerprints are `CHAR(64)`
ASCII/`ascii_bin`. `observed_at` is TIMESTAMP(6) without a supplied SQL default;
`created_at` is TIMESTAMP(6) with `CURRENT_TIMESTAMP(6)`. Preserve UTC semantics.

Keys and relationship obligations:

- `PRIMARY(id)` and
  `uq_supplier_product_revision_execution_head(supplier_import_source_execution_id, supplier_product_identity_head_id)`
  establish row identity and at most one revision per execution/head pair.
- `uq_supplier_product_revision_pointer_scope(id, supplier_product_id, supplier_product_identity_head_id, supplier_id, supplier_feed_id)`
  prepares the exact future pointer target, but creates no pointer.
- `fk_supplier_product_revision_execution` binds the six-field tuple
  `(supplier_import_source_execution_id, supplier_id, supplier_feed_id,
  source_identity, source_descriptor_fingerprint, source_execution_fingerprint)`
  to the matching execution tuple, with its first field referencing execution
  `id`. Its explicit index is `ix_supplier_product_revision_execution_fk`.
- `fk_supplier_product_revision_head` binds
  `(supplier_product_identity_head_id, supplier_id, supplier_feed_id, supplier_sku_bytes)`
  to head `(id, supplier_id, supplier_feed_id, supplier_sku_bytes)`, with
  `ix_supplier_product_revision_head_fk` and byte-exact SKU comparison.
- **Temporary P6 constraint:** `fk_supplier_product_revision_product_id`
  references only `supplier_products(id)`, supported by
  `ix_supplier_product_revision_product_id_fk`. It is not composite staging
  ownership. Every one of the three FKs uses UPDATE/DELETE RESTRICT.
- `chk_supplier_product_revision_fingerprints` requires six exact lowercase
  64-hex values. `chk_supplier_product_revision_source_identity` enforces
  SKU byte length 1..1020 and canonical `snapshot-source-v1:` identity grammar
  of length 1..128. Neither check computes a digest or validates source provenance.
- `trg_supplier_product_revision_no_update` and
  `trg_supplier_product_revision_no_delete` reject every row UPDATE/DELETE
  with SQLSTATE 45000, including a no-op UPDATE.

Preserve fixed table collation, trigger SQL mode and trigger creation session
(`cp866` / `cp866_general_ci`). Trigger `database_collation` is
`ENVIRONMENT_DERIVED_DATABASE_COLLATION`: independently read the schema default
and require the inspector's raw trigger metadata to match that exact value
before normalization. Do not hardcode the database default or accept either of
two values indiscriminately. The existing inspector/comparator stay unchanged.

## Dependencies and the P0-07 Boundary

P0-03 supplies immutable source execution/resolved-context identity and its
six-field referenced key. P0-04 supplies receipt-bound payload provenance for
future row fingerprints; P6 has no invented direct receipt FK. P0-05 supplies
the immutable logical head and its exact supplier/feed/SKU tuple.

At P0-07, and not here, the temporary product-ID FK is replaced with
`fk_supplier_product_revision_product_scope` over
`(supplier_product_id, supplier_product_identity_head_id, supplier_id, supplier_feed_id)`
targeting the matching staging tuple under
`uq_supplier_product_source_revision_scope`. P0-07 adds the staging head and
current-revision pointer, composite ownership and guarded projection/pointer
transitions. Existing legacy staging rows remain pair-null under that separate
contract; P0-06 must not introduce those columns or bind/backfill them.

Consequently P6 proves execution/head ownership and existence of a staging ID,
but **cannot prove that the staging row belongs to that head/supplier/feed**.
It also cannot enforce pointer CAS, projection-to-current-revision consistency
or complete fingerprint semantics. Record this gap in a synthetic negative
control; do not claim that P6 rejects cross-scope staging solely by its ID FK.
This is a reason to keep the foundation dormant, not permission to weaken or
silently extend P6.

## Bytes and Fingerprints

The canonical sections on stable logical supplier-product heads, immutable
source revisions and deterministic fingerprint recipes remain binding.
Reuse the P0-05 validation contract: validate raw UTF-8 and forbidden Unicode
Cc/Cf before its exact trim boundary; apply the frozen Unicode classification;
preserve the resulting exact SKU bytes. Do not add NFC/NFD, generic whitespace
normalization, case folding or lossy character replacement. SQL length checks
are not substitutes for this application validation.

`source_member_identity_hash` uses the existing
[`OperationalSupplierOfferIdentityHasher::supplierSku()`](../app/Services/Suppliers/Onboarding/OperationalSupplierOfferIdentityHasher.php)
contract. Execution/descriptor identities must be those bound by P0-03/P0-04,
not newly inferred from a mutable source. The canonical recipes distinguish:

- `supplier_import_source_row_v1`: execution and receipt fingerprints,
  accepted-payload digest/byte length, member identity, source ordinal and
  parser projection, together with the ordered schema/scope fields.
- `supplier_product_staging_projection_v1`: the fixed canonical source-owned
  staging projection, including its declared fields; not catalog `product_id`,
  `synced_at`, status, mapping notes or ordinary model timestamps.
- `supplier_product_source_revision_v1`: the exact head/staging/execution/scope
  tuple, SKU bytes, source identity, descriptor/execution/member/row/projection
  fingerprints and UTC-microsecond `observed_at` in the declared order.

For those recipes use the exact canonical domain + NUL + fixed-order canonical
JSON, SHA-256 and field-type rules: explicit nulls, canonical decimal strings,
integer fields as integers, validated UTF-8, sorted nested-object keys and
preserved list order. This prose is not a replacement payload definition.
Independent golden vectors must precede any future value/hash implementation;
candidate output or an inspected DB must never generate its own expected oracle.

This foundation's byte tests can prove binary storage/ownership, exact
readback, format rejection and use of the existing member identity contract.
They cannot prove an unimplemented parser-projection or revision fingerprint
builder. Such semantic tests stay deferred, not marked PASS by using arbitrary
valid-looking synthetic hashes.

## Future Writer Contract, Explicitly Deferred

No revision participant is proposed now. A later private participant must be
part of the canonical whole caller-owned transaction:

1. Validate raw identity before opening the transaction; INSERT the logical
   head first, classify only the exact unique-key duplicate, then lock and
   compare its bytes. Do not use SELECT-then-INSERT as allocation authority.
2. Resolve/bind the unique SupplierProduct under that same head lock. Legacy
   exact-byte matches follow the canonical 0/1/multiple resolution; ambiguous
   legacy rows fail closed, not automatic cleanup or arbitrary selection.
3. INSERT or reuse the immutable execution/head revision with exact content
   comparison, never overwrite an existing row to make a retry succeed.
4. CAS the staging source projection and current pointer under P0-07, and
   commit all head + staging binding + revision + pointer/projection changes
   together. Failure at any boundary rolls back the complete caller unit.

Exact committed retry may reuse identical evidence; a duplicate pair with
different content is a conflict, not idempotent success. The future writer must
use deterministic head lock ordering and the canonical conflict/re-read policy.
A participant must propagate deadlock, lock timeout and DB errors; it must not
start its own transaction, commit, roll back or locally retry. Only the outer
transaction owner can retry a known-rolled-back complete unit. Lost connection
or uncertain commit outcome is not a safe retry signal. No committed orphan
head or partial source revision may be treated as a completed import.

The existing private
[`SupplierProductIdentityHeadRepository`](../app/Repositories/Suppliers/SupplierProductIdentityHeadRepository.php)
is a reference for boundaries, not an operational caller to activate. P0-07 and
an independently reviewed complete writer are prerequisites for integration.
P0-08 claim-source and P0-09 policy authority are also outside this proposal.

## Guarded Local Downgrade

Reference canonical `phase-iii-p0-prefix-downgrade-contract-v1` and
`P0-06-DOWN-01`. P6 -> P5 is **local/testing only**, never a staging rollback
instruction. Reuse the existing coordinator's invocation-local single-step
authorization, disabled gates, dedicated MySQL connection, zero-wait named
mutex, complete pre-DDL inspection and post-DDL evidence protocol.

Require exact terminal P6, zero revision rows, no current pointer or inbound
dependency, and absence of P7-P9 artifacts. Reject malformed/extra/missing
objects, non-terminal requests, populated revisions, dependent objects,
wrong environment, unauthorized/nested/concurrent calls and changed session
with zero DDL. Do not require valid predecessor heads/executions/receipts or
ordinary catalog/staging data to be empty: they are preserved.

The existing operation is a single MySQL 8.4 atomic DDL statement,
`DROP TABLE supplier_product_source_revisions`, with exact spelling taken
from the frozen operation, removing exactly the 30 P0-06 objects. Re-enumerate
and match exact P5 and its hash before declaring success. There is no P0-06
multi-statement partial-state family. This does not make DDL an application
transaction or guarantee outcome across a lost connection.

Keep primary uncertainty/failure separate from secondary mutex-release errors;
preserve evidence, discard stale authorization and never retry blindly. Any
future continuation needs fresh classification under the existing contract.
Operational recovery remains **forward-only and evidence-preserving**.

## Proposed Future File Allowlist

**Not authorized by this document.** Independent scope review and a separately
approved canonical slice/adoption decision must precede implementation. No
canonical edits are smuggled into this proposed implementation list.

| Proposed file | Minimal responsibility and boundary |
| --- | --- |
| `database/migrations/2026_08_28_090005_create_supplier_product_source_revisions_table.php` | Proposed filename/order following P0-05; thin P0_06 forward/down wrapper only; confirm availability before adoption |
| `database/migrations/support/CanonicalSupplierPhaseThreeP0Schema.php` | Add only exact P0-06 forward/down dispatch and pristine/dependency handling through existing guarded DDL machinery; no changes to P0-01..05 behavior |
| `app/Models/SupplierProductSourceRevision.php` | Read/hydrate-only immutable record, exact-byte fields and immutable timestamp casts; prohibit create/save and all mutations |
| `tests/Feature/PhaseThreeP0SourceRevisionFoundationMysqlTest.php` | Proposed focused MySQL 8.4 schema/guard/model/preservation vectors; synthetic SQL fixtures, no live importer |
| `tests/Feature/SupplierOfferLifecycleDocumentationContractTest.php` | Only separately adopted presence/allowlist/zero-activation expectations; retain historical negative cases and exact protected authority checks |
| `tests/Feature/PhaseThreeP0SliceOneMysqlTest.php` | After fresh P6, add the guarded terminal P6 -> P5 preparation below, then retain the existing separately authorized P0-05/P0-04/P0-03 downs and original P4/P2 assertions; no change to the Slice 1 subject |
| `tests/Feature/PhaseThreeP0SliceTwoMysqlTest.php` | After fresh P6, add the same guarded P6 -> P5 preparation, then retain the existing separately authorized P0-05/P0-04 downs and original P4/P3 assertions; no change to the Slice 2 subject |
| `tests/Feature/PhaseThreeP0SliceThreeMysqlTest.php` | After fresh P6, add the same guarded P6 -> P5 preparation, then retain the existing separately authorized P0-05 down and original P4 assertions; no change to the Slice 3 subject |
| `tests/Feature/PhaseThreeP0SliceFourMysqlTest.php` | After fresh P6, add the same guarded P6 -> P5 preparation before the original exact P5 assertion; retain all existing P5/P4 scenarios, data and Super Admin preservation checks |

### Legacy Slice Fixture Preparation (F1)

Static inspection at the review baseline shows that Slice 1, 2 and 3 call
`migrate:fresh` and then P0-05 `down()` directly (respectively lines 40-48,
48-53 and 56-61 of the four-suite allowlist's first three files). Slice 4 calls
`migrate:fresh` and immediately asserts P5 at lines 63-64. A future normally
discovered P0-06 migration makes fresh reach P6. The existing coordinator's
terminal-state guard correctly rejects P0-05 down from P6, and Slice 4 would
not receive its expected P5. This is a predicted future fixture incompatibility
from code inspection, **not an executed or observed current MySQL failure**.

For each of these four legacy suites, propose only this preparation in a
task-owned disposable MySQL 8.4 database:

1. Let normal `migrate:fresh` reach and verify exact fresh P6. Do not exclude
   the P0-06 migration or substitute a pre-P6 migration list.
2. Supply a fresh invocation-local authorization for the canonical guarded
   terminal P0-06 down, P6 -> P5. Preserve the terminal-step, pristine revision,
   dependency, environment, connection and mutex predicates; no direct DROP,
   arbitrary SQL or skipped migration step is allowed.
3. Immediately verify consumption of this separate authorization, including
   its removal from process environment, `$_ENV` and `$_SERVER`, and verify the
   exact P5 prefix, complete schema/oracle signature and frozen P5 hash. Do not
   accept an arbitrary recognized prefix or reuse the consumed grant.
4. Continue each suite's original scenario. Any subsequent P0-05/P0-04/P0-03
   down receives its own separately consumed authorization, with the original
   P5/P4/P3/P2 assertions, exact schema checks, negative guards, protected-data
   and Super Admin checks retained without weakening or test exclusion.

This is narrowly scoped legacy fixture preparation, not a global setup hook
that hides P6. The new P0-06 foundation suite must inspect **true fresh P6**
before any downgrade; it separately proves P5 -> P6 and guarded P6 -> P5.
The four added PHP paths are future proposed work only. No PHP file is edited
or executed in this documentation revision.

Existing `P0MigrationStep::P0_06`, oracle, inspector, comparator, Unicode data,
identity hasher, identity-head participant, receipt implementation, factories,
providers, routes, jobs, schedules, flags and all unrelated tests remain
unchanged. If tests expose a need outside this list, stop for scope review.
No public write repository, new DTO or service container binding is included.

The current closed-world documentation contracts correctly reject an
unauthorized P0-06 implementation. Future adoption must resolve that boundary
explicitly, without weakening guards or refreshing schema/semantic hashes.
This documentation task changes neither those tests nor the canonical design.

## Future Acceptance Matrix

Every row below is **NOT RUN**. These are proposed obligations, not evidence of
an implemented foundation or a request to provision a DB now. Use independent
synthetic fixtures and the unchanged frozen oracle on MySQL 8.4; a skip or
SQLite-only result does not satisfy a required MySQL case.

| Case | Required evidence and failure boundary |
| --- | --- |
| A1: exact schema | Fresh and populated exact P5 -> exact P6; 339 objects / frozen P6 digest; independently attest schema default and trigger metadata under `utf8mb4_0900_ai_ci` and `utf8mb4_unicode_ci`; preserve fixed table/creation-session collations |
| A2: zero-DDL guards | Missing middle object, same-name wrong signature, extra shared object, later prefix, enabled gate or unavailable guard/session fails before destructive DDL; no false rollback claim after uncertain DDL |
| A3: immutable record | Model save/create/update/delete/touch/increment paths reject; direct SQL UPDATE including no-op and DELETE reject; raw rows and timestamps unchanged; read/hydration retains exact bytes |
| A4: ownership | Execution tuple and head supplier/feed/SKU mismatches plus nonexistent staging ID reject. Explicitly document P6's cross-scope *existing* staging-ID gap without pretending P0-07 protection exists |
| A5: identities/uniqueness | Exact SKU byte readback; wrong lengths, invalid source grammar and non-lowercase/non-64 fingerprints reject; duplicate execution/head SQL insert rejects. Golden semantic fingerprint and application retry tests remain deferred to the writer stage |
| A6: transaction boundary | Synthetic revision insert inside an owner transaction rolls back with zero persisted partial rows and unchanged predecessors; SQL constraint failure is not caught as success. No application repository/idempotent writer is introduced |
| A7: terminal downgrade | Empty P6 -> exact P5 with independently populated valid predecessors preserved; populated revisions/inbound dependency/wrong terminal step reject with zero DDL; safe structured evidence and primary/secondary outcomes verified |
| A8: protected data | Before/after complete synthetic Product and supplier_products content/timestamps, predecessor rows, active Super Admin ID/role/deleted/active state unchanged; never production rows |
| A9: dormancy/discovery | Exact adopted file set, no public write API, callers/bindings/jobs/schedules/import/capture integration; no P0-07 columns/backfill, no P0-08/P0-09; all gates and commerce rules unchanged |
| A10: regression | All four existing Slice 1-4 MySQL suites named in the allowlist and the new `PhaseThreeP0SourceRevisionFoundationMysqlTest` must pass on real MySQL 8.4, together with applicable coordinator/oracle/documentation and immutable-model regressions, PHP syntax/Pint, protected data/admin/sync checks and required CI. Record exact tests/assertions/failures/errors/skips, observed exits and same-byte bindings; include evidence that the original Slice assertions/guards were not weakened. A skip or SQLite result is not a required MySQL PASS |

All future acceptance cases, including A10 and the legacy-suite adaptation,
remain **NOT RUN** in this documentation candidate. Historical PR #234 CI
validates its own earlier bytes; it is not evidence for the future P0-06
implementation or its changed fixture preparation.

App-level concurrent retry, competing-import CAS and whole head/staging/revision/
pointer rollback tests are deferred with the writer, not waived. When separately
included, use independent processes and synchronization evidence, exact conflict
classification, known rollback versus uncertain commit cases, and zero partial
writes at every boundary. Single-process SQL uniqueness alone cannot prove them.

## Open Decisions and Review Gates

1. Independently review schema-only plus guarded-model scope; confirm it is
   useful without a revision writer. A request for a writer requires a separate
   broader proposal, not an implicit expansion of this list.
2. Explicitly decide canonical slice adoption/name, migration filename and
   presence-test allocation in a later authorized task. This text does not
   change `NOT_SLICED_NOT_AUTHORIZED` or grant implementation permission.
3. Before any hash/value implementation, settle the independent golden-vector
   inputs and receipt/parser/projection boundaries. None are newly specified
   or proven by this proposal.
4. Have Laravel/Database, Supplier Import, Catalog Sync Safety, Security and
   QA/Documentation reviewers verify the exact frozen transition, P6/P7 gap,
   local-down restrictions, legacy-suite preparation, unchanged protected data
   and absence of runtime entry points. This matches the Phase III-P0 PR gate
   in the [runtime plan](PHASE_9C6_5C3D1_RUNTIME_IMPLEMENTATION_PLAN.md):
   independent Database, Supplier Import, Security, QA and Catalog Sync Safety
   review with real MySQL 8.4 for schema authority. These are review
   responsibilities, not runtime agents. Reviewers must not treat P0-05
   deployment or historical CI as P0-06 acceptance.

All ten operational bounds remain **NOT SPECIFIED**:
`max_source_rows`, `max_source_bytes`, `max_spool_rows`, `max_spool_bytes`,
`max_enrollments`, `max_observations`, `max_canonical_children`,
`external_sort_chunk`, `db_insert_batch_ceiling`, `snapshot_transaction_bound`.
No numeric default is supplied and `PH3-RDY-003` remains blocked.

UPDATE, Sync All, automatic sync and capture stay closed. No source fetch,
parser/capture integration, supplier staging mutation, catalog write, import,
backfill, rights change, P1 or subsequent Phase III activation is authorized.
The next action is independent review of these documents, not implementation.

P0-06 IMPLEMENTATION: NOT STARTED.
RUNTIME ACTIVATION: NOT PERFORMED.
VPS ACTIONS IN THIS TASK: NONE.
COMMIT / PUSH / PR / MERGE: NOT PERFORMED.

# Staging Source Revision Pointers Foundation Proposal

## Explicit proposal supersession — 2026-10-26

2026-10-26 isolated canonical candidate: the five-document design package is OWNER APPROVED. New P7/successor oracle literals are CANDIDATE — NOT OWNER APPROVED. Original-worktree adoption and P0-07 implementation/runtime activation are NOT PERFORMED.

The following frozen P7=349/reverse (p,h,s,f)/three-down proposal is historical.
Its zero-revision forward prerequisite and A2/A8/A9 are superseded in this
isolated candidate by [the canonical reconciliation](IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md#p0-07-d1-d3r2-candidate-reconciliation-2026-10-26).
Current A2 requires preservation of compatible populated P6 and independent
pre-DDL rejection of incompatible scope. A8 requires four down operations with
three exact partial boundaries. A9 covers P7_DOWN_1/2/3, fresh authority and
uncertain-outcome STOP. Coordinator implementation scope is four-operation
terminal/partial down; allowlist B remains unimplemented and unauthorized here.
Historical values below are not concurrent executable authority.

## Original proposal checkpoint (historical)

Status: PROPOSED / UNAPPLIED. Local documentation candidate, 2026-10-21.
Proposed designation: **P0-07 Staging Source Revision Pointers Foundation**.
No new Slice number is adopted. This document grants no implementation,
canonical adoption, migration, importer integration or runtime authority.

Baseline: `b2d7e0bb428745dfe9d0e39a6973fd34a5cf371c`, also actual GitHub main
at the 2026-10-21 read. Historical deployment attribution is in the
[Slice 5 staging record](PHASE_III_P0_SLICE_5_STAGING_EVIDENCE_2026_10_20.md).

## Authority and Recommended Boundary

Read with [AGENTS](../AGENTS.md), [AI roles](AI_AGENTS.md),
[canonical persistence design](IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md),
[runtime plan](PHASE_9C6_5C3D1_RUNTIME_IMPLEMENTATION_PLAN.md#future-phase-iii-p0-provenance-migration-allocation),
[P0-06 proposal](PHASE_III_P0_SOURCE_REVISION_FOUNDATION_PROPOSAL.md),
[Supplier Import](SUPPLIER_IMPORT.md), [Catalog Sync Safety](CATALOG_SYNC_SAFETY.md),
[Sync Safety](SYNC_SAFETY.md), [Data Ownership](DATA_OWNERSHIP.md) and
[Testing](TESTING.md). All source line references below are baseline-bound.

The smallest intended implementation is exact P6 -> P7 schema coordination and
isolated acceptance tests, with no production writer or model mutation API.
P0-07 depends directly on P0-05 identity heads and P0-06 immutable revisions
(canonical migration registry, line 4204); their P0-01..04 prerequisites remain.
Current coordinator forward/down dispatch ends at P0-06. An enum member or a
frozen P7 oracle entry is not executable implementation authority.

**The current frozen P7 contract is not yet a demonstrably compatible dormant
upgrade for active legacy staging.** The conflicts below require a separate
design decision before an implementation work order. Do not implement a subset
of P7, silently modify its trigger, disable constraints, or integrate importers
to conceal this issue. This proposal is reviewable; implementation is not ready.

Logical review lenses used: Coordinator/Documentation, Laravel schema and QA,
Supplier Import/Catalog Sync Safety, Release evidence and Security. Requested
`mycomputer-development-workflow` and `mycomputer-catalog-sync-safety` skills
were not available in the supplied skill inventory; repository rules apply.
These are not independent reviewer approvals.

## Exact Existing Registry Target

This section transcribes existing authority, not a replacement registry or a
hash generated from future implementation. Source: canonical artifact registry,
frozen object signatures (lines 4445, 4625-4626, 4650-4652, 4713-4736, 4761),
state registry (4789-4792) and operation registry (4889-4891); the corresponding
read-only oracle is
[CanonicalSupplierPhaseThreeP0Oracle.php](../database/migrations/support/CanonicalSupplierPhaseThreeP0Oracle.php).

| Prefix | Tables / columns / indexes / FKs / checks / triggers | Objects | SHA-256 |
| --- | --- | ---: | --- |
| P6 | 9 / 172 / 71 / 25 / 49 / 13 | 339 | `ea265e4717c2f61abba6731f08be576ff6fcd01fe6b673d61d41011a888056aa` |
| P7 | 9 / 174 / 75 / 27 / 50 / 14 | 349 | `87a549668d891206e5bd29e75514616916d71b87708f2d964a8824c8b3c0bebe` |

P0-07 introduces 12 object signatures and retires the two temporary P6
index/FK signatures: net +10, not a new table or revision-column change.

For the compact tuples below, `h = supplier_product_identity_head_id`,
`r = current_source_revision_id`, `s = supplier_id`, `f = supplier_feed_id`,
`p = supplier_product_id`. These aliases are explanatory, never SQL identifiers.

New `supplier_products` columns, ordinals 27 and 28, are respectively `h` and
`r`: `bigint unsigned`, nullable, SQL NULL default, no generated expression,
extra or comment. No existing row is assigned either value by migration.

| Table | Exact index | Ordered full columns | Kind |
| --- | --- | --- | --- |
| supplier_products | `uq_supplier_product_identity_head_assignment` | h | UNIQUE |
| supplier_products | `uq_supplier_product_source_revision_scope` | id, h, s, f | UNIQUE |
| supplier_products | `ix_supplier_product_identity_head_fk` | h, s, f | nonunique |
| supplier_products | `ix_supplier_product_current_revision_fk` | r, id, h, s, f | nonunique |
| supplier_product_source_revisions | `ix_supplier_product_revision_product_scope_fk` | p, h, s, f | nonunique |

All five indexes are BTREE, ascending, without expressions or prefix lengths.
The revision index replaces `ix_supplier_product_revision_product_id_fk(p)`.

| Exact FK | Child -> parent, ordered columns |
| --- | --- |
| `fk_supplier_product_identity_head` | supplier_products(h,s,f) -> supplier_product_identity_heads(id,s,f) |
| `fk_supplier_product_current_revision` | supplier_products(r,id,h,s,f) -> supplier_product_source_revisions(id,p,h,s,f) |
| `fk_supplier_product_revision_product_scope` | supplier_product_source_revisions(p,h,s,f) -> supplier_products(id,h,s,f) |

Every FK is ON UPDATE RESTRICT / ON DELETE RESTRICT. The third replaces
`fk_supplier_product_revision_product_id(p -> supplier_products.id)`; retaining
both old and new artifacts would not be exact P7.

`chk_supplier_product_source_revision_pointers` is enforced: both pointers NULL
or both non-NULL, never a half-pair. The unique head index allows multiple
legacy NULL rows but not assignment of one non-NULL head to multiple products.

`trg_supplier_product_source_projection_guard` is BEFORE UPDATE, ROW:

- A previously non-NULL head cannot change, including being cleared.
- The new pointer pair must be complete.
- A changed revision pointer cannot be cleared and must select exactly one
  revision matching new `(r,id,h,s,f)`.
- If the revision pointer is unchanged, changes detected with `<=>` in the
  enumerated source fields signal SQLSTATE 45000. The list is supplier_sku,
  ean, mpn, name, brand_name, category_name, price, supplier_price_raw,
  recommended_price, quantity, external_availability_status,
  external_availability_label, availability_status_id, currency, raw_data,
  payload_hash and received_at.

Preserve fixed existing table collations and the exact trigger creation
session: client `cp866`, connection `cp866_general_ci`, and frozen strict
SQL mode. Trigger `database_collation` is
`ENVIRONMENT_DERIVED_DATABASE_COLLATION`: compare actual schema default from
independent metadata to raw trigger metadata before canonical normalization.
Do not hardcode `utf8mb4_unicode_ci` as a database default.

## Compatibility Findings and Required Design Decisions

### D1 - Legacy Updates Are Not Dormant Under the Frozen Trigger

Canonical line 4761 has no `OLD.h IS NOT NULL` condition around the source-field
ELSEIF. For an existing pair-NULL row, updating price or received_at while r
stays NULL therefore reaches the rejection. This is a static SQL/control-flow
finding; no P7 runtime test was performed in this task.

[ControlledSupplierStagingImportService](../app/Services/Suppliers/ControlledSupplierStagingImportService.php),
lines 168-257, fills/saves existing staging rows with source values and
received_at, without pointers. [XmlImportEngine](../app/Services/Imports/XmlImportEngine.php),
lines 102-128, uses updateOrCreate with the same kind of fields and then updates
staged attributes. Their existing-row paths conflict with that trigger even
when capture is disabled. CREATE-only paths leaving both pointers NULL do not
establish compatibility for updates.

[CatalogSyncPreviewService](../app/Services/Products/CatalogSyncPreviewService.php),
lines 33-78, selects/maps staging rows rather than performing a pointer CAS.
[ProductSyncService](../app/Services/Products/ProductSyncService.php), lines
170-175 and 429-433, updates product_id, status, synced_at and mapping_notes;
those specific fields are excluded from the trigger's source-field list.
This is a scoped static observation, not a full runtime compatibility PASS or
permission to enable UPDATE. Attributes and other callers still need tests.

Required separate decision: whether and how unbound legacy rows remain writable
while ineligible for protected admission. A narrowly gated legacy exemption
would change frozen trigger authority and requires separate canonical review;
it is NOT part of the allowlist below. Installing the current trigger and
calling the foundation behaviorally inactive is not acceptable.

### D2 - First Binding Has a Constraint Cycle

The pair CHECK forbids assigning h without r. A new revision requires the
existing staging tuple `(p,h,s,f)` through the replacement FK. Assigning both
staging pointers first requires that revision through the reverse FK and the
trigger's COUNT. For a normal non-NULL supplier/feed scope starting pair-NULL,
neither obvious insert-first nor head-first sequence satisfies all three.
MySQL statement-time constraints do not become deferred just because the
future writer uses one transaction. This is a static incompatibility requiring
an isolated MySQL proof during separately authorized design work, not a test
result claimed here. Do not seed tests by disabling FK/CHECK/trigger enforcement.

Required separate decision: an explicit, constraint-valid initial-binding
protocol and its canonical authority. Merely deferring a writer does not make
the proposed complete-pair state constructible. No importer integration,
backfill or ad hoc transient state is silently added to solve it.

### D3 - Projection Equality and CAS Are Not Proved by This Trigger

Canonical prose at lines 5985-5998 promises rejection of a revision whose
projection does not match. The frozen body at line 4761 only verifies the
revision tuple when r changes. It does not recompute or compare
staging_projection_fingerprint, check staged-attribute contents, or enforce an
application `WHERE current_source_revision_id = expected_old` CAS predicate.
It is an UPDATE trigger, not an INSERT guard; string `<=>` follows field
collation and is not a universal raw-byte comparison. Nullable existing scope
columns also need explicit tests, not assumptions about composite FK coverage.

Required separate decision: reconcile the stronger prose with exact SQL and
the future writer/admission obligations. This proposal does not weaken the
prose, modify the oracle, or claim SQL proves semantic fingerprints. Until
D1-D3 are resolved, the proposed implementation is conditional, not authorized.

## Populated Forward Migration and Preservation

After separate design resolution, a foundation work order would require:

1. Exact live P6 classification and independent schema-default metadata, using
   the existing inspector/comparator, protected-gate checks and connection/mutex
   guard. Unknown objects, suffixes, foreign inbound dependencies or uncertainty
   mean STOP before DDL. Do not infer schema from migration-ledger count alone.
2. Existing legacy staging rows, supplier ownership, product/catalog content,
   attributes, workflow metadata and timestamps preserved. New pointers remain
   NULL/NULL; no backfill, hash derivation, data normalization or catalog writes.
3. **Proposed extra prerequisite: zero revision rows before forward DDL.** P6
   permits revisions bound only by staging ID. Their non-NULL head cannot match
   a legacy row's newly NULL head under the new composite FK. A populated P6
   revision table must fail closed before any DDL; do not delete evidence or
   manufacture a backfill. P5 heads and earlier evidence need not all be empty.
4. Exact DDL plan and pre/post inventories. Adding staging columns/keys,
   replacing revision ownership and installing a trigger span statements and
   tables. Per-statement atomic DDL is not atomicity of the whole migration.
5. After each known boundary, record raw metadata and actual outcome. On partial
   forward failure, preserve the last verified state and connection outcome;
   STOP without local retry, rollback, or pretending a down-partial hash is a
   generic forward recovery state. Later recovery needs its own authorization.
6. Final exact P7 and row-level preservation proofs, not just object/row counts.

The frozen P7 hash above remains a historical target while design decisions
are open. If a later approved contract changes it, version/review that authority
explicitly; never generate an expected oracle from implementation output.

## Guarded Terminal Downgrade and Partial States

Local/testing only. Operational rollback remains forward-only and
evidence-preserving. P7 -> P6 is not permission to roll back staging.
Require one-shot local authorization, capture gates disabled, exact terminal
state, all new pointers NULL, no P8/P9 or inbound dependencies, and a verified
live locked connection. Do not demand unrelated predecessor tables be emptied.

| Frozen operation | Exact transition | Result objects / SHA-256 |
| --- | --- | --- |
| P0-07-DOWN-01: drop projection trigger | P7 -> P7_DOWN_1 | 348 / `680a78d1b5c54488255b39da92e4995c3bcaa5d900f7b8f0baf40846c1f2c7be` |
| P0-07-DOWN-02: single ALTER replaces revision composite FK/index with the temporary ID FK/index | P7_DOWN_1 -> P7_DOWN_2 | 348 / `65dbb7dd13d42731577f66a58fd6b7bf02d6fe44988b7eaaad747a8a517702ea` |
| P0-07-DOWN-03: single ALTER removes the two staging FKs, CHECK, four indexes and two columns | P7_DOWN_2 -> P6 | 339 / `ea265e4717c2f61abba6731f08be576ff6fcd01fe6b673d61d41011a888056aa` |

Exact SQL is frozen at canonical lines 4889-4891; do not replace it with
name-based best-effort drops. Re-enumerate complete objects after each statement.
Partial state acceptance is exact signature/hash matching, not a subset test.
An explicit later invocation may resume only the authorized next operation
after fresh authorization, lock, live connection, full classification and
pristine/dependency checks. Never automatically replay a statement with an
uncertain outcome or accept an unrelated step's partial state.

Current [coordinator](../database/migrations/support/CanonicalSupplierPhaseThreeP0Schema.php),
lines 43-60 and 94-183, supports one down operation per implemented step and
requires its initial state. P0-07's three operations and controlled partial
resumption require explicit coordinator work; they do not already exist merely
because the frozen oracle lists them. Preserve consumed authorization on
failure, primary-vs-cleanup exception precedence, live-connection guards and
no reconnect/retry semantics. Partial failure needs durable evidence, not a
claim that PHP transaction rollback reversed committed DDL.

## SQL Versus Deferred Writer Authority

The future writer still needs independently approved exact semantic inputs,
receipt/execution/head binding, immutable revision creation, byte-safe
fingerprints, locked scope, compare-and-swap affected-row checks and caller-owned
transaction/error propagation. Those are not supplied by nullable columns or
by a successful migration. P0-06's read model is not a new public write API.

Excluded: writer/CAS implementation, semantic fingerprint implementation,
importer adapters, model fillable expansion, current-row rehash/backfill,
capture/admission, P0-08/P0-09, jobs/schedules, public/admin write endpoints,
runtime/service bindings and feature-flag changes. Product manual data,
Super Admin/access, supplier staging policy and selected-only catalog rules
remain unchanged. UPDATE, Sync All and automatic sync remain disabled.
All previously unfilled operational bounds remain NOT SPECIFIED.

## Conditional Future Implementation Allowlist

Exactly these nine proposed paths, only after separate design adoption and owner
implementation authority. The new timestamp/name is a proposal following the
existing 090005 migration; it is not an already adopted file. No file below is
edited now. Canonical D1-D3 remediation is a separate documentation/authority
change, not an unlisted permission to alter frozen files during implementation.

| Future path | Narrow reason |
| --- | --- |
| `database/migrations/2026_08_28_090006_add_source_revision_pointers_to_supplier_products_table.php` (NEW) | Guarded forward/down dispatch only, no writer or data backfill |
| `database/migrations/support/CanonicalSupplierPhaseThreeP0Schema.php` | Exact P0-07 forward, pristine/ownership checks, three-operation terminal/partial down; preserve P0-01..06 semantics |
| `tests/Feature/PhaseThreeP0StagingSourceRevisionPointersFoundationMysqlTest.php` (NEW) | Actual fresh P7, populated legacy preservation, constraint negatives, exact downgrade/partial/uncertain cases |
| `tests/Feature/SupplierOfferLifecycleDocumentationContractTest.php` | Explicit newly authorized artifact discovery and dormancy negatives, without filtering unknown artifacts or changing frozen expected bytes |
| `tests/Feature/PhaseThreeP0SliceOneMysqlTest.php` | Fresh reaches P7: exact P7 assertion then authorized P0-07 -> P6 before existing P0-06 -> P5 setup |
| `tests/Feature/PhaseThreeP0SliceTwoMysqlTest.php` | Same prerequisite adaptation, retain original Slice 2 acceptance |
| `tests/Feature/PhaseThreeP0SliceThreeMysqlTest.php` | Same prerequisite adaptation, retain original Slice 3 acceptance |
| `tests/Feature/PhaseThreeP0SliceFourMysqlTest.php` | Same prerequisite adaptation, retain both database-default cases and original negatives |
| `tests/Feature/PhaseThreeP0SourceRevisionFoundationMysqlTest.php` | Explicit P7 -> P6 preparation before original P6/P5 scenarios; preserve P0-06 ownership and byte assertions |

Current legacy setUp paths run normal migrate:fresh and assert P6. Do not filter
the migration list, hardcode older fresh results, erase unknown artifacts or
relax their assertions. The new P0-07 suite must exercise true fresh P7, not
only P7 reached through a hand-selected subset. P0-07 enum/oracle/inspector/
comparator are dependencies to reuse, not extra editable paths. If later proof
requires another file, stop and request a revised exact allowlist.

## Future Acceptance Matrix

Every row is **NOT RUN**. All DDL, FK, trigger, lock and partial-state proof
requires real isolated MySQL 8.4, not SQLite or source-text matching. The
existing P0-06 deployment/CI does not establish P0-07 acceptance.

| ID | Required proof | Status |
| --- | --- | --- |
| A1 | Normal full migration discovery reaches exact fresh P7: complete ordered objects/signatures/hash and raw metadata, no unexpected artifacts | NOT RUN |
| A2 | Representative populated P6 with legacy staging and predecessor evidence upgrades without changing old column bytes, timestamps, product/attribute/admin data; both new values NULL. Populated revisions reject before DDL | NOT RUN |
| A3 | NULL/NULL accepted; both half-pairs reject; constructible valid bound pair after D2 resolution; unique head assignment, head replacement and pointer clearing negatives | NOT RUN |
| A4 | Nonexistent and cross-product/supplier/feed/head/revision tuples reject; nullable legacy feed edge and INSERT vs UPDATE paths explicitly covered | NOT RUN |
| A5 | Unchanged-pointer source mutations reject for protected rows; same-pointer no-op and permitted workflow metadata; legacy importer updates after D1 resolution. Exact projection/attribute semantics are not falsely attributed to SQL | NOT RUN |
| A6 | Existing import/preview/selected-sync behavior and protected catalog data remain unchanged; flags off, no production callers, no backfill or new write endpoint | NOT RUN |
| A7 | Raw schema default and trigger database_collation agree under utf8mb4_0900_ai_ci and utf8mb4_unicode_ci; fixed table and creation-session contracts unchanged | NOT RUN |
| A8 | Terminal authorized all-null P7 -> P6, all three boundaries exact, populated legacy bytes preserved; reject missing/consumed grants, non-null pointers, suffixes/inbound dependencies and altered schema before destructive DDL | NOT RUN |
| A9 | Fail/inject at each DDL boundary; exact P7_DOWN_1/2 resume only under fresh authority; unknown partial/connection loss/uncertain outcome STOP; primary failure survives cleanup failure | NOT RUN |
| A10 | Real competing processes/locks, guard ownership, no hidden reconnect/retry or application transaction assumption; prove post-DDL enumeration and consumed authorization | NOT RUN |
| A11 | All five predecessor MySQL suites adapted explicitly; original assertions/negative cases retained. Documentation discovery fails for extra artifacts; frozen oracle tests unchanged | NOT RUN |
| A12 | Linux syntax, Pint, relevant regression then full backend under documented isolated DB/runtime; report actual skips/warnings/exits, never infer PASS from missing output | NOT RUN |

Future repository commands, not executed here: `php artisan test` with the
new foundation test and the five listed predecessor classes;
`php artisan test tests/Feature/SupplierOfferLifecycleDocumentationContractTest.php`;
`php artisan test tests/Unit/Suppliers/SourceProfiles/CanonicalSupplierPhaseThreeP0OracleTest.php`;
`vendor/bin/pint --test`; `php artisan test`; `git diff --check`.
Windows documented equivalents use `.\.tools\php\php.exe` (AGENTS/TESTING).
No performance/lock/retry budget or new memory limit is invented here.

## Current Documentation Validation and Next Decision

Only the two new documents are in this candidate; canonical docs, source,
configuration, oracle and tests are unchanged. Validation is scope, UTF-8,
links/anchors, source-bound facts, whitespace, secret-pattern scan, full diff
and preservation. Documentation contract execution is NOT RUN: dependencies
and bundled PHP are absent in this isolated worktree, and no canonical contract
input changed. No dependencies, database or Docker environment were installed.

The next single step is independent documentation/proposal review of this pair,
including D1-D3 and the conditional nine-file boundary. If accepted as a design
finding, separately authorize exact canonical remediation before implementation.
Do not use this proposal or historical staging success as runtime approval.

P0-07 IMPLEMENTATION: NOT STARTED.
CANONICAL ADOPTION / RUNTIME ACTIVATION IN THIS TASK: NOT PERFORMED.
VPS ACTIONS IN THIS TASK: NONE.
COMMIT / PUSH / PR / MERGE: NOT PERFORMED.

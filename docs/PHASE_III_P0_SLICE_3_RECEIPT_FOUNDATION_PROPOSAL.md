# Phase III-P0 Slice 3: immutable receipt foundation proposal

## Decision boundary

Defined canonical phase: **Phase 9C.6.5C.3D - Phase III-P0 Slice 3**.
Name: **Immutable Source Payload Receipt Foundation - P0-04 only**.
Canonical status: `DEFINED_NOT_IMPLEMENTATION_AUTHORIZED`.

This is a NON-AUTHORITATIVE PROPOSAL subordinate to the revised canonical
design and awaiting fresh independent review. It is not a second schema,
semantic registry or
current readiness map. No implementation, migration execution, runtime
activation, commit, push, PR, merge or deployment is authorized by this plan.
A later explicit repository-owner implementation decision is required.
The dated applied revision record below identifies the exact owner-approved
prose delta and independently fixed acceptance expectations.

Verified baseline: `21b201df9b159d7289c7538f56877890c764302a`.
Slice 2's P0-03 foundation merged through PR #222; see the
[dated staging evidence](PHASE_III_P0_SLICE_2_STAGING_EVIDENCE_2026_09_05.md).
That record separates GitHub/CI verification from owner-provided operational
observations. Slice 1 remains complete and dormant. Neither completion grants
activation. The protected sequence now records completed P0-03 and defined
P0-04-only Slice 3. P0-04 remains unimplemented and not implementation-authorized;
P0-05 through P0-09 remain unimplemented, unsliced and unauthorized.

## Sources, not competing registries

The [canonical persistence design](IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md#phase-iii-provenance-and-bounds-architecture-decision)
owns the migration, artifact, prefix, object-signature, state-signature,
downgrade-operation and payload-integrity registries.
The [subordinate runtime plan](PHASE_9C6_5C3D1_RUNTIME_IMPLEMENTATION_PLAN.md)
cannot override them. Refer to these exact identifiers:

- `phase-iii-p0-slice-sequence-v1`: lifecycle/next-slice prose updated by the exact approved patch only.
- P0-04 migration and artifact rows, P4 prefix row: no SQL, membership or object change proposed.
- `phase-iii-payload-integrity-contract-v1`: unchanged full receipt/handle/EOF semantics.
- `phase-iii-p0-object-signatures-v1`, `phase-iii-p0-state-signatures-v1`,
  `phase-iii-p0-downgrade-operations-v1`: unchanged literal authorities.

Existing code inspected as implementation precedent, not a replacement oracle:
[coordinator](../database/migrations/support/CanonicalSupplierPhaseThreeP0Schema.php),
[oracle](../database/migrations/support/CanonicalSupplierPhaseThreeP0Oracle.php),
[execution value](../app/Data/Suppliers/Imports/CanonicalSupplierImportSourceExecution.php),
[execution model](../app/Models/SupplierImportSourceExecution.php),
[execution repository](../app/Repositories/Suppliers/SupplierImportSourceExecutionRepository.php),
[context resolver](../app/Services/Suppliers/SupplierImportSourceContextResolver.php),
[JSON encoder](../app/Data/Suppliers/Onboarding/CanonicalOnboardingData.php),
[Slice 1 MySQL regressions](../tests/Feature/PhaseThreeP0SliceOneMysqlTest.php) and
[Slice 2 MySQL regressions](../tests/Feature/PhaseThreeP0SliceTwoMysqlTest.php).
The coordinator currently implements only P0-01, P0-02 and P0-03. Recognizing
P4 in the existing oracle is NOT an implemented P0-04 forward/down path.

## Minimal future artifact responsibilities

Every row below is proposed future work, not an existing class/method claim.
Where the registry fixes a name, it is retained. The receipt value and repository
class names below follow the existing execution artifacts and are proposed
implementation names; no new public runtime API is authorized.

| Proposed artifact | Responsibility and dependencies | Allowed future effects | Acceptance |
| --- | --- | --- | --- |
| `*_create_supplier_import_source_payload_receipts_table.php` plus existing coordinator's P0-04 step | Literal P0-04 registry transcription; exact P3 prerequisite and P4 oracle; existing dedicated connection, guard and session/collation checks | Only the canonical receipt table/keys/checks/triggers; no new oracle or generated expected SQL | F1, F2, F3, F9 |
| `SupplierImportSourcePayloadReceipt` model | Canonical named append-only model; existing immutable and mass-assignment guards; connection-bound hydration; immutable execution relationship | No editable/update/delete API; repository-only insertion | F2, F3, F10 |
| `CanonicalSupplierImportSourcePayloadReceipt` value | Validate the existing five-field identity, canonical bytes and fingerprint; reconstruct from immutable stored metadata | Pure computation; no DB, file, network or timestamp generation in fingerprint | F4 |
| `SupplierImportSourcePayloadReceiptRepository` | Transaction-required exact insert/reuse; explicit connection; persisted execution identity; complete canonical comparison and conflict rejection | One complete receipt row or no write; no execution/profile change | F5, F6, F7, F8 |
| Isolated unit/feature/MySQL tests | Synthetic execution/receipt fixtures only; separate MySQL processes for races; frozen P0/Phase I/II comparisons | Disposable test resources only | F1-F10 |

No arbitrary receipt CRUD, update-or-create, replacement, mutable status column,
new execution on retry or convenience profile creation is part of Slice 3.
Future implementation must leave all production callers absent. Direct isolated
tests are the only proposed callers; a dormant repository does not itself prove
transport finalization. This proposal does not add a test-only bypass to the
production interface.

## Exact persistence reference

The existing P0-04 row fixes eight columns in this order:
`id > supplier_import_source_execution_id > source_execution_fingerprint >
receipt_version > accepted_payload_bytes > accepted_payload_sha256 >
payload_receipt_fingerprint > created_at`.

IDs are non-null unsigned BIGINT; primary `id` is auto-increment.
`source_execution_fingerprint`, `accepted_payload_sha256` and
`payload_receipt_fingerprint` are non-null ASCII/ascii_bin CHAR(64), lowercase
hex. `receipt_version` is non-null ASCII/ascii_bin VARCHAR(96), exactly
`supplier_import_source_payload_receipt_v1`.
`accepted_payload_bytes` is non-null unsigned BIGINT and strictly positive.
`created_at` is non-null UTC TIMESTAMP(6), excluded from fingerprint input.
Exact defaults, CHECK expressions, collation/session behavior and SQL remain
those of the literal oracle, not inferred from these readable summaries.

Retain the canonical primary key, unique execution and fingerprint keys:
`uq_import_source_payload_receipt_execution`,
`uq_import_source_payload_receipt_fingerprint`;
composite index `ix_import_source_payload_receipt_execution_fk`;
FK `fk_import_source_payload_receipt_execution` with RESTRICT actions to
P3's `uq_import_source_execution_receipt_scope(id, source_execution_fingerprint)`;
checks `chk_import_source_payload_receipt_version`,
`chk_import_source_payload_receipt_bytes`,
`chk_import_source_payload_receipt_fingerprints`;
and `trg_import_source_payload_receipt_no_update` /
`trg_import_source_payload_receipt_no_delete`.

P4 is the existing NORMAL_PREFIX: 294 objects, comprising 7 tables,
151 columns, 60 indexes, 21 FKs, 46 checks and 9 triggers. Its existing
`phase-iii-p0-prefix-v1` hash is
`1fc28641da815cd4737b50b5b8ed065918b3f416f970ba2cccef82e5342e8d57`.
P3 remains 275 objects and
`88e8c410ea052d66c6d8a920143f11fdf3ecca47f4c6c570afcecb16fce1cf2b`.
These are existing expected values, not new or observed-schema expectations.
The 19-object difference is P0-04 only; no P0-05 through P0-09 DDL.

## Canonical receipt identity and transaction proposal

The logical field inventory, in canonical contract order, is:

```text
schema
supplier_import_source_execution_id
source_execution_fingerprint
accepted_payload_bytes
accepted_payload_sha256
```

The schema/domain is `supplier_import_source_payload_receipt_v1`.
Fingerprint bytes use that ASCII domain, exactly one NUL, and the existing
`CanonicalOnboardingData` JSON encoding. Its associative-key normalization
sorts keys; logical field inventory order must not be mistaken for a license to
replace the encoder with insertion-order JSON. Independently reproduce literal
JSON, domain bytes, byte length and SHA-256 vectors before implementation
acceptance. Input-order variants must agree; changed execution/size/digest,
wrong domain/NUL, stringified integer, unknown/missing key, wrong version,
noncanonical encoding and non-lowercase hash must reject or differ as specified.
Do not accept a supplied fingerprint without recomputation. No path, credentials,
payload content, file identity, created_at or other timestamp enters identity.

`accepted_payload_sha256` describes the exact accepted decoded parser-input
bytes: no newline/character conversion, XML/CSV projection or reserialization.
A positive size and well-formed digest are not proof that these bytes were
downloaded or read. Synthetic receipt metadata tests prove persistence only.
The future real acceptance boundary remains bounded download finalization;
there is no new permission to accept an arbitrary caller's claimed real digest.

Proposed repository flow, confined to a caller-owned transaction on one explicit
MySQL connection:

1. Validate the immutable value before insertion. Require an active transaction;
   never silently fall back to another connection or start a second transaction.
2. Lock the persisted execution by ID first and verify its exact fingerprint;
   reject absence or mismatch. Read the original persisted authority, not live
   SupplierFeed/XmlMappingTemplate selectors; do not invoke profile resolve/create.
3. Read/lock any receipt for that execution and any fingerprint conflict in a
   stable order. Parent execution then receipt is the proposed receipt-only
   lock order. It never acquires earlier job/feed/template locks after execution;
   it does not alter Slice 2's lock order.
4. If a receipt exists, reconstruct/recompute its canonical bytes and compare
   every canonical value and binding, not just digest text. Return its unchanged
   ID and original audit timestamp only for an exact match. A caller's new
   timestamp cannot replace the committed audit instant. Size/digest conflict,
   foreign execution, forged fingerprint or corrupted canonical bytes fail.
5. If absent, insert all fields atomically. Unique and composite FK constraints
   remain final protection. Classify eligible duplicate-key races narrowly;
   reread/compare on the same connection, never mutate the winner. An unrelated
   duplicate, deadlock or connection uncertainty rolls back and returns a
   sanitized failure, without blind in-transaction replay or diagnostic leakage.
6. A retry after a known rollback uses the same immutable execution/value. After
   an uncertain commit it first reconciles by the same identity; exact evidence
   is reused, conflict rejected, and unreadable state remains blocked. No new
   selectors, execution, digest, profile or replacement receipt is manufactured.

Future integration must review this proposed lock order with its complete call
graph before adding a live caller. Model guards and SQL triggers reject update,
touch/touchQuietly, delete, replace and clear, including mass-assignment bypasses.
No partial size/digest evidence is externally visible on failure.

## Foundation acceptance matrix

All rows are FUTURE requirements, not tests already executed for P0-04.

| ID | Future Slice 3 foundation evidence | Required negative/atomicity result |
| --- | --- | --- |
| F1 | Real MySQL 8.4 exact P3 -> P4 plus independent closed-world P4 inspection | Missing/extra/malformed object, wrong session/collation or non-P3 prerequisite rejects; no oracle refresh |
| F2 | Exact FK scope and all version/size/digest CHECKs | Cross-execution ID/fingerprint; zero/negative/null/overflow size; malformed/uppercase digest; invalid version fail; parent evidence unchanged |
| F3 | SQL and model append-only matrix | Raw UPDATE/DELETE/REPLACE and model mutation/force/unguarded/touch paths fail; no replaced row |
| F4 | Literal independently computed byte/hash vectors and round trip | Exact field grammar; canonical JSON/domain/NUL; audit/path excluded; unknown or coerced values fail; existing vectors unchanged |
| F5 | Absent insert and exact duplicate reuse | Exactly one row, exact bytes and binding, unchanged ID/created_at; changed payload or fingerprint conflict writes zero rows |
| F6 | Two independent MySQL processes, same payload | Both resolve the same receipt; exactly one receipt and unchanged execution/profile counts; synchronization is real, not sequential mocks |
| F7 | Two independent MySQL processes, conflicting payload | One winner, deterministic conflict for loser; never last-writer-wins; no partial receipt or execution change |
| F8 | Failure after insertion before commit; retry and uncertain-outcome reconciliation | Full caller transaction rollback, no partial evidence; persisted authority ignores selector changes; no new execution/profile on retry |
| F9 | Pristine terminal P4 -> P3 through the existing coordinator | Malformed/unknown/nonterminal/nonempty states and repeated invocation reject before destructive DDL; exact P3 retained/verified |
| F10 | P0-01/P0-02/P0-03, frozen Phase I/II/III and Catalog Sync regressions; static caller audit | Zero fetch/import/sync/queue/scheduler/capture and zero products/supplier_products mutation; no production caller |

Independent review must inspect literal vectors and actual concurrent process
results. Windows pcntl skips or SQLite trigger approximations are not MySQL
evidence. Future implementation gates include focused tests, full backend CI
on the actual head, PHP syntax, Pint and clean diff. They do not authorize
running migrations during this documentation task.

## Deferred integration matrix

| Deferred surface | Existing canonical requirement preserved | Separate future evidence/gate |
| --- | --- | --- |
| BoundedImmutableSourcePayload owner and storage adapter | Nonserializable owner of the same verified private regular-file object; no pathname reopen; secure lifecycle and cleanup | Dedicated design/implementation review with handle identity, permission, replacement and cleanup tests |
| Protected downloader / redirect handling | Exact accepted decoded byte count and SHA-256; bounded finalization; canonical no-unsafe-redirect and credential isolation | Approved numeric bounds and protected transport review; synthetic metadata is insufficient |
| Parser adapter / EOF | Same verified handle, recomputed count/digest, full EOF verification before parser success | XML/CSV tamper/TOCTOU/truncation/EOF regressions through real adapters |
| Receipt use by live imports / snapshot capture | Persisted execution/context/receipt authority; no mutable-selector reinterpretation | Explicit later runtime-integration and activation gates; P0-05 through P0-09 dependencies remain unsliced/unimplemented/unauthorized |
| Policy, jobs, scheduling, monitoring, recovery/backfill | All existing authority and evidence requirements remain closed | Separate owner authorization; no numeric or operational approval from schema presence |

The canonical readiness map remains solely in the referenced architecture.
In particular its PH3-RDY-003 blocker is not closed by this proposal; all ten
operational bounds remain NOT SPECIFIED and runtime activation remains
UNAUTHORIZED. This is a reference to those consequences, not a replacement
CURRENT status table.

## Local/testing downgrade and operational recovery

Future P0-04 support extends the existing invocation-scoped coordinator, not a
parallel down path. The frozen `P0-04-DOWN-01` operation has P4 precondition
and P3 result. Only an exact pristine terminal P4 may down to P3: receipt count
zero, P5-P9 absent, all schema/evidence/session/guard checks complete before
the first destructive DDL. P3 execution rows may be retained; pristine means
the terminal receipt table is empty, not deletion of parent evidence.

Consume fresh authorization before gates, schema inspection or DDL; remove
process, $_ENV and $_SERVER visibility as Slice 2 already does. Successful,
malformed, non-pristine and exception paths consume it. A second same-process
invocation without fresh authorization rejects before DDL; a later genuinely
fresh grant is independently checked. Do not cache a success in a coordinator,
closure or environment helper. Preserve existing connection-uncertainty and
safe evidence reporting, including failure to verify the resulting state.

Operational rollback remains forward-only and evidence-preserving. No deployed
receipt/execution is removed or cleared. No VPS down/rollback command or
operational receipt deletion is supplied here.

## Safety and non-effects

Repository Catalog Sync policy remains CREATE=true (manual/controlled),
UPDATE=false, SYNC_ALL=false, AUTO=false. Supplier import remains staging-only.
No supplier input overwrites Product name/slug/SEO/descriptions/localizations,
images, categories or attributes. No supplier image import, content overwrite,
Product/staging mutation, sync/import execution or source payload acceptance.

All seven `supplier_snapshot_capture` gates remain disabled by existing
fail-closed configuration reads: capture_enabled,
protected_generation_admission_enabled, recovery_issuance_enabled,
recovery_execution_enabled, monitor_schedule_enabled, observer_schedule_enabled,
alert_delivery_enabled. No config file is introduced and no runtime environment
is inspected or changed. Existing Super Admin access and sessions are untouched.

## One-time owner-approved revision: 2026-09-06

The repository owner approved only Appendix A of the 2026-09-06 instruction:
the protected prose patch with LF UTF-8 SHA-256
`cd4d9b2b0cd08540db9cf8e95a72dd4be6c42cacf1001cdaeed2d5f88adbb666`.
Authority baselines and semantic review were fixed before changing tests.
The owner supplied independently fixed OLD/NEW expectations after Python and
Node.js review-only implementations agreed on all six domains.

This one-time procedure permits only the affected documentation inventories
and lifecycle assertions for this exact patch. It is not general permission
to bless/refreeze future candidates and grants no application, migration,
runtime or release authorization. The no-bless/no-regeneration acceptance
rule remains enforced: no candidate-derived expectations, fixture updater,
environment bypass, fallback canonical-file reread, ignored structural bytes,
weakened semantic registry or reduced oracle is permitted.

The execution procedure is:

1. Verify the branch and fixed HEAD `21b201df9b159d7289c7538f56877890c764302a`,
   clean index, seven starting normalized file fingerprints and all OLD
   inventories. Preserve the intentionally dirty candidate in an external
   task TEMP snapshot before editing.
2. Extract only Appendix A with LF and its final LF, UTF-8 without BOM.
   Verify its exact SHA-256 and run `git apply --check --verbose`.
   Apply only that reviewed delta, never the superseded earlier proposal patch.
3. Compare all resulting NEW fields to the fixed external expectations through
   the existing PHP structural oracle; independently calculate byte/domain
   hashes locally. A mismatch stops revision, not a reason to invent a new hash.
4. Replace only affected fixed inventory fields and lifecycle assertions.
   Preserve pre/post regions, parser/acceptance rules, domains, IDs, ordering,
   all 13 design registries and 3 runtime-plan registries, and adversarial cases.
5. Verify focused old-status/header rejection, documentation and pure frozen
   suites, PHP syntax, Pint, links, scope and diff. Review all nine files
   unstaged; package those exact files outside Git for fresh independent review.

### Applied protected review record

This record replaces the earlier embedded unapplied patch; there is no second
current patch. The immutable patch identifier above is the exact applied
review artifact. Appendix A's literal added/deleted lines are:

| Applied file | Additions | Deletions |
| --- | ---: | ---: |
| `docs/IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md` | 35 | 22 |
| `docs/PHASE_9C6_5C3D1_RUNTIME_IMPLEMENTATION_PLAN.md` | 35 | 18 |

Git's regenerated diff reports design +34/-21 and runtime +35/-18: its diff
alignment retains the identical blank line that Appendix A explicitly replaces
in the slice-sequence hunk. This is not a content discrepancy; all fixed NEW
byte and ordered-unit hashes match. No byte was changed to force a diff count.

S3-REV-001's opening now identifies the fixed implementation baseline recorded
for the Slice 2 completion review at `21b201df9b159d7289c7538f56877890c764302a`,
includes P0-03, and links dated owner-provided deployment evidence. It does not
call this the moving current origin/main baseline. PR #211 planning history
and the dated Slice 1/`30b05f4aaacad38f3c6f4b782a5d90004c8740ff` record are
untouched. S3-AUTH-001's approval/procedure gap is resolved by the dated owner
decision and its bounded execution, not by an inferred general permission.

### Fixed OLD/NEW inventory audit

Only `expectedPhaseThreeArchitectureDocumentInventory()`,
`expectedPhaseThreeCurrentArchitectureInventory()` and
`expectedPhaseThreeRuntimePlanInventory()` have affected fixed fields.
Categories H/L/M/P/T mean heading/literal/marker/paragraph/table with the existing
CANONICAL_*_EXACT names. Line counts include terminal split elements. The
version strings, hash domains, unit prefixes/widths and region ordering stay
unchanged. Only the specified paragraph counts change.

| Domain | State | Bytes | Lines | Units | H/L/M/P/T | Byte SHA-256 | Ordered-unit SHA-256 |
| --- | --- | ---: | ---: | ---: | --- | --- | --- |
| Design whole | OLD | 1889044 | 8124 | 1136 | 86/72/41/866/71 | `fe41962aabc6b0d88714e89bc3728ba523a501469bcf5e9e2950f2fdb650dba4` | `5f5eea2e745409145356f66aa48586f0e8f8da10ddfc2302702841d3ce8a6ae9` |
| Design whole | NEW | 1890050 | 8137 | 1137 | 86/72/41/867/71 | `590490cdcf3f8c8946fce98455658e6797b4421b83df6a7f436f02757fe45108` | `7f9f1a79522e77262613298d6d9337f9d3fda3ee511097322aa144d87d2a321b` |
| Pre-current region | OLD | 244093 | 3751 | 565 | 33/37/8/462/25 | `44ac26363d14a70678a56fea0ace1ca88960317404b115f82c566093cd6bfecc` | `9493b421b6bcb38ef1f8d58638b9d5526294873097c7acded67bf946de84aa64` |
| Pre-current region | NEW | 244093 | 3751 | 565 | 33/37/8/462/25 | `44ac26363d14a70678a56fea0ace1ca88960317404b115f82c566093cd6bfecc` | `9493b421b6bcb38ef1f8d58638b9d5526294873097c7acded67bf946de84aa64` |
| Current region | OLD | 1481235 | 2628 | 292 | 16/18/31/201/26 | `cf619f1bde3a774166f0def50e80ae3b7e69550b966f6037b05dee553c9dd503` | `44075b42faeae7cb1280f9ccb3124acd8562cc3ba2d5f7d02cb21d47e4b16737` |
| Current region | NEW | 1482241 | 2641 | 293 | 16/18/31/202/26 | `2524fbffba5ba25a856b207f379fdf77416a68ca2565a85080effa337b4cd993` | `e5ef270f20957730244777e78712373e43784481a4cdcdacb2cdb4cc95895607` |
| Post-current region | OLD | 163716 | 1747 | 279 | 37/17/2/203/20 | `cb9c1413424bdf9c2eaca71ebd569a971537cb5ee4ead36ce89168f97ade6a17` | `aa9e71c9be4f9154cc2b23dc45e1edc1ea6b339a4fde98dc9b7b86121989ae17` |
| Post-current region | NEW | 163716 | 1747 | 279 | 37/17/2/203/20 | `cb9c1413424bdf9c2eaca71ebd569a971537cb5ee4ead36ce89168f97ade6a17` | `aa9e71c9be4f9154cc2b23dc45e1edc1ea6b339a4fde98dc9b7b86121989ae17` |
| Standalone CURRENT | OLD | 1481235 | 2628 | 292 | 16/18/31/201/26 | `e03495b716dc53a75a6adf2b9bd6123ba791c9e9711f688cdeecbbf6fcf8c316` | `e0a5d44941e18e40d4ff00db5886532a30b665f80b4e6e2c6521f4bbb146a80c` |
| Standalone CURRENT | NEW | 1482241 | 2641 | 293 | 16/18/31/202/26 | `ca4349cfef35729ea87b09a7344f16667207b781daa749e44bd75b327b52118c` | `6526abc414d5d51f7e5186309d81bc2a4a85807186a95267cd2a646e9d6ee21d` |
| Runtime plan whole | OLD | 115648 | 1699 | 497 | 48/2/7/424/16 | `1483056eecac79e54325589b308fc1470f76e8c68fc6e7639556a97026e62896` | `b55a9f0f7ca6303ca5ac08b31e48483cbbbf5499a2db299cf4e9d50a69e5fff4` |
| Runtime plan whole | NEW | 116783 | 1716 | 498 | 48/2/7/425/16 | `665a062570a02bd0d2d8fbf6b1faf72e3a4d7683c77eb384e0d2350594e89a4b` | `57930a2508871ae81eb61ae7847ff2d514d5b7c46a67252781901611000b9d44` |

The OLD values were verified before editing and every resulting NEW value
matched the owner's fixed review expectations using the unchanged PHP
structural parser before expected constants were changed. Pre-current and
post-current bytes and expectations are identical. The standalone CURRENT and
current-region hashes intentionally use different domains.
Independent local byte/domain and registry-preservation checks are recorded
with final validation below; Python/Node agreement is owner-supplied review
evidence, not a claim that this author performed a fresh independent review.

All P0 migration/artifact/prefix/SQL/object/state/downgrade-operation and semantic
registries remain outside the revision. Phase I/II vectors and schema/coordinator
code are unchanged. Existing oracle metadata remains 168474 bytes, 375 objects,
17 states, 15 downgrade operations and domain-bound SHA-256
`0ca5b057d4733cb791d791bbf6113e8e7f3a678ffdd61a7c11ca36306023def6`.
Documentation acceptance fingerprints are not P3/P4 schema state hashes.

### Historical review: 2026-09-05

The earlier candidate left S3-AUTH-001 BLOCKED because no permitted revision
procedure had been supplied; the protected documents and OLD expectations
were unchanged and its embedded prose diff was NOT APPLIED. Its validation
was 54 documentation tests / 4,878 assertions and 35 pure tests / 392 assertions,
zero skips, on Windows/PHP 8.4.22. That pass preserved old frozen bytes; it did
not reconcile stale current status. That patch and blocker-only outcome are
superseded by the exact owner-approved 2026-09-06 revision above.
No new staging observation or CI run is implied. CI #487 validates the earlier
merged baseline, not this revised local documentation candidate.

### Local validation: 2026-09-06

Environment: Windows, bundled PHP 8.4.22 NTS x64. All commands below completed
locally with exit code 0, with no database connection or migration execution.
Each focused filter ran separately (one test each, zero skips):

| Exact command from repository root | Assertions | Result |
| --- | ---: | --- |
| `.\.tools\php\php.exe artisan test --filter=test_phase_three_p0_slice_two_authority_is_exact_and_nonactivating` | 129 | PASS |
| `.\.tools\php\php.exe artisan test --filter=test_completed_slice_status_rejects_pre_completion_authority_without_hashes` | 60 | PASS |
| `.\.tools\php\php.exe artisan test --filter=test_runtime_plan_opening_uses_fixed_slice_two_review_baseline_not_moving_main` | 28 | PASS |
| `.\.tools\php\php.exe artisan test --filter=test_slice_two_dated_completion_and_slice_three_proposal_do_not_rewrite_history` | 212 | PASS |
| `.\.tools\php\php.exe artisan test --filter=test_slice_three_proposal_keeps_evidence_provenance_and_one_time_revision_explicit` | 72 | PASS |
| `.\.tools\php\php.exe artisan test --filter=test_phase_three_current_architecture_authority_is_fully_closed_world` | 156 | PASS |
| `.\.tools\php\php.exe artisan test --filter=test_phase_three_architecture_document_outer_regions_are_fully_closed_world` | 224 | PASS |

Complete documentation command:
`.\.tools\php\php.exe artisan test --filter=SupplierOfferLifecycleDocumentationContractTest`
passed 56 tests / 5,057 assertions / zero skips. This includes all existing
closed-world, adversarial, status-discovery, schema/semantic and frozen checks.
The duplicated unit-count assertions in the two structural tests changed only
from 292 to 293 and 1136 to 1137, the same fixed Appendix C values; their
adversarial bodies and acceptance parsers were not changed.

Pure suites command:

```powershell
.\.tools\php\php.exe artisan test tests/Unit/Suppliers/SourceProfiles/CanonicalSupplierPhaseThreeP0OracleTest.php tests/Unit/Suppliers/SourceProfiles/CanonicalSupplierSourceProfileContractTest.php tests/Unit/Suppliers/Imports/SupplierImportSourceExecutionContractTest.php tests/Unit/Suppliers/Snapshots/CanonicalSupplierSnapshotByteContractTest.php
```

Result: 35 tests / 392 assertions / zero skips. Exact schema-oracle metadata,
Phase I/II byte vectors, source-profile and execution contracts remain frozen.

Additional commands/checks:

- `.\.tools\php\php.exe -l tests/Feature/SupplierOfferLifecycleDocumentationContractTest.php`: PASS.
- `.\.tools\php\php.exe vendor\bin\pint --test`: full repository PASS.
- `git apply --check --verbose <task-TEMP>/approved-protected.patch`: PASS before apply;
  `git apply --reverse --check --verbose <task-TEMP>/approved-protected.patch`: PASS
  after apply, check only (no reversal).
- Independent PowerShell byte/domain audit: six NEW hashes/counts match;
  13 design and 3 runtime registries plus pre/post regions are byte-identical.
- The focused pure PHP status helpers evaluated the complete pre-edit protected
  documents, identical to the fixed HEAD: OLD status 21 violations, OLD opening
  8 violations; NEW status/opening both zero. These checks do not call the
  whole-document hash oracle. The in-memory restored old header also rejects;
  PR #211 history, dated Slice 1 SHA and historical diff text remain valid.
- Relative file-link audit: 72 checked, zero missing. Scope and high-confidence
  credential-pattern scans: PASS. No credential value was printed.
- `git diff --check`, `git diff --stat 21b201df9b159d7289c7538f56877890c764302a`,
  `git diff --name-status 21b201df9b159d7289c7538f56877890c764302a`,
  `git diff --cached --name-status` and `git status --short`: expected
  nine-file candidate only, empty index and unchanged HEAD. Untracked documents
  are counted separately, not omitted from the final review ZIP or scope audit.

Intermediate validation rejected duplicated readiness identifiers in the four
non-owner status documents; those references were corrected without changing
the authority parser. It also exposed the two stale duplicated unit-count
assertions above. Both were reconciled to the fixed external values before the
successful complete run. No failing result is represented as a candidate pass.

Local self-review found no remaining in-scope BLOCKER, MAJOR or MINOR.
S3-AUTH-001 and S3-REV-001 are locally remediated and validated; this is not
final independent acceptance. The diff-alignment count distinction above is a
NOTE, not a protected-byte change. All changes remain unstaged for review.

Full application/MySQL suites, fresh CI, VPS verification and production
evidence collection: NOT RUN for this documentation-only revision. Baseline
CI #487 is not candidate validation. P0-04 implementation remains NOT AUTHORIZED.
The next gate is fresh independent review, not implementation or release.

# P0-07 R2 Synthetic MySQL Design Evidence - 2026-10-23

Status: DOCUMENTATION CANDIDATE FOR INDEPENDENT REVIEW.
Verdict: VERIFIED PASS for the recorded synthetic reduced-graph matrix only.
No canonical acceptance, adoption, foundation implementation or runtime
activation is performed or authorized by this document.

Read with [AGENTS](../AGENTS.md), [Testing](TESTING.md),
[R2 proposal](PHASE_III_P0_STAGING_SOURCE_REVISION_POINTERS_D1_D3_RESOLUTION_PROPOSAL.md),
[foundation findings](PHASE_III_P0_STAGING_SOURCE_REVISION_POINTERS_FOUNDATION_PROPOSAL.md)
and the separate [canonical-adoption candidate](PHASE_III_P0_STAGING_SOURCE_REVISION_POINTERS_CANONICAL_ADOPTION_CANDIDATE.md).
Historical NOT RUN/FAIL/STOP records are preserved, not relabeled.

## Provenance and Verification Boundary

The runtime execution was owner-executed, not agent-executed. Run root:
`/home/mycomputer/p007-r2-lock-observer-run-20261023T112238Z`.
This documentation task reads the exported bytes offline. It performs no
Docker/Engine/MySQL command, replay, cleanup, migration, application test or VPS
operation. The earlier independent review reported 57 matching stdin records,
44 design cases and 60 assertions. Those counts and their raw results were also
checked in this task; they are not accepted merely from that review's summary.
This task does not constitute a new independent acceptance approval.

All archive-relative references below are under
`p007-r2-lock-observer-run-20261023T112238Z/`.
`L` denotes one-based JSONL line numbers, not reconstructed console lines.
`commands.jsonl` means `evidence/design/commands.jsonl` unless qualified.
The SQL source references are relative to the separately verified observer
package, not additional files inside the run archive.

| Artifact | Verified identity |
| --- | --- |
| `P0_07_LOCK_OBSERVER_EVIDENCE_20261023T112238Z.zip` | 55013 bytes; 68 files; SHA-256 `71efd25c4ebefb2db1db75838c618364bfc48e274b46d27094b9ac563f189177` |
| `EVIDENCE-MANIFEST.json` | SHA-256 `c7ae098b8c810bc1ca27e07917828f1cfccfee0e74ceff27bf564a6cce499006`; exact set of 67 other files |
| Executed `PACKAGE-MANIFEST.json` | SHA-256 `4b008bf5d0fb3e9ef9aec7a0aa03922c39dce28e2e5e81a21a062b05eb9b8d30` |
| Package `proposal-R2.md` | 33000 bytes; SHA-256 `f55ad48293f6ccb15ec8bf8116cdbce0f05ab4140cabe4823e93a3f2f6a39af0`; matches the existing repository R2 document |
| `evidence/design/commands.jsonl` | 88216 bytes; SHA-256 `93dcec2652348b35e5edae98fa0e99846e817f17413e5a71f9280ad0a9478f8f` |
| `evidence/design/lock-wait-result.json` | SHA-256 `3bd756e580c824f4e7aefa56b877a2dcc193a00acc86143df7810e0c0e3f9f52` |

The archive was checked for safe unique relative paths, exact entry set,
CRC32, uncompressed bytes and every manifest SHA-256. All 38 source-file
identities in `evidence/RUN-INTENT.json` match the local reviewed package.
The executed inputs were independently reconstructed as bytes from those
verified SQL sources, recorded token and observed connection IDs. All 57 stdin
sizes/hashes match: 55 SQL batches and two empty inspect inputs. Reconstruction
is comparison only; no reconstructed SQL or owner script was executed.

The 44 `design_probe` calls were compared with actual errno, SQLSTATE and
affected-row outputs, including expected failures. The 60 `design_assert`
calls have their expected true outputs. These include repeated inherited-FK
checks in separate fixtures; they are not 60 unique behaviors. Summary table
reprints of `design_results` were not counted as new calls. There are 52
design-runner child spawn requests, each with recorded exit and close 0,
no signal, and ordered monotonic events. This does not turn every child into
a distinct SQL experiment or a separately observed container termination.

## Recorded Runtime and Isolation

Sources: `evidence/RUN-INTENT.json`, `evidence/LIVE-RESOURCE.json`,
`evidence/LIVE-IDENTITY.json`, `evidence/FINAL-IDENTITY.json`,
`evidence/design/binding.json`, `commands.jsonl` L4/L10/L16 and final UUID output.

| Field | Recorded value |
| --- | --- |
| Container | `37aec2f927c8557bc0c9ea8796f5541b176ef47b5d5150d57e66438e9cb4942f` |
| Image | `sha256:b3b90af2a6552ae30c266fdb7d5dd55f3afb72404bb78d37fe8a23eb857fd3fb` |
| MySQL / server UUID | `8.4.11` / `0e634df0-ced4-11f1-ae2e-3ac21d4c26d9` |
| Owner Node | `/usr/bin/node`, `v22.22.1` |
| Endpoint | `unix:///var/run/docker.sock` |
| Container created / started | `2026-10-23T11:22:38.600702915Z` / `2026-10-23T11:22:38.804864454Z` |
| Recorded PID / restart count | 3771 / 0 |
| Network / IPC / PID mode | `none` / `private` / empty (not host) |
| Restart policy / privileged | `no` / false |
| Limits | 2147483648 bytes memory; 2000000000 NanoCpus |
| Persistent mounts / binds / published ports | none / null / empty |
| MySQL data / socket storage | tmpfs `/var/lib/mysql`, 1 GiB; `/var/run/mysqld`, 64 MiB |
| FK checks / event scheduler / isolation | 1 / OFF / REPEATABLE-READ |
| Initial databases | information_schema, mysql, performance_schema, sys |

The synthetic schemas use `utf8mb4_unicode_ci` in `sql/00-common.sql`.
This is not a two-database-default test and does not certify the full canonical
trigger/session metadata. Both required database defaults remain a later
foundation acceptance gate. The before/after design identities are identical
and the final server UUID matches. The final recorded container is still
running, not restarting/OOM-killed. Its recorded `.State.ExitCode=0` is NOT
a terminal container exit. This archive does not attest its current state.

## Terminal Records and Seal

| Layer | Recorded observation | Evidence |
| --- | --- | --- |
| Outer worker shell | start `11:22:38.214Z`; exit/close 0 at `11:22:52.336Z`; 14123.038168 ms total; no timeout/interruption/overflow/error | `OWNER-SHELL-EXIT.json`, `OWNER-SHELL.events.jsonl` |
| Owner orchestration entry | shell observed entry exit 0; second-resolution interval `11:22:38Z` to `11:22:52Z` | `OWNER-ENTRY-EXIT.json` |
| Design-runner Node child | start `11:22:47.146Z`; exit/close 0 at `11:22:52.290Z`; 5143.920262 ms | `evidence/009-design-runner.record.json`, corresponding events |
| A and B Docker exec clients | each recorded exit/close 0, no signal | `commands.jsonl` L220/L221 and L225/L226 |
| MySQL container | last recorded running; no terminal exit attested | `evidence/FINAL-IDENTITY.json` |

All UTC times in this table are on 2026-10-23. The shell and orchestration
tokens match their intent/entry records. Outer stdout/stderr and each numbered
command's stdout/stderr match its process record. The orchestration entry has
an observed wait exit, but no separately recorded Node close; do not invent it
from the shell close. `evidence/OWNER-RESULT.json` and
`evidence/design/result.json` report `DESIGN_EXPERIMENTS_COMPLETED` with
`canonical_acceptance=false`.

Seal time is `2026-10-23T11:27:19.323Z`, status
`COMPLETE_SUCCESS_EVIDENCE`. Its `sql_pass_inferred=false` is not SQL failure:
the seal validates evidence integrity and shell completion, not SQL semantics.
The SQL review above is a separate step. The manifest excludes subsequent
`cleanup/`; this archive supplies no cleanup execution evidence. Cleanup was
not performed in this review.

## Expected and Observed Matrix

| Scenario / expected | Observed raw result | Evidence | Limit |
| --- | --- | --- | --- |
| Frozen D1 rejects an ordinary legacy price update | `F-D1-legacy-update 1644 45000 -1`; row remains `1 NULL NULL 125.00` | `commands.jsonl` L50/L55; `sql/30-frozen-cases.sql` | Reproduces frozen reduced-graph behavior, not a staging test |
| Frozen D2 first binding cycle | head-first `1644/45000`; revision-first `1452/23000`; pair-first `1644/45000` | L52-L54; `sql/10-frozen.sql` and `30-frozen-cases.sql` | Statement-time constraints in this fixture |
| Frozen D3 collation gap | case-only legacy update allowed, `0/00000`, one row | L51 | Does not prove all collation inputs |
| R1 nullable-scope CHECK conflicts with inherited SET NULL | `3823/HY000`, affected rows -1; `failed-DDL-left-no-check=1` | L85-L86; `sql/02-r1-check-rejection.sql` | Expected refusal is a passed negative case |
| R2 legacy writes remain allowed | legacy insert/update/case/null-feed each `0/00000/1` | label `proposed-cases`; `sql/40-proposed-cases.sql` | Not an execution of unchanged application importers |
| Pair-only CHECK and separate scope guards | INSERT half-pairs `3819/HY000`; UPDATE half-pairs and null-feed pair guards `1644/45000` | same label; CHECK `YES` at L167 | No disabled FK/CHECK workaround |
| Scope/immutability | cross-scope/orphan `1452/23000`; null revision feed `1048/23000`; pointer/head clearing and revision update/delete `1644/45000` | same label, raw case rows | Reduced receipt/execution graph |
| Byte-sensitive bound fields | price, case-only and trailing-space updates all `1644/45000`; no-op affects 0; workflow affects 1 | same label | Stored JSON/decimal semantics differ from raw lexical serialization |
| Initial and subsequent publication | `initial-CAS=1`, `revision-CAS=1`; stale CAS `0/00000/0` | L137/L153/L154; `sql/40-proposed-cases.sql` | Caller expected-old discipline is not imposed by SQL alone |
| Whole-unit rollback | `rollback-head=1`, `rollback-revision=1`, `rollback-parent=1`; product 9 pair `NULL NULL` | L162-L165 | Deliberate known rollback, not uncertain-commit recovery |
| Inherited FK actions retained | four exact actions, columns and FK checks assert true before/after DDL in each fixture | labels `*-FK-before` / `*-FK-after`; `sql/01-verify-inherited.sql` | Not complete canonical inventory comparison |
| Feed/supplier/catalog actions | legacy feed SET NULL true; supplier CASCADE true; bound feed delete `1451/23000`; workflow product SET NULL true | L403-L406; `sql/66-legacy-referential-actions.sql` | Same reduced graph only |

The initial-CAS row is identified by its literal label; line references are
checked against the archived JSONL, not against stdout progress counters.

## Concurrency Proof Before Publication

All events below come from `evidence/design/commands.jsonl` and share that
process's monotonic clock. Elapsed time alone is not a lock-wait proof.

| Event | UTC | Monotonic ms | Line |
| --- | --- | ---: | ---: |
| `A_LOCKED:28` | 11:22:49.488Z | 2305.311258 | 202 |
| `B_READY:29` | 11:22:49.591Z | 2408.585609 | 206 |
| Verified B-to-A transaction-owner row | 11:22:49.686Z | 2503.516891 | 213 |
| A publication stdin, 384 bytes | 11:22:49.686Z | 2503.785590 | 214 |
| `A-CAS-one=1` | 11:22:49.689Z | 2506.487254 | 215 |
| B exact duplicate `1062/23000` | 11:22:49.690Z | 2508.062757 | 216 |
| B locked winner, then stale-zero assertion true | 11:22:49.692Z / 11:22:49.706Z | 2509.877957 / 2523.895040 | 219 / 224 |
| Final persisted tuple | 11:22:49.794Z | 2611.571935 | 230 |

The raw lock row at L210 and `evidence/design/lock-wait-result.json` is:

```text
P007_WAIT_V1 INNODB 29 28 2592 2590 2592 2590
```

Spaces above display actual tab-separated fields: marker, engine, requesting
connection B, blocking connection A, requesting/blocking engine transaction IDs,
then their matched `INNODB_TRX.TRX_ID` values. `sql/53-concurrency-waits.sql`
joins the engine transaction IDs to `INNODB_TRX`, uses `TRX_MYSQL_THREAD_ID` and
limits the engine to InnoDB. It does not infer ownership from
`BLOCKING_THREAD_ID`. IDs remain decimal strings in JavaScript, with exact SQL
decimal comparisons. One observation attempt established B=29 -> A=28 and
2592 -> 2590 before publication. `B_READY` alone would not establish waiting.

Final row, checked both in raw L230 and `evidence/design/concurrency-result.json`:

```text
5 55 500 150.00 5 55 1 10 434F4E43555252454E54 1 1 1
```

Ordered fields from `sql/55-concurrency-final.sql`: product ID, product head,
current revision, price, revision product, revision head, supplier, feed,
hex SKU bytes, revision-table count, products assigned to head 55, head-55 count.
Thus one revision, one assigned product and one logical head remain; the loser
does not overwrite the winner. `B-duplicate-is-logical-key=1` checks the exact
logical unique key, not any arbitrary duplicate. No automatic retry follows.

## Snapshot and Referential-Action Results

Tuple order: product ID, current revision, price, availability ID, attribute
raw value, toy digest-matches boolean. Sources: the three
`evidence/design/*-snapshot.json` files, raw labels `import-snapshot-A`,
`before-snapshot-A`, `after-snapshot-A` and their `*-current` commands.

| Separate fixture | T0 | T2 in same snapshot | Current committed view |
| --- | --- | --- | --- |
| Concurrent import | `18 400 125.00 1 A 1` | `18 400 125.00 1 A 1` | `18 401 126.00 1 B 1` |
| SET NULL before T0 | `18 400 125.00 NULL A 0` | `18 400 125.00 NULL A 0` | `18 400 125.00 NULL A 0` |
| SET NULL after T0 | `18 400 125.00 1 A 1` | `18 400 125.00 1 A 1` | `18 400 125.00 NULL A 0` |

`sql/61-snapshot-A-open.sql` establishes the RR snapshot before the concurrent
operation; `63-snapshot-A-after.sql` reads it again. `sql/65-referential-action.sql`
shows direct availability mutation rejected (`1644/45000`), while an inherited
FK SET NULL leaves the pointer unchanged and creates a projection mismatch in
the newer view. L326-L328 and L385-L387 record both placements around T0.
This supports the same-view admission requirement; no admission implementation
was exercised. `sql/60-snapshot-setup.sql` hashes only price/status/one attribute.
That toy projection is NOT the canonical versioned fingerprint recipe.

## Gaps Deliberately Demonstrated and Still Open

Raw L155-L160 records permitted independent attribute update/insert/delete,
an unselected revision insert, a wrong semantic digest transition and direct
republish of an old revision; each affects one row with `0/00000`.
Those are successful demonstrations of SQL's responsibility boundary, not
permission for a future writer/admission service to accept those states.
Structural validity neither proves publication history nor grants reuse.

The historical failed owner run at `20261023T081255Z` remains separate:
its owner-reported 1205/HY000, empty observation output and no publication are
not rewritten. Its original files are not in this verified archive. Successful
transaction-owner observation now does not establish implicit-lock conversion
or another mechanism as the historical cause.

This run closes the earlier *synthetic experiment* gaps for frozen D1/D2,
R1 incompatibility, R2 constructibility/guards, known rollback, real wait/CAS,
MVCC and the tested FK actions. It does not close foundation A1-A12, full P6/P7
schema/migration/down guards, both schema defaults, literal independent oracle,
application import regression, complete canonical provenance/fingerprints,
writer/admission implementation, uncertain-outcome handling or runtime approval.

## Documentation Task State

File-read verification found branch
`codex/p0-06-staging-p0-07-proposal-docs`, HEAD
`b2d7e0bb428745dfe9d0e39a6973fd34a5cf371c`, matching index/HEAD tree
`0bc87a434f09897a4ebfebeb39f8c0be4cf06b85`. Cached `origin/main` is
`0e2cf9bcd28d040df18696dd294f4554982e59e1`; current remote was not observed.
The three prior document hashes remain unchanged. No refs/index were updated.

The current sandbox does not authorize writes to this sibling worktree, and
`git -C ... status` returns Permission denied. Both new documents are therefore
prepared externally only, not installed as repository untracked files. The
external full diff combines the three verified existing additions and these
two proposed additions. Repository application is BLOCKED, not silently moved
to another branch. No permissions/escalation workaround was attempted.

Documentation contract: NOT RUN; this worktree lacks `vendor/autoload.php`
and `.tools/php/php.exe`. The repository-derived command would be
`.\.tools\php\php.exe artisan test tests/Feature/SupplierOfferLifecycleDocumentationContractTest.php`.
No borrowed dependencies, installation or test rerun was performed.
External UTF-8/link/whitespace/evidence/diff/readback checks are separately
recorded in the review package, not claimed as PHP contract execution.

Next: independent review of these documentation candidates and their gates.
Catalog Sync UPDATE/SYNC_ALL/AUTO remain disabled; no data, flags, capture,
auth/admin/session or runtime behavior is changed.

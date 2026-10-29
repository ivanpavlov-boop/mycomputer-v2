# Phase III-P0 Slice 5 Staging Evidence - 2026-10-20

Status: LOCAL DOCUMENTATION CANDIDATE FOR INDEPENDENT REVIEW.
Recorded on 2026-10-21. This is an attributed historical evidence record,
not canonical adoption, a new deployment, or runtime activation authority.

Related: [AGENTS](../AGENTS.md), [Deployment](DEPLOYMENT.md),
[Release Checklist](RELEASE_CHECKLIST.md), [Catalog Sync Safety](CATALOG_SYNC_SAFETY.md),
[Slice 5 definition](IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md#slice-5---immutable-supplier-product-source-revision-foundation),
[P0-06 foundation proposal](PHASE_III_P0_SOURCE_REVISION_FOUNDATION_PROPOSAL.md),
[P0-07 proposal](PHASE_III_P0_STAGING_SOURCE_REVISION_POINTERS_FOUNDATION_PROPOSAL.md).

## Attribution and Evidence Identity

| Source | What was checked locally | Limits |
| --- | --- | --- |
| GitHub REST responses on 2026-10-21 | PR, merge commit, main, workflow run and six jobs, read-only | Historical CI, not a new test or VPS observation |
| Owner-executed `SAFE_REPORT.json` | Downloaded bytes, SHA-256, JSON fields and internal consistency | Statements made by the deployment script; no independent replay of private raw VPS evidence |
| Owner-executed `WRAPPER-EXIT.txt` | Exact local contents `SCRIPT_EXIT=0` | This file does not contain a tee exit |
| Owner statement | `TEE_EXIT=0`; acceptance of the 19 October backup; preservation of other service identities | Attributed confirmation, not a locally recovered terminal stream |
| Owner browser confirmation on 2026-10-21 | Owner reports that the site, authenticated administration and Catalog Sync open | Not an automated browser test; no catalog action is inferred |

Locally read evidence: `Downloads/PR237_R4_DEPLOY_20261020_200105/`.
Original files were not edited or repackaged into the repository.

| Artifact | Bytes | SHA-256 |
| --- | ---: | --- |
| `SAFE_REPORT.json` | 19154 | `00be0c7e15ce7e3cc80af1dd2f634e2b2fa9b8002449e01743cc643e161c99c7` |
| `WRAPPER-EXIT.txt` | 14 | `2a36f7aa9b0668207b3b012c18b6c1ea1cc9295859a2d04febd51b9a5fcebeba` |

The locally retained `DEPLOY_PR237_STAGING_R4.sh` also matches reviewed SHA-256
`66aa0810cd1ca410cb1ec3f6037034134214d57bc04c65e75ebe3d37b1c66efc`.
This verifies artifact identity, not an independent execution trace proving that
every VPS byte was executed. The owner identifies that R4 as the executed script.

Owner-provided VPS evidence root:
`/root/mycomputer-v2-pr237-deploy-20261020T165300Z.nDV7PSOG`.
No private receipts, raw Docker environment, credentials, or backup payloads
are reproduced here. The safe report's aggregate assertions are not substituted
for missing raw measurements.

## Independently Checked GitHub Facts

[PR #237](https://github.com/ivanpavlov-boop/mycomputer-v2/pull/237) is merged,
with `merged_at=2026-10-19T08:43:28Z`.

| Identity | Value |
| --- | --- |
| Merge/deployed target | `b2d7e0bb428745dfe9d0e39a6973fd34a5cf371c` |
| Merge parents | `0e2cf9bcd28d040df18696dd294f4554982e59e1`, `d02d0570a5ef6e2a5408644a269c78b7a3456ec9` |
| Merge tree | `0bc87a434f09897a4ebfebeb39f8c0be4cf06b85` |
| Actual GitHub main, checked 2026-10-21 05:33:52 UTC | `b2d7e0bb428745dfe9d0e39a6973fd34a5cf371c` |
| Previously deployed baseline, from owner report | `be849c48279b65257fdcbcb217f4fa41a58a3104` |

At the read time main has not advanced beyond the target. The local cached
`origin/main` was `0e2cf9bcd28d040df18696dd294f4554982e59e1`, not the current
remote main. A fetch of the exact target object with `--no-tags
--no-write-fetch-head` supplied the new isolated worktree without updating that
cached ref or the other checkouts.

[Post-merge run 39224805532](https://github.com/ivanpavlov-boop/mycomputer-v2/actions/runs/39224805532)
is `push`, attempt 1, head equal to the merge target, completed `success`.
These are GitHub job conclusions, not newly executed tests or reconstructed
test totals:

| Job | Conclusion | Job ID |
| --- | --- | --- |
| backend | SUCCESS | 117968384298 |
| backend-image-permissions | SUCCESS | 117968384253 |
| frontend-validation | SUCCESS | 117968383946 |
| frontend-browser | SUCCESS | 117968384583 |
| frontend-card-cache | SUCCESS | 117968384347 |
| frontend-card-cache-alpine | SUCCESS | 117968384278 |

## Owner-Executed Deployment Record

Source: safe report `started_utc`, `finished_utc`, `duration_s`, `stages`,
`controller_exit`, `critical_command_results` and the wrapper file.
The interval is 2026-10-20 16:53:00.694477 through 16:55:15.702967 UTC,
135.009 seconds. Controller exit and locally recorded script exit are both 0.
Tee exit 0 is owner-provided only.

All ten stages have completion records, in this order:

| Stage | Completion UTC, 2026-10-20 |
| --- | --- |
| VERIFY_BACKUP | 16:53:04.849949 |
| PREFLIGHT | 16:53:11.454495 |
| FETCH_PINNED_TARGET | 16:53:12.880062 |
| CHECKOUT_BUILD_GATE | 16:53:52.008093 |
| DRAIN_OLD_WORKERS | 16:54:33.499821 |
| MIGRATE_ONCE | 16:54:38.062079 |
| ACTIVATE_APP | 16:54:49.874628 |
| ACTIVATE_WORKERS | 16:54:58.013482 |
| POSTCHECKS | 16:55:15.022358 |
| RETIRE_OWNED_HELPER | 16:55:15.694836 |

### Migration and Data

Safe report `migration`: exactly one pending migration was applied,
`2026_08_28_090005_create_supplier_product_source_revisions_table`;
pending afterward is `[]`. `schema_after` is `P6 / NORMAL_PREFIX`, 339 objects,
SHA-256 `ea265e4717c2f61abba6731f08be576ff6fcd01fe6b673d61d41011a888056aa`.
This matches the existing frozen P6 state, not a new oracle generated here.

`protected_products_supplier_products` reports matching ordered-row hashes.
The safe report does not include the individual before/after digests: this
record preserves the reported result, not a claim of independent DB equality.
`runtime.admins` retains active Super Admin ID 1. PHP is 8.4.26; the reported
MySQL runtime is 8.4.9 with database `mycomputer_v2`.

### Flags and Dormancy

Safe report `runtime.flags` records unchanged presence/type/value for 17 keys.
The ten present values are booleans:

| Key | Value |
| --- | --- |
| `catalog_sync.create_enabled` | true |
| `catalog_sync.update_enabled` | false |
| `catalog_sync.sync_all_enabled` | false |
| `catalog_sync.auto_enabled` | false |
| `commerce.public.enabled` | false |
| `commerce.public.confirmation_enabled` | true |
| `commerce.abandoned_cart_recovery.enabled` | false |
| `legal.approved` | true |
| `payments.methods.card.enabled` | false |
| `payments.methods.leasing.enabled` | false |

Seven `supplier_snapshot_capture.*` keys are absent (`type="null"`, `value=null`):
`capture_enabled`, `protected_generation_admission_enabled`,
`recovery_issuance_enabled`, `recovery_execution_enabled`,
`monitor_schedule_enabled`, `observer_schedule_enabled`, `alert_delivery_enabled`.
Absence is not rewritten as a stored boolean false.

The new app, queue and scheduler containers use the target images according
to `target_images` and `services`. Owner evidence reports the other service
identities preserved. Merely listing their final IDs does not independently
establish before/after equality. These app/worker activations are deployment
steps, not activation of dormant supplier provenance functionality.

### Drain Semantics

`old_worker_exits.queue=0`. The scheduler result is explicitly
`FORCED_EMPTY_CONTROLLER_TERMINATION`, container exit 137, not graceful exit.
`scheduler_drain` reports verified kernel live/zombie/reap accounting and
`QUIESCENT` closure with no live work before termination; observer and Docker
exec exits are separately 0. Restart policy was checked as `no`.
The exclusive window was owner-asserted, not technically enforced against all
external operators. This safe-report review does not independently rerun the
observer, inspect kernel accounting, or authorize reuse of the procedure.

### HTTP, Browser and Backup Limits

Safe report `http` has 20 expected responses, ten each at local port 8080 and
`https://computer2u.eu`: `/`, `/catalog`, `/categories`, `/api/v1/products`,
`/admin/login`, `/checkout/success` return 200; `/admin` and
`/admin/catalog-sync-preview` return 302; `/cart` and `/checkout` return 404.
Expected redirects and closed commerce routes are not failed checks.
`new_service_errors=[]` applies only to the checked interval.

The script records authenticated browser login as NOT RUN. The separate
2026-10-21 owner browser confirmation does not replace an automated login or
Catalog Sync mutation test. No CREATE/UPDATE/import was exercised in this task.

Safe report `backup.files_verified=589` and `owner_restore_result` reports PASS.
The 19 October backup was explicitly accepted by the owner. SQL and storage are
not a common atomic snapshot. No backup payload, restore, current applicability,
or ongoing service health was rechecked in this documentation task.

## Status Discrepancies and Separate Proposed Correction

The target contains the source-revision migration, read model and MySQL tests,
and the attributed deployment record above records P6 on staging. Some current
status sentences still describe P0-06 as unimplemented. Line references below
are bound to `b2d7e0bb428745dfe9d0e39a6973fd34a5cf371c`, not future line numbers:

| Document | Stale or historical wording requiring separate review |
| --- | --- |
| [PHASES](PHASES.md), line 233 and nearby status paragraphs | P0-06..09 unimplemented; no P0-06-or-later migration/operational evidence |
| [Canonical design](IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md), lines 3773-3775, 5190-5191, 5237 onward | Slice 5 definition/authority checkpoint is not a current implementation/deployment summary |
| [Runtime plan](PHASE_9C6_5C3D1_RUNTIME_IMPLEMENTATION_PLAN.md), lines 59-62, 621-623, 1358-1361 | P0-06 unimplemented/implementation-unauthorized |
| [P0-06 proposal](PHASE_III_P0_SOURCE_REVISION_FOUNDATION_PROPOSAL.md), lines 3-10 and closing status | Historical adoption candidate and NOT STARTED wording |

Proposed current-status text for a separate canonical/documentation review:

> Slice 5 / P0-06 schema/read-model foundation is IMPLEMENTATION_PRESENT_DORMANT,
> merged through PR #237 at b2d7e0bb428745dfe9d0e39a6973fd34a5cf371c.
> Push CI 39224805532 succeeded. Owner-executed staging evidence dated
> 2026-10-20 reports exact P6 and is attributed in the Slice 5 staging record.
> Deferred P0-06 writer/integration and P0-07..P0-09 remain unimplemented and
> unauthorized. Neither presence nor deployment authorizes runtime activation.

Do not erase the historical design-adoption checkpoint or treat this proposed
paragraph as authorization. A later change must reconcile the single canonical
status authority, its references and strict documentation-contract expectations
together, without changing frozen inventories/hashes or weakening assertions.
None of those files is edited by this candidate.

## Validation Boundary

Current work: documentation scope, UTF-8, links/anchors, whitespace, secret-pattern
review, hash/source binding, Git diff and preservation checks only. GitHub GETs
and local evidence reads are new; deployment and CI execution are reused evidence.

No application, MySQL, Docker, browser or CI run was started. The isolated
worktree has no `vendor/autoload.php` or bundled `.tools/php/php.exe`;
documentation-contract execution is NOT RUN. No dependencies were installed.
The two added documents do not change the canonical inputs or runtime artifact
discovery paths. The contract also scans all Markdown for specific watchdog
identifiers; neither new document introduces those identifiers. That static
check is not execution of the PHP contract or an independent acceptance PASS.

DEPLOYMENT IN THIS TASK: NOT PERFORMED.
CANONICAL ADOPTION / RUNTIME ACTIVATION IN THIS TASK: NOT PERFORMED.
VPS ACTIONS IN THIS TASK: NONE.
COMMIT / PUSH / PR / MERGE: NOT PERFORMED.

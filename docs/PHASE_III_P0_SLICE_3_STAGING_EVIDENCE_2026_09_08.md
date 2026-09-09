# Phase III-P0 Slice 3: staging evidence, 2026-09-08

## Provenance and interpretation

Operational status: `IMPLEMENTED_MERGED_DEPLOYED_DORMANT`, P0-04 only.
The repository owner supplied the deployment observations and final session/
homepage confirmation. The documentation author did not access the VPS,
staging HTTP, runtime environment, database, raw logs or admin session in this
task. This dated record is not a new health check or a production deployment.

Supplied transcript provenance: `Pasted text(20260908-184655).txt`, 17,171
bytes, SHA-256
`ebcfe908e0d891f87e81faffa5363874757a0d83db6ae4d8f582f0a6cc09b744`.
This is a supplied provenance hash, not an independently verified local-file
hash. Later log output and owner confirmation were supplied in conversation.
No claim is made to have read a separate deployment report or the raw transcript.

Repository facts were independently rechecked by read-only GitHub CLI and
local fetch for this documentation task:

- [PR #224](https://github.com/ivanpavlov-boop/mycomputer-v2/pull/224) is MERGED,
  merged at `2026-09-08T13:21:39Z`.
- Approved implementation head: `59e58535e6975464ca966789001a5a6eb5c3cfeb`.
- Merge/deployed SHA: `05750da719b933937b80af83858a4a7f0803296f`.
- Implementation and merge tree: `279ae04b0780ff887e741f28a007907b701ce87e`.
- PR scope: 17 files, +2436/-81. Its base was
  `b699334087bb53ba4414f8d0e1186441bd92eb8b`.
- [Merge CI #491, run 34231480826](https://github.com/ivanpavlov-boop/mycomputer-v2/actions/runs/34231480826)
  is completed/SUCCESS on that exact merge SHA. `backend`,
  `frontend-validation` and `frontend-browser` each succeeded.
- Freshly fetched main remained at the deployed SHA when this record was
  prepared. This fixed historical SHA is not a promise about future main.

That CI validates its own exact merge bytes, not the later Slice 4 documentation
candidate. The [R6 implementation record](PHASE_III_P0_SLICE_3_IMPLEMENTATION_RECORD.md)
is historical local validation, not this task's test result. Earlier
[Slice 3 design](PHASE_III_P0_SLICE_3_RECEIPT_FOUNDATION_PROPOSAL.md) and
[Slice 2 staging evidence](PHASE_III_P0_SLICE_2_STAGING_EVIDENCE_2026_09_05.md)
retain their dated pre-implementation statements; this record does not rewrite them.

## Owner-provided rollout evidence

Explicit owner approval preceded staging rollout at `https://computer2u.eu`,
repository `/var/www/mycomputer-v2`. No new operational authorization is granted.

| Checkpoint | Supplied observation |
| --- | --- |
| Preflight | `2026-09-08T17:53:15Z` |
| Before branch/HEAD | clean main, `21b201df9b159d7289c7538f56877890c764302a` |
| Preflight runtime | PHP 8.4.25; MySQL 8.4.9 |
| Update | verified fast-forward through PR #223 documentation and PR #224 implementation |
| After HEAD | `05750da719b933937b80af83858a4a7f0803296f` |
| Cumulative VPS diff | 19 files, +3320/-101; not the PR's 17-file diff |
| Migration | `2026_08_28_090003_create_supplier_import_source_payload_receipts_table` |
| Migration result | Ran, batch 28; execution DONE; only this exact path executed |
| Before schema | P3 / NORMAL_PREFIX, 275 objects |
| Before schema SHA-256 | `88e8c410ea052d66c6d8a920143f11fdf3ecca47f4c6c570afcecb16fce1cf2b` |
| After schema | P4 / NORMAL_PREFIX, 294 objects |
| After schema SHA-256 | `1fc28641da815cd4737b50b5b8ed065918b3f416f970ba2cccef82e5342e8d57` |
| Products | 1866 before and after |
| supplier_products | 6717 before and after |
| Source profiles / source executions | 0 / 0 before and after |
| Payload receipts | table absent before; 0 rows after |
| Active Super Admin | same access preserved; 1 active account with panel/user/role-management access before and after |
| Existing admin session | owner explicitly confirmed it continued to work |
| Local / public staging / admin HTTP | 200 / 200 / 200; staging/admin curl followed redirects |
| Browser | owner confirmed homepage worked normally |
| Final Git status | clean |

App, queue and scheduler were rebuilt. New image IDs were reported as app
`6d57bff47820`, queue `0f192955db27`, scheduler `5951ee2cbc4a`. App became
healthy. Configuration, route and view caches rebuilt successfully; queue and
scheduler started with new images after migration; nginx restarted.
`optimize:clear --except=cache` preserved the general application cache.
No frontend rebuild or .env change was performed.

All services were Up. App, MySQL, Redis and Meilisearch were reported healthy;
no healthcheck result is inferred for services without one.

Unchanged row counts are a count check, NOT proof of byte-for-byte content
preservation. Schema hashes describe schema, not catalog content. Redirected
admin HTTP 200 alone is not authenticated-access proof: the separate model
access check and owner-confirmed existing session supply that evidence.
There was no new independent external HTTP observation by this author.

## Supplied summary log scan

Start: `2026-09-08T18:42:10.697503928Z`, from the new app container's StartedAt.
The scan end was not separately timestamped. This is the supplied summary
scan, not this author's review of every raw log message.

| Service | Lines | Suspect matches | Warnings | HTTP 5xx |
| --- | ---: | ---: | ---: | ---: |
| app | 9 | 0 | 0 | 0 |
| queue | 0 | 0 | 0 | 0 |
| scheduler | 47 | 0 | 0 | 0 |
| nginx | 52 | 0 | 0 | 0 |

Laravel logging was stack -> single: one file read, 0 recognized entries after
the start, 0 warnings and 0 errors. An empty queue log does not prove a job ran
or that the entire queue was empty.

## Dormancy and safety

Both supplied preflight and postcheck confirmed effective Catalog Sync values
CREATE=true, UPDATE=false, SYNC_ALL=false, AUTO=false. Manual controlled CREATE
is policy, not permission to execute it. Supplier import remains staging-only;
no direct Product mutation, image import or content/category/attribute overwrite.

All seven effective `supplier_snapshot_capture` gates were false:
capture_enabled, protected_generation_admission_enabled,
recovery_issuance_enabled, recovery_execution_enabled,
monitor_schedule_enabled, observer_schedule_enabled, alert_delivery_enabled.
Preflight said the keys were not explicitly configured and used false defaults;
postcheck compared `config(key, false)` with false. No explicit config file or
env assignment is inferred from those effective defaults.

Code presence remains `IMPLEMENTATION_PRESENT_DORMANT` in the existing
protected presence contract. The dated operational completion above is separate
evidence, not a replacement value in that contract. Neither deployment, merge,
local validation nor CI grants runtime activation.

The [proposed Slice 4 boundary](PHASE_III_P0_SLICE_4_IDENTITY_HEAD_FOUNDATION_PROPOSAL.md)
is subordinate to the canonical Slice 4 definition; design adoption is not
implementation permission. P0-06 through P0-09 remain unsliced and unauthorized.
P0-05 implementation and runtime activation remain UNAUTHORIZED;
the canonical readiness authority and all ten NOT SPECIFIED bounds are unchanged.
No new import, Sync, payload acquisition, queue/scheduler activation, rollback,
VPS access or deployment was performed to write this record.

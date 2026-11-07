# P0-07 Staging Closure Evidence — 2026-11-06

Documentation closure candidate; NOT ADOPTED by this preparation task.
It attributes existing evidence and performs no new staging or database check.

## Completed boundary

P0-07 schema foundation: **IMPLEMENTED_MERGED_DEPLOYED_DORMANT**.
This closes only the guarded source-revision schema foundation. P0-08 is a
canonical correction candidate, NOT ADOPTED; implementation is not authorized.
P0-09 and deferred writer/admission/importer integration are not authorized.
Phase III runtime remains unimplemented, blocked and unactivated. All ten
production numeric policy values remain NOT SPECIFIED.

| Historical binding | Exact identity |
| --- | --- |
| Canonical adoption reviewed head / merged PR | `a60ddad8591973ef2ae57883c4bb20373644fc05`, [PR #238](https://github.com/ivanpavlov-boop/mycomputer-v2/pull/238), merge `fe9f65e8649f3942fbc898c8d5fc5af3093b678f` |
| Dormant implementation reviewed head / merged PR | `a0c826effd9bd53c6318d12d715819d37eaaa873`, [PR #239](https://github.com/ivanpavlov-boop/mycomputer-v2/pull/239) |
| Approved and deployed staging release | `37c1671b07ff33952337a3a5ea86b30411bb74d9` |
| Historical successful CI | [PR run 41195688888](https://github.com/ivanpavlov-boop/mycomputer-v2/actions/runs/41195688888), [main run 41203083388](https://github.com/ivanpavlov-boop/mycomputer-v2/actions/runs/41203083388) |
| Executed rollout package manifest | `bc231a6e8e462f6ff91d658ea24161a2cd65d06f6b547651ea54d861988682fa` |
| Rollout result package | `p007-p6-p7-live-20261106T075444Z`; MANIFEST SHA-256 `663e2b4cdfbff097fccb3f1a2322baecb5e47abfc2158be2cc3fd0eb978d8f8f` |
| Invocation | `9d84d59892f70580d7afac1afc380b5f` |
| Execution interval | 2026-11-06 08:02:42–08:06:10 UTC; 10:02:42–10:06:10 Europe/Sofia |

The existing rollout records one path-specific P0-07 migration submission;
migration child, remote child, SSH and launcher/receiver exit/close are 0/0.
Exact P6 `ea265e4717c2f61abba6731f08be576ff6fcd01fe6b673d61d41011a888056aa`
became exact P7
`765692fc06ba30ee87f27a7357edcb793927a52aef2375846a79257e5e8fcc83`,
350 objects, ledger 112 to 113 with one new row and no remaining pending
migration in that observation. Protected data signatures for 164 existing
tables were preserved behind the writer fence; legacy pointers stayed
NULL/NULL, active Super Admin count was one, and flags remained CREATE=true,
UPDATE=false, SYNC_ALL=false, AUTO=false. Existing source-revision row count
was zero; the live run is not proof of populated-revision preservation.
Compatible populated P6 is covered by the separately attributed isolated R2
validation, not re-executed here.

## Backup, services and limits

Capture, isolated MySQL 8.4 restore and signed PC readback passed for the same
capture manifest
`c812662639b1ea1c39606aa032f6b588db2c1e66644bd502d0cacb87ef51fc26`.
Measured isolated restore time was 86.969351748 seconds. The new and old PC
copies remain retained; the new copy's minimum retention ends no earlier than
2026-12-06 08:06:10 UTC (10:06:10 Europe/Sofia), without automatic deletion.
No backup payload is included in this document or correction review package.
The sequential fenced DB/storage capture is not a distributed atomic backup;
private .env/configuration, keys, Redis and external stores are excluded and
remain under separate custody. Storage ACL/xattr capture is not promised.

Recorded recovery brought the pinned backend services and nginx up; eight
services were running with their documented restart policies and no new
restart loops. App/MySQL/Redis/Meilisearch health was healthy. The same
frontend/MySQL/Redis/Meilisearch container identities were preserved. The
rollout intentionally changed backend/nginx identities; it did not claim
backup-only recovery of those old containers.
Twenty recorded local/HTTPS HTTP checks passed, with the expected
200/302/404 route results and zero new application/queue/scheduler error
markers in the observed interval. Storefront/product visual smoke and safe
flags were subsequently owner-confirmed in the design-review request; that
is an attestation, not a new network check. Authenticated admin login and
continued error-window monitoring remain separate manual checks unless
independently recorded later. No production deployment is attributed.

## Historical checkpoints and validation attribution

The 2026-10-26 five-document approval and then-candidate oracle/adoption
statements remain dated history. Later canonical acceptance, adoption,
implementation, PR/CI and this staging closure supersede their current-status
effect without rewriting original review files or NOT RUN/failure records.
The original R2 Windows preflight failures, design proof limitations and
subsequent isolated validation remain distinct evidence.

Historical implementation R2 validation under each database default was
lifecycle 2 tests / 136 assertions, targeted 105 / 14350 and full backend
2593 / 40426, with the recorded optional .env warning events and limitations.
Those counts, the PR/main CI and this staging run do not validate the new
P0-08 canonical correction candidate. Candidate MySQL validation is NOT RUN.

Read the [canonical design](IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md#p0-08-forward-protocol-correction-candidate-2026-11-06),
[runtime plan](PHASE_9C6_5C3D1_RUNTIME_IMPLEMENTATION_PLAN.md) and
[phase index](PHASES.md) for the proposed current documentation boundary.
No writer/admission, importer, backfill, Catalog Sync activation, automatic
sync, auth/session change or protected product-content overwrite is authorized.

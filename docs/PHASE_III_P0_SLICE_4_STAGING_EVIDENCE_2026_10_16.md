# Phase III-P0 Slice 4 Staging Evidence: 2026-10-16

Recorded on 2026-10-17. This is a dated operational evidence record, not a
schema, semantic, readiness or implementation authority. The
[canonical persistence design](IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md)
remains authoritative. The existing code-presence classification stays
`IMPLEMENTATION_PRESENT_DORMANT`. This separate deployment record reports
`IMPLEMENTED_MERGED_DEPLOYED_DORMANT` for Slice 4 / P0-05, subject to the
provenance and limits below. It does not authorize runtime activation.

The [Slice 4 proposal](PHASE_III_P0_SLICE_4_IDENTITY_HEAD_FOUNDATION_PROPOSAL.md),
[runtime plan](PHASE_9C6_5C3D1_RUNTIME_IMPLEMENTATION_PLAN.md),
[phase index](PHASES.md) and
[Slice 3 deployment history](PHASE_III_P0_SLICE_3_STAGING_EVIDENCE_2026_09_08.md)
are unchanged. Their historical decisions are not rewritten by this record.

## Provenance

| Evidence class | Source and what was checked | Limit |
| --- | --- | --- |
| Independently checked GitHub metadata | Read-only PR, commit, main ref and workflow-run queries on 2026-10-17 | Establishes repository/CI facts, not the deployed host state |
| Existing CI log evidence read locally | Previously retrieved logs for run 38895567191, tied to the same target | Historical execution, not newly executed tests |
| Owner-provided VPS observations | The supplied release/resume, schema, service, preservation, HTTP and restore results below | No VPS, DB, archive or admin-session access in this documentation task |
| Owner browser confirmation | 2026-10-17 response concerning catalog, authenticated admin and Catalog Sync Preview | Human smoke confirmation, not exhaustive automated admin coverage |

Raw release artifacts remain at the owner-supplied host paths. Their contents,
exit codes and artifact hashes have not been independently read here. Missing
batch numbers, timings and exits are not inferred from a reported PASS.

## Repository and CI Facts

- Repository: `ivanpavlov-boop/mycomputer-v2`.
- [PR #234](https://github.com/ivanpavlov-boop/mycomputer-v2/pull/234): `MERGED`;
  `mergedAt=2026-10-16T11:42:36Z`.
- Merge and owner-reported deployed target:
  `be849c48279b65257fdcbcb217f4fa41a58a3104`.
- PR head: `7b2b5cd8c048af2c2e6622cc9d83f6dadef0c102`.
- PR base and owner-reported previous host checkout:
  `477e268687c07c364aba330acfd18912efed043c`.
- The PR-head and merge-commit trees both equal
  `2892872f87f0a93aa816d7b8e62cadf339da90c9`.
- The GitHub `main` ref checked at `2026-10-17T07:28:03Z` still identifies
  the deployed target. This is a dated observation, not a claim about future main.
- [Post-merge CI run 38895567191](https://github.com/ivanpavlov-boop/mycomputer-v2/actions/runs/38895567191):
  event `push`, attempt `1`, head SHA equal to the merge target,
  status `completed`, conclusion `success`.

| Job | Rechecked conclusion |
| --- | --- |
| backend | SUCCESS |
| backend-image-permissions | SUCCESS |
| frontend-validation | SUCCESS |
| frontend-browser | SUCCESS |
| frontend-card-cache | SUCCESS |
| frontend-card-cache-alpine | SUCCESS |

The existing retrieved job logs confirm backend **2556 passed / 31941
assertions**, frontend unit **435 passed**, and browser **88 passed / 2
skipped**. The browser skips are the existing mutually conditional commerce
release-state cases in `frontend/test/browser/commerce-release-states.spec.ts`:
the closed-state case requires `closed`, and the confirmation-only case
requires `confirmation_only`. They are not two additional passes. Job success
and these totals do not establish coverage of every administrative function.
No tests or workflow reruns were performed for this document.

## Owner-Provided Deployment Sequence

Staging: [computer2u.eu](https://computer2u.eu).

- Original release evidence: `/root/mycomputer-v2-pr234-deploy.UmNjID`.
- Resume evidence: `/root/mycomputer-v2-pr234-deploy.UmNjID/resume.eHdT3Q`.
- The first execution was interrupted at `ACTIVATE_WORKERS`.
- The controlled continuation completed worker replacement and final checks
  using the already prepared images. The migration was **not repeated**.
- The supplied terminal summary is reproduced exactly:

```text
UTC=2026-10-16T19:00:22Z RESUME=PASS TARGET=be849c48279b65257fdcbcb217f4fa41a58a3104
```

The cause of the initial delay/interruption remains UNKNOWN. The final summary
does not retrospectively turn the interrupted first execution into a success.

## Owner-Provided Schema and Services

Applied migration:
`2026_08_28_090004_create_supplier_product_identity_heads_table.php`.

| Measurement | Supplied result |
| --- | --- |
| Transition | P4 -> P5 |
| Final classification | P5 / NORMAL_PREFIX |
| Schema objects | 309 |
| Schema SHA-256 | `8cc5afed3a68c4152bf1456a10741c5e23bd077bf4dae39cae639208872ca56f` |
| Applied / pending migrations | 111 / 0 |
| `supplier_product_identity_heads` rows | 0 |

The schema digest agrees with the existing canonical P5 oracle; the *live
measurement* is owner-provided, not a new inspector invocation in this task.

| Backend role | Supplied image identity |
| --- | --- |
| app | `sha256:f0dda771c99c81797dba5af67f16164ceb4575aa96f9cbc6df86cdbdad3f08e0` |
| queue | `sha256:86f064035ad7191c3a2b0896e9ec7031a8c6669e5cb2b9257533e5c9686f4b20` |
| scheduler | `sha256:89c23a42b23064d94a8f1566d70987bce5c8f56cd81a3f962a41cdd6192533cf` |

All eight services were reported running. App, MySQL, Redis and Meilisearch
were reported healthy. No healthcheck PASS is asserted for services without a
healthcheck, and no unprovided container IDs or start times are reconstructed.

## Owner-Provided Preservation and Gates

```text
FLAGS_PRESERVED=PASS
ENV_PRESERVED=PASS
PROTECTED_DATA_HASHES=PASS
SUPER_ADMIN_ID_1=PASS
```

| Protected table | Rows | Supplied full-table content SHA-256 |
| --- | ---: | --- |
| products | 1866 | `e3a305c72bc44f589547e2b4d1bf4b0f9585d89c63c606e02a5d1dfaab93f2ab` |
| supplier_products | 6717 | `9c5a57166d1e7c9fbc81754f959fdd0d90fcaba4f1149d0fe27a7b3197d18a28` |

These are supplied before/after content comparisons using complete table hashes,
not a preservation inference from matching row counts. The guarantee is not
extended to unexamined tables. The supplied Super Admin result preserves the
same active, non-deleted account ID 1; no account identity or credential is
published here.

| Effective setting | Supplied value |
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

`CAPTURE_ACTIVATION=NOT_PERFORMED`. Absent capture keys recorded as
`present=false / value=null` are not configured `false` values. This record
does not invent a per-key capture inventory, assert that configuration was
added, or authorize callers. Manual selected CREATE remains the existing
controlled path; UPDATE, Sync All and automatic sync remain disabled.

## Owner-Provided HTTP and Browser Smoke

The owner reports the following checks from the VPS against both
`localhost:8080` and the public staging URL:

| Routes | HTTP result |
| --- | --- |
| `/`, `/catalog`, `/categories`, `/api/v1/products` | 200 |
| `/admin`, `/admin/catalog-sync-preview` | 302 |
| `/admin/login` | 200 |
| `/cart`, `/checkout` | 404 |
| `/checkout/success` | 200 |

These are VPS-origin observations, not an independent external observer.
The redirects alone do not prove authenticated administration works.

On 2026-10-17 the owner separately confirmed **"Всичко се зарежда!"** in
response to checking catalog, administration after login and Catalog Sync
Preview. This is **owner-confirmed browser/authenticated UI smoke**. No
automated check of all administrative functions, sync writes, checkout or
capture activation is claimed.

## Owner-Provided Backup and Restore Evidence

- Backup: `/root/mycomputer-v2-pre-pr234-20261016T173354Z.muMeXN`.
- Isolated restore evidence:
  `/root/mycomputer-v2-pre-pr234-20261016T173354Z.muMeXN/restore-check.HV4rRw`.
- The supplied result is successful validation of database/storage archives and
  PASS for the isolated restore test.

This is not evidence of off-site custody or a complete atomic VPS backup.
No archive contents, secrets, restore commands or newly computed archive
hashes were accessed in this task.

## Result and Remaining Boundaries

P0-05 staging completion is recorded as
`IMPLEMENTED_MERGED_DEPLOYED_DORMANT`, with GitHub identities independently
checked and host results explicitly owner-provided. The prior interruption,
historical FAIL/STOP results and their unknown causes remain preserved.

P0-06 through P0-09 remain `NOT_SLICED_NOT_AUTHORIZED`. The
[source-revision foundation proposal](PHASE_III_P0_SOURCE_REVISION_FOUNDATION_PROPOSAL.md)
is a separate non-authoritative review candidate, not the next approved slice.
All ten operational bounds remain `NOT SPECIFIED`. No runtime caller,
capture, import, catalog sync, P1 or subsequent Phase III activation follows
from deployment or from this document.

VPS ACTIONS IN THIS DOCUMENTATION TASK: NONE.
NEW DEPLOYMENT / RUNTIME ACTIVATION: NOT PERFORMED.

# Phase 9C.6.5C.3D Runtime Implementation Plan

## Status and authority

This document is a subordinate implementation plan for the approved
[immutable supplier-offer snapshot persistence design](IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md).
It does not replace, relax, or reinterpret that design.

The approved design entered `main` through PR #211:

- reviewed head: `d6ccb67bd1b75e2171ce93846fb48114018ebc0e`;
- merge commit: `2876600deda8592b98fdd70948f76a88aa9c2893`;
- planning baseline: `origin/main` at
  `2876600deda8592b98fdd70948f76a88aa9c2893`;
- canonical tables: 10;
- canonical digest/fingerprint identities: 22;
- recovery protocol matrix: 19 x 3;
- crash matrix: 66 x 11;
- rollout matrix: 103 x 8 with 104 prerequisite edges;
- acceptance criteria: 64;
- focused recovery/monitor cases: 53;
- expected-state fingerprint: `expected_state_fingerprint_v2`, exactly 20
  ordered fields including `claimed_at`;
- republish resume-state fingerprint: exactly 16 ordered fields.

The current implementation baseline is `origin/main` at
`096beef3e619e769ac703fa7c1ea8fa38939db0d`:

- Phase I canonical schema: implemented, merged through PR #212, CI-verified,
  deployed to staging, and behaviorally dormant;
- Phase II guarded models and canonical byte contracts: implemented, merged
  through PR #213, CI-verified, deployed to staging, and uncalled;
- Phase III snapshot persistence/cohort authorization: provenance and durable
  source-binding architecture selected, still unimplemented and not
  implementation-authorized.

<!-- phase-iii-architecture-status-reference authority=phase-iii-architecture-contract-v1 -->
The exact current Phase III architecture status map is owned only by the
[canonical architecture contract](IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md#phase-iii-provenance-and-bounds-architecture-decision).
This plan does not mirror or restate that map. The selected future authority is
an immutable source profile, immutable resolved source context, append-only
source execution, stable supplier/feed/SKU identity head, append-only supplier-
product source revision, guarded current-revision pointer, exact five-field
claim source binding, and a ten-bound operational policy whose numeric values
lack authorized production evidence and remain `NOT SPECIFIED`.
Claim source binding does not replace candidate provenance. The authoritative
proof, fail-closed matrices, selected future schema and limit inventory are in the
[Cohort Enrollment Contract](IMMUTABLE_SUPPLIER_OFFER_SNAPSHOT_PERSISTENCE_DESIGN.md#cohort-enrollment-contract).

This planning phase authorizes no migration, runtime behavior, queue routing,
Redis write, recovery action, monitor schedule, provider call, supplier import,
Catalog Sync action, Product mutation, `supplier_products` mutation, deployment,
or feature activation.

The independent planning-review findings RPR-001 and RPR-002 are closed here
only as implementation-boundary and dependency-order corrections. They do not
change any approved canonical design contract.

## Existing runtime inventory

### Supplier-import entry points and execution

| Current component | Current responsibility | Implementation relevance |
| --- | --- | --- |
| `app/Services/Suppliers/SupplierImportOrchestrator.php` | Creates `SupplierImportRun`, directly dispatches `RunSupplierImportJob`, takes a cache lock, allocates `ImportJob`, invokes XML/CSV import, and closes parents | Main orchestrated-path integration point; direct dispatch and force-release behavior must be replaced only inside the future gated protected path |
| `app/Jobs/RunSupplierImportJob.php` | Runs the orchestrator on queue `imports`; `$tries=3`, `$timeout=3600`; `failed()` directly fails the run | Both routing and failure ownership conflict with the approved protected-path contract |
| `app/Jobs/ProcessSupplierImportRunJob.php` | Alias subclass of `RunSupplierImportJob` | Must inherit the same protected coordinator contract |
| `app/Jobs/ProcessXmlSupplierFeed.php` | Runs `XmlImportEngine` directly on queue `imports`; `$tries=3`, `$timeout=1200`; `failed()` directly fails `ImportJob` | Legacy XML path must converge on the common protected coordinator |
| `app/Services/Imports/XmlImportEngine.php` | Downloads/parses XML, mutates staging rows and attributes, writes failures and counters, and transitions `ImportHistory` | Existing non-repeatable mutation engine; it must not be replayed after `processing` begins |
| `app/Services/Suppliers/SupplierCsvFeedImportService.php` | Imports CSV into staging and writes history/failures/counters | Existing orchestrated CSV mutation engine; protected-path ownership checks must occur before its first side effect |
| `app/Console/Commands/RunScheduledSupplierImports.php` | Dispatches due orchestrated imports | Existing schedule remains separate from monitor scheduling and from Catalog Sync |
| `app/Console/Commands/SyncDueSupplierFeeds.php` | Creates legacy XML `ImportJob` rows and directly dispatches `ProcessXmlSupplierFeed` | Second path that must use the same claim/outbox/coordinator when the protected path is enabled |
| `app/Services/Suppliers/SupplierImportScheduleService.php` | Selects due suppliers and calculates next-run timestamps | Existing import scheduling only; it does not provide protected admission or monitor health |
| `app/Services/Suppliers/SupplierImportSafetyService.php` | Evaluates current row-count/drop safety after import | Existing staging safety projection; it is not immutable snapshot qualification |
| `app/Services/Suppliers/SupplierImportReportService.php` | Persists the mutable `SupplierImportRun.report` projection | May rebuild only non-authoritative report details after canonical terminal commit |
| `app/Services/Suppliers/SupplierImportNotificationService.php` | Sends current import notifications and updates `suppliers.last_import_notification_at` | EXISTS only for the legacy/unprotected transition path; PROHIBITED in protected execution and never the canonical watchdog alert sink or provider capability boundary |
| `app/Services/Suppliers/SupplierImportCapabilityAuditService.php` | Read-only inventory/readiness audit of current import capabilities | Useful regression inventory; it does not authorize protected execution |
| `app/Http/Controllers/Api/V1/Admin/SupplierImportController.php` | Manual and force orchestrated dispatch | Existing authorization surface; no recovery authorization is present here |
| `app/Filament/Resources/Suppliers/Tables/SuppliersTable.php` | Manual/force import actions | Existing human import entry point; it is not a recovery-authorization screen |
| `app/Filament/Resources/ImportJobs/Tables/ImportJobsTable.php` | Direct legacy XML dispatch action | Must not bypass the protected claim/outbox path after activation |
| `app/Filament/Resources/SupplierFeeds/Tables/SupplierFeedsTable.php` | Direct feed import dispatch action | Must not bypass the protected claim/outbox path after activation |
| `app/Filament/Resources/SupplierImportRuns/SupplierImportRunResource.php` | Current run visibility | Future recovery UI may link to it, but mutable run state is not recovery authority |
| `app/Filament/Resources/ImportHistories/ImportHistoryResource.php` | Read-only history visibility | Existing immutable-evidence presentation precedent |

### Existing persistence

| Current component | Current responsibility | Implementation relevance |
| --- | --- | --- |
| `app/Models/Supplier.php` | Supplier identity, schedule and safety settings | Parent of claims, snapshots and enrollments |
| `app/Models/SupplierFeed.php` | Supplier feed identity/configuration | Parent/provenance for protected executions and snapshots |
| `app/Models/SupplierImportRun.php` | Mutable orchestrated-run status/report | Existing parent projection, not the durable execution authority |
| `app/Models/ImportJob.php` | Mutable import counters/state and protected supplier/feed identity once history exists | The Phase I additive ownership index now exists; the model remains a constrained execution parent |
| `app/Models/ImportHistory.php` | Importer-only creation and one `started -> finished/failed` CAS transition; deletion forbidden | Reusable partial immutable generation identity, but not snapshot evidence or execution ownership |
| `app/Models/Concerns/GuardsImportHistoryIdentity.php` | Blocks parent identity changes after history references exist | Reusable parent-identity safety pattern |
| `app/Models/SupplierProduct.php` | Mutable supplier staging record | Existing importer target; not immutable evidence and never a source for snapshot backfill |
| `app/Models/FailedImport.php` | Per-row import failures | Demonstrates why importer replay after `processing` is unsafe |
| `database/migrations/2026_06_07_121751_2_create_import_jobs_table.php` | Creates `import_jobs` | Historical migration remains unchanged; Phase I added `uq_import_job_id_supplier_feed` separately |
| `database/migrations/2026_06_07_121751_3_create_import_histories_table.php` | Creates `import_histories` | Historical migration remains unchanged; Phase I added `ix_import_history_supplier_id` separately |
| `database/migrations/2026_08_13_090000_restrict_import_history_parent_foreign_keys.php` | Makes history parent deletion restrictive | Existing MySQL `RESTRICT` precedent |
| `database/migrations/2026_06_11_170000_create_supplier_import_scheduling_tables.php` | Adds supplier schedule fields and `supplier_import_runs` | Existing orchestrated parent schema; it is not a claim/outbox implementation |

All ten canonical Phase I tables and their guarded Phase II models now exist.
They remain inactive and have no production persistence/runtime caller.

### Locking, queue and Redis

| Current component | Current responsibility | Implementation relevance |
| --- | --- | --- |
| `app/Services/Suppliers/SupplierImportExecutionLock.php` | Uses `Cache::lock('supplier_import:<id>', 3600)` and owner release | Partial owner-token safety, but wrong store/TTL contract and no DB-owner coupling |
| `SupplierImportOrchestrator::execute()` | Creates the same cache lock directly and calls `forceRelease()` for force runs | Conflicts with the approved stale-owner model; protected execution must never delete another owner's lock |
| `config/cache.php` | Generic cache lock connection | No dedicated supplier-import lock/fence readiness contract |
| `config/database.php` | Redis `default` and `cache` connections | Missing `redis_supplier_import` and restricted connection settings |
| `config/queue.php` | Generic Redis queue; default retry interval is environment-driven | Missing dedicated supplier-import connection/queue and `retry_after=3900` |
| `docker-compose.yml` | One general worker consumes `imports` with `--tries=3 --timeout=1200`; Redis 7 | Missing isolated supplier worker and general-worker exclusion of `supplier-imports` |
| `.github/workflows/ci.yml` | Backend CI uses MySQL 8.4, but `CACHE_STORE=array` and `QUEUE_CONNECTION=sync` | Real MySQL exists; real Redis integration coverage is absent |

The durable dispatch-outbox schema/model exists but has no runtime repository or
caller. There is no publication reservation service, external Redis generation
fence, one-use publication effect, Redis retirement receipt, or startup
timing/readiness validator.

### Recovery, monitoring and alerting

No supplier-import runtime implementation exists for:

- recovery authorization or immutable recovery results;
- any of the five canonical recovery actions;
- `suppliers:monitor-import-dispatch-watchdogs`;
- the independent monitor observer;
- monitor-health coordination;
- durable alert intents;
- an alert provider capability adapter;
- publication mismatch reconciliation;
- abandoned processing reconciliation;
- a durable outbox reconciler.

`app/Services/Suppliers/Onboarding/SupplierImportActivityInspector.php` is a
read-only inspection helper for current run/job activity. It may inform UI
presentation, but it is not the canonical monitor, observer, admission gate, or
recovery authority.

### Authorization infrastructure

| Current component | Reusable behavior |
| --- | --- |
| `app/Models/User.php` | `isActiveAdminAccount()` and `isSuperAdmin()` provide the exact active Super Admin predicate |
| `app/Filament/Concerns/RequiresFilamentPermission.php` | Existing Filament navigation/resource authorization pattern |
| `app/Filament/Resources/Users/UserResource.php` | Prevents deletion/deactivation/downgrade of the last active Super Admin |
| `database/seeders/RolesAndPermissionsSeeder.php` | Existing role and permission synchronization; future recovery must not broaden non-Super-Admin authority |
| `app/Policies/ImportHistoryPolicy.php` | Read-only immutable-evidence policy precedent |
| `app/Policies/ImportPolicy.php`, `app/Policies/SupplierPolicy.php` | Current import/supplier permission and parent-reference protections |

The future issuer must use the stricter active Super Admin predicate directly.
Possession of `manage imports`, `manage supplier imports`, or any legacy admin
permission is not sufficient.

### Existing tests to preserve or extend

- `tests/Feature/SupplierImportSchedulingTest.php`;
- `tests/Feature/XmlImportEngineTest.php`;
- `tests/Feature/ApcomSupplierImportTest.php`;
- `tests/Feature/ControlledSupplierStagingImportTest.php`;
- `tests/Feature/SupplierImportActivityInspectorTest.php`;
- `tests/Feature/ImportHistoryGenerationSafetyTest.php`;
- `tests/Feature/ImportHistoryForeignKeyMigrationTest.php`;
- `tests/Feature/ImportHistoryParentDeletionProtectionTest.php`;
- `tests/Feature/OperationalSupplierOfferLifecyclePreviewTest.php`;
- `tests/Unit/Suppliers/Onboarding/OperationalSupplierOfferEvidenceBundleReaderTest.php`;
- `tests/Unit/Suppliers/Onboarding/OperationalSupplierSourceIdentityTest.php`;
- `tests/Unit/Suppliers/Onboarding/SupplierSnapshotQualificationTest.php`;
- `tests/Feature/FilamentAuthorizationTest.php`;
- `tests/Feature/AdminRoleManagementTest.php`;
- `tests/Feature/SupplierOfferLifecycleDocumentationContractTest.php`.

## Approved concept to repository mapping

| Approved table | Deployed inactive foundation | Future runtime owner | Mutability and CAS boundary |
| --- | --- | --- | --- |
| `supplier_import_execution_claims` | Phase I table plus guarded `SupplierImportExecutionClaim` model | `SupplierImportExecutionClaimRepository` | Mutable only through canonical state/owner CAS; path and bound identity are write-once; no delete |
| `supplier_import_dispatch_outbox` | Phase I table plus guarded `SupplierImportDispatchOutbox` model | `SupplierImportDispatchOutboxRepository` | Mutable publication/lease/watchdog state through exact owner/generation CAS; payload/key/deadline immutable; no delete |
| `supplier_import_dispatch_monitor_health` | Phase I singleton table plus guarded `SupplierImportDispatchMonitorHealth` model | monitor-gate repository/service | Singleton generation/owner CAS; observer sequence is independently committed |
| `supplier_import_dispatch_alert_intents` | Phase I table plus guarded `SupplierImportDispatchAlertIntent` model | alert-intent repository/delivery service | Immutable identity/payload; generation-bound delivery lease and bounded state CAS |
| `supplier_import_dispatch_recovery_authorizations` | Phase I table plus append-only `SupplierImportDispatchRecoveryAuthorization` model | authorization repository/issuer | Append-only immutable authorization; no update/delete |
| `supplier_import_dispatch_recovery_results` | Phase I table plus append-only `SupplierImportDispatchRecoveryResult` model | result repository | Append-only sequence 1/2 result events; generated one-start/one-terminal guards |
| `supplier_import_cohort_authorization_members` | Phase I table plus append-only `SupplierImportCohortAuthorizationMember` model | cohort authorization repository | Append-only immutable hashed seed membership |
| `supplier_offer_snapshot_generations` | Phase I table plus append-only `SupplierOfferSnapshotGeneration` model | immutable snapshot repository | Append-only final header; one per claim and history; no update/delete |
| `supplier_offer_snapshot_enrollments` | Phase I table plus append-only `SupplierOfferSnapshotEnrollment` model | immutable snapshot repository | Append-only first enrollment per scope/offer |
| `supplier_offer_snapshot_observations` | Phase I table plus append-only `SupplierOfferSnapshotObservation` model | immutable snapshot repository | Append-only exhaustive generation/enrollment fact |

The canonical helper locations are:

- reuse `app/Data/Suppliers/Onboarding/CanonicalOnboardingData.php` where its
  sorted-object contract is explicitly required by the approved 22 identities;
- reuse `app/Services/Suppliers/Onboarding/OperationalSupplierOfferIdentityHasher.php`
  for the approved supplier SKU, product, and sample domains;
- reuse the deployed stricter `SnapshotSourceIdentity` value object without
  changing the existing broader operational source-identity validator;
- keep fixed-order payload, expected-state, resume-state, result, and alert
  serializers separate from the recursively sorted generic helper where their
  approved byte order is security-relevant.

## Gap analysis

### Current deployed artifact inventory

This table reports repository/deployment presence separately from supplier-
runtime activation. `PRESENT / DEPLOYED` never means that a repository,
coordinator, worker, schedule, recovery action, monitor or capture path calls
the artifact.

| Artifact | Artifact status | Supplier-runtime status | Repository evidence |
| --- | --- | --- | --- |
| `supplier_import_execution_claims` | `PRESENT / DEPLOYED` | `INACTIVE / UNWIRED` | Phase I migration `2026_08_20_120001_create_supplier_import_execution_claims_table.php` |
| `supplier_import_dispatch_outbox` | `PRESENT / DEPLOYED` | `INACTIVE / UNWIRED` | Phase I migration `2026_08_20_120002_create_supplier_import_dispatch_outbox_table.php` |
| `supplier_import_dispatch_monitor_health` | `PRESENT / DEPLOYED` | `INACTIVE / UNWIRED` | Phase I migration `2026_08_20_120003_create_supplier_import_dispatch_monitor_health_table.php` |
| `supplier_import_dispatch_alert_intents` | `PRESENT / DEPLOYED` | `INACTIVE / UNWIRED` | Phase I migration `2026_08_20_120004_create_supplier_import_dispatch_alert_intents_table.php` |
| `supplier_import_dispatch_recovery_authorizations` | `PRESENT / DEPLOYED` | `INACTIVE / UNWIRED` | Phase I migration `2026_08_20_120005_create_supplier_import_dispatch_recovery_authorizations_table.php` |
| `supplier_import_dispatch_recovery_results` | `PRESENT / DEPLOYED` | `INACTIVE / UNWIRED` | Phase I migration `2026_08_20_120006_create_supplier_import_dispatch_recovery_results_table.php` |
| `supplier_import_cohort_authorization_members` | `PRESENT / DEPLOYED` | `INACTIVE / UNWIRED` | Phase I migration `2026_08_20_120007_create_supplier_import_cohort_authorization_members_table.php` |
| `supplier_offer_snapshot_generations` | `PRESENT / DEPLOYED` | `INACTIVE / UNWIRED` | Phase I migration `2026_08_20_120008_create_supplier_offer_snapshot_generations_table.php` |
| `supplier_offer_snapshot_enrollments` | `PRESENT / DEPLOYED` | `INACTIVE / UNWIRED` | Phase I migration `2026_08_20_120009_create_supplier_offer_snapshot_enrollments_table.php` |
| `supplier_offer_snapshot_observations` | `PRESENT / DEPLOYED` | `INACTIVE / UNWIRED` | Phase I migration `2026_08_20_120010_create_supplier_offer_snapshot_observations_table.php` |
| `SupplierImportExecutionClaim` | `PRESENT / DEPLOYED` | `UNCALLED` | Phase II guarded model |
| `SupplierImportDispatchOutbox` | `PRESENT / DEPLOYED` | `UNCALLED` | Phase II guarded model |
| `SupplierImportDispatchMonitorHealth` | `PRESENT / DEPLOYED` | `UNCALLED` | Phase II guarded model |
| `SupplierImportDispatchAlertIntent` | `PRESENT / DEPLOYED` | `UNCALLED` | Phase II guarded model |
| `SupplierImportDispatchRecoveryAuthorization` | `PRESENT / DEPLOYED` | `UNCALLED` | Phase II guarded model |
| `SupplierImportDispatchRecoveryResult` | `PRESENT / DEPLOYED` | `UNCALLED` | Phase II guarded model |
| `SupplierImportCohortAuthorizationMember` | `PRESENT / DEPLOYED` | `UNCALLED` | Phase II guarded model |
| `SupplierOfferSnapshotGeneration` | `PRESENT / DEPLOYED` | `UNCALLED` | Phase II guarded model |
| `SupplierOfferSnapshotEnrollment` | `PRESENT / DEPLOYED` | `UNCALLED` | Phase II guarded model |
| `SupplierOfferSnapshotObservation` | `PRESENT / DEPLOYED` | `UNCALLED` | Phase II guarded model |
| `Phase II canonical byte/value contracts` | `PRESENT / DEPLOYED` | `UNCALLED` | `app/Data/Suppliers/Snapshots`, including the frozen dispatch, recovery, generation, enrollment, observation and reason contracts |
| `SupplierSnapshotFingerprintService` | `PRESENT / DEPLOYED` | `UNCALLED` | Phase II service owns the frozen 22-identity inventory and digest producers |
| `SnapshotSourceIdentity` | `PRESENT / DEPLOYED` | `UNCALLED` | Phase II strict snapshot-source value contract |

### Remaining runtime implementation gaps

| Future runtime component | Artifact status | Runtime status | Evidence and required direction |
| --- | --- | --- | --- |
| Phase III persistence repository and service API | `NOT IMPLEMENTED / MISSING` | `INACTIVE` | No `ImmutableSupplierOfferSnapshotRepository`, cohort-authorization repository/service, collector or capture service exists |
| Immutable candidate-row source provenance | `DESIGNED / NOT IMPLEMENTED` | `INACTIVE` | Append-only source execution plus immutable staging revision is canonical; existing rows remain ineligible until recaptured; exact status is referenced from the canonical architecture contract |
| Durable claim authorization source binding | `DESIGNED / NOT IMPLEMENTED` | `INACTIVE` | Exact five-field tuple, atomic transition, trigger/checks and generation composite FK are fixed; exact status is referenced from the canonical architecture contract |
| Approved production operational bounds | `NOT SPECIFIED` | `BLOCKED` | The ten Phase III limits remain unapproved; exact status is referenced from the canonical architecture contract |
| MySQL ownership CAS repository | `NOT IMPLEMENTED / MISSING` | `INACTIVE` | Deployed claim fields exist, but no one-statement MySQL-UTC acquisition service owns the 4,200-second lease |
| Protected supplier Redis lock | `NOT IMPLEMENTED / MISSING` | `INACTIVE` | The legacy generic lock and `forceRelease()` remain outside the future protected path |
| Durable outbox publisher/reconciler | `NOT IMPLEMENTED / MISSING` | `INACTIVE` | The outbox table/model and dispatch-payload contract exist, but direct legacy dispatch remains unwired to them |
| Redis advance/publish/retire Functions | `NOT IMPLEMENTED / MISSING` | `INACTIVE` | No external generation fence or one-use publication effect exists |
| Protected retry/watchdog coordinator | `NOT IMPLEMENTED / MISSING` | `INACTIVE` | Current job tries/timeouts and `failed()` behavior are still legacy behavior |
| Recovery issuer, repositories and five actions | `NOT IMPLEMENTED / MISSING` | `INACTIVE` | Recovery tables/models/byte contracts exist, but no issuer, nonce flow, command or action implementation calls them |
| Monitor and independent observer runtime | `NOT IMPLEMENTED / MISSING` | `INACTIVE` | Monitor/alert tables and models exist, but no monitor command, lease service, observer heartbeat or freshness admission implementation exists |
| Alert provider capability and delivery runtime | `NOT IMPLEMENTED / MISSING` | `INACTIVE` | No provider is selected and no native-fence/provider-idempotency effect boundary is proven |
| Protected queue/worker/config gates | `NOT IMPLEMENTED / MISSING` | `INACTIVE` | No dedicated supplier-import connection/worker or activatable readiness configuration exists |
| Active Super Admin infrastructure | `PRESENT / DEPLOYED` | `ACTIVE LEGACY INFRASTRUCTURE` | Reusable user predicates and last-admin protection exist; they do not issue recovery authority |
| Legacy completion notification | `PRESENT / DEPLOYED` | `ACTIVE LEGACY PATH ONLY` | The future protected path must bypass it with zero legacy email dispatch and zero `last_import_notification_at` mutation |
| Phase I empty-schema down guard | `PRESENT / DEPLOYED` | `LOCAL/TESTING ONLY` | The complete fail-closed Phase I downgrade capability is implemented; operational rollback remains forward-only |
| MySQL CI | `PRESENT / DEPLOYED` | `ACTIVE` | Backend CI runs against MySQL 8.4 |
| Real Redis CI | `NOT IMPLEMENTED / MISSING` | `INACTIVE` | CI does not start Redis and still uses array cache/sync queue for this surface |

These are expected implementation gaps, not an implementation design conflict.
The current direct path can remain available only while the new protected path
and capture gates are disabled. Activation must be a later, separately
authorized rollout after every dependency is merged and independently proven.

### Protected external-effect boundary

Protected execution remains limited to exactly the three mutating external
coordination/effect surfaces approved by the canonical design:

1. fenced Redis queue publication;
2. fenced/idempotent canonical alert delivery; and
3. owner-token-checked supplier Redis lock acquire/renew/release.

Legacy `SupplierImportNotificationService` is not a fourth protected effect. It
exists only in the legacy/unprotected transition path and is prohibited in the
protected path. No protected email/provider send may bypass durable alert
intents, and no canonical protected alert delivery is possible until Phase X
plus a separately reviewed provider capability satisfy the approved external
boundary contract.

## Dependency graph

```text
approved design
  -> I canonical schema
  -> II models and byte contracts
      -> III-P0 protected source provenance prerequisite foundation
          -> III snapshot persistence core
      -> IV execution claim/allocation/outbox core
          -> V Redis fencing and isolated queue transport
              -> VI common coordinator and protected importer integration
                  -> monitor-readiness contract plus unavailable implementation
                  -> VII recovery authorization
                      -> VIII database-only recovery actions
                      -> IX fenced same-key republication
                  -> X monitor/observer/alert coordination
                      -> DB-backed monitor-readiness implementation
  -> III-P0 + III + VIII + IX + X
      -> XI integration, evidence producer and disabled rollout gates
```

Phase III-P0 must precede Phase III. Phase VI owns the application-facing monitor-readiness contract and binds its
unavailable implementation. For monitor readiness, Phases VII through IX depend
only on that existing contract and remain fail closed; their other prerequisites
remain unchanged. Phase VII requires VI, Phase VIII requires VI plus VII, Phase
IX requires V plus VI plus VII, and Phase X requires the schema/models,
claim/outbox and coordinator foundations through VI. Phase X supplies the
DB-backed implementation without changing the contract. Phase XI requires
III-P0 and III through X. There is no circular dependency, and no later phase may compensate
for a failed earlier invariant. Every phase follows its own local implementation,
validation, independent review, remediation-or-not-required, fresh independent
PASS, push authorization, push, remote verification, Draft PR, CI, PR review,
merge authorization, and merge boundary. Deployment and activation remain
separate later authorizations.

### Planned file allocation

Exact timestamps and narrowly scoped helper names are chosen in each future
implementation candidate, but ownership is fixed as follows.

**Phase I**

- `database/migrations/*_add_supplier_feed_allocation_constraints_to_import_jobs_table.php`;
- `database/migrations/*_create_supplier_import_execution_claims_table.php`;
- `database/migrations/*_create_supplier_import_dispatch_outbox_table.php`;
- `database/migrations/*_create_supplier_import_dispatch_monitor_health_table.php`;
- `database/migrations/*_create_supplier_import_dispatch_alert_intents_table.php`;
- `database/migrations/*_create_supplier_import_dispatch_recovery_authorizations_table.php`;
- `database/migrations/*_create_supplier_import_dispatch_recovery_results_table.php`;
- `database/migrations/*_create_supplier_import_cohort_authorization_members_table.php`;
- `database/migrations/*_create_supplier_offer_snapshot_generations_table.php`;
- `database/migrations/*_create_supplier_offer_snapshot_enrollments_table.php`;
- `database/migrations/*_create_supplier_offer_snapshot_observations_table.php`;
- `database/migrations/*_add_supplier_id_id_index_to_import_histories_table.php`;
- `tests/Feature/SupplierOfferSnapshotMigrationTest.php` and focused MySQL
  schema/down-guard tests.

**Phase II**

- `app/Models/SupplierImportExecutionClaim.php`;
- `app/Models/SupplierImportDispatchOutbox.php`;
- `app/Models/SupplierImportDispatchMonitorHealth.php`;
- `app/Models/SupplierImportDispatchAlertIntent.php`;
- `app/Models/SupplierImportDispatchRecoveryAuthorization.php`;
- `app/Models/SupplierImportDispatchRecoveryResult.php`;
- `app/Models/SupplierImportCohortAuthorizationMember.php`;
- `app/Models/SupplierOfferSnapshotGeneration.php`;
- `app/Models/SupplierOfferSnapshotEnrollment.php`;
- `app/Models/SupplierOfferSnapshotObservation.php`;
- `app/Data/Suppliers/Onboarding/SnapshotSourceIdentity.php`;
- narrowly scoped serializers/fingerprint value objects under
  `app/Data/Suppliers/` and `app/Services/Suppliers/`;
- `tests/Unit/Suppliers/SupplierOfferSnapshotFingerprintTest.php` plus exact
  dispatch/recovery/alert vector tests.

**Phase III-P0**

- one additive migration adding the canonical composite supplier-feed ownership
  parent key without editing the historical supplier-feed migration;
- five additive migrations creating `supplier_import_source_profiles`,
  `supplier_import_source_executions`,
  `supplier_import_source_payload_receipts`,
  `supplier_product_identity_heads`, and `supplier_product_source_revisions` in
  dependency order;
- one additive migration adding the guarded identity-head/current-revision
  pointers, indexes and composite FKs to `supplier_products`;
- one additive migration adding the five-field `cohort_source_identity`
  authority and generation-to-claim source FK;
- one later additive migration adding immutable ten-bound policy-v2 authority
  to claims/generations, with all ten numeric values still `NOT SPECIFIED`;
- `app/Models/SupplierImportSourceProfile.php`;
- `app/Models/SupplierImportSourceExecution.php`;
- `app/Models/SupplierImportSourcePayloadReceipt.php`;
- `app/Models/SupplierProductIdentityHead.php`;
- `app/Models/SupplierProductSourceRevision.php`;
- `app/Data/Suppliers/Imports/ImportJobIdentity.php`;
- `app/Data/Suppliers/Imports/ResolvedSupplierImportSourceContext.php`;
- `app/Data/Suppliers/Imports/BoundedImmutableSourcePayload.php`;
- source-profile, ImportJob identity, execution, payload-receipt, context and
  policy-v2 canonical contracts under
  `app/Data/Suppliers/` and `app/Services/Suppliers/`;
- dormant allowlisted policy definitions in `config/supplier_snapshot.php`,
  with no approved numeric policy key until all ten values are authorized;
- `app/Services/Suppliers/SupplierImportSourceContextResolver.php`;
- one protected bounded downloader/parser adapter boundary that accepts only
  the resolved context and same-handle immutable payload, not a mutable
  `ImportJob`, `SupplierFeed`, pathname or mapping model; persists exactly one
  append-only payload receipt per execution and verifies receipt size/SHA-256
  at parser EOF; and implements `protected_source_redirect_policy_v1` by disabling redirect
  following and failing every `3xx` before destination contact, payload
  acceptance or parser invocation;
- focused MySQL schema, locked ImportJob selector, immutable
  profile/execution/receipt/revision, A-to-B TOCTOU, redirect rejection,
  bounded source-byte, same-handle mutation, crash reconstruction and zero-
  Product-mutation tests.

**Phase III**

- `app/Repositories/Suppliers/ImmutableSupplierOfferSnapshotRepository.php`;
- `app/Repositories/Suppliers/SupplierImportCohortAuthorizationRepository.php`;
- `app/Services/Suppliers/Snapshots/SupplierImportCohortAuthorizationService.php`;
- `app/Services/Suppliers/Snapshots/SupplierOfferSnapshotCollector.php`;
- `app/Services/Suppliers/Snapshots/SupplierOfferSnapshotCaptureService.php`;
- `app/Services/Suppliers/Snapshots/ImportHistorySnapshotSourceAdapter.php`;
- `tests/Feature/SupplierOfferSnapshotPersistenceTest.php`;
- `tests/Feature/SupplierOfferSnapshotCaptureTest.php`;
- `tests/Feature/SupplierOfferSnapshotConcurrencyTest.php`;
- `tests/Feature/SupplierImportCohortAuthorizationTest.php`.

**Phase IV**

- `app/Repositories/Suppliers/SupplierImportExecutionClaimRepository.php`;
- `app/Repositories/Suppliers/SupplierImportDispatchOutboxRepository.php`;
- `app/Repositories/Suppliers/SupplierImportAllocationRepository.php`;
- `app/Repositories/Suppliers/SupplierImportStateInvariantRepository.php`;
- `app/Repositories/Imports/TransactionalImportGenerationStartRepository.php`;
- `app/Repositories/Imports/TransactionalImportTerminalRepository.php`;
- `tests/Feature/SupplierImportExecutionIdempotencyTest.php`;
- `tests/Feature/SupplierImportDispatchOutboxTest.php`;
- `tests/Feature/SupplierImportAllocationTest.php`.

**Phase V**

- `.env.example`, `config/database.php`, `config/queue.php`,
  `docker-compose.yml`, and `.github/workflows/ci.yml`;
- versioned Redis Function asset under a reviewed supplier-import deployment
  location;
- `app/Services/Suppliers/SupplierImportDispatchOutboxPublisher.php`;
- `app/Services/Suppliers/SupplierImportQueueTimingValidator.php`;
- `tests/Feature/SupplierImportQueueTimingTest.php`;
- `tests/Feature/SupplierImportMysqlRedisRecoveryTest.php`;
- dedicated real-Redis function/adversarial tests.

**Phase VI**

- `app/Contracts/Suppliers/SupplierImportDispatchMonitorReadiness.php`;
- `app/Services/Suppliers/UnavailableSupplierImportDispatchMonitorReadiness.php`;
- the fail-closed container binding in `app/Providers/AppServiceProvider.php`;
- `app/Jobs/RunSupplierImportJob.php`;
- `app/Jobs/ProcessSupplierImportRunJob.php`;
- `app/Jobs/ProcessXmlSupplierFeed.php`;
- `app/Services/Suppliers/SupplierImportOrchestrator.php`;
- `app/Services/Suppliers/SupplierImportExecutionLock.php`;
- `app/Services/Suppliers/SupplierImportDeliveryAdmissionService.php`;
- `app/Services/Suppliers/SupplierImportExecutionCoordinator.php`;
- `app/Services/Suppliers/SupplierImportInHandleFailureService.php`;
- `app/Services/Suppliers/SupplierImportTransportFailureService.php`;
- affected CLI/API/Filament dispatch entry points listed in the runtime
  inventory;
- `tests/Feature/SupplierImportCrashRecoveryTest.php`;
- `tests/Feature/SupplierImportFailedCallbackTest.php` and existing import
  regression suites.

**Phase VII**

- `app/Repositories/Suppliers/SupplierImportDispatchRecoveryAuthorizationRepository.php`;
- `app/Repositories/Suppliers/SupplierImportDispatchRecoveryResultRepository.php`;
- authorization issuer/issued result/nonce reader under
  `app/Services/Suppliers/` and `app/Data/Suppliers/`;
- a dedicated read-only Filament recovery-candidate resource under
  `app/Filament/Resources/`;
- `tests/Feature/SupplierImportDispatchRecoveryAuthorizationTest.php`;
- focused Filament/Super-Admin authorization tests.

**Phase VIII**

- `app/Repositories/Imports/ExpiredQueuedImportTerminalRepository.php`;
- `app/Repositories/Imports/PublicationMismatchTerminalRepository.php`;
- `app/Repositories/Imports/AbandonedSupplierImportTerminalRepository.php`;
- action-specific command/services under `app/Console/Commands/` and
  `app/Services/Suppliers/`;
- `app/Console/Commands/ReconcileAbandonedSupplierImportExecutions.php`;
- `app/Console/Commands/ResolveImportPublicationMismatch.php`;
- `tests/Feature/SupplierImportPublicationMismatchResolutionTest.php` and
  database-only recovery concurrency tests.

**Phase IX**

- `app/Console/Commands/ReconcileSupplierImportDispatchOutbox.php`;
- the authorized Phase A/B continuation in
  `app/Services/Suppliers/SupplierImportDispatchOutboxPublisher.php`;
- `tests/Feature/SupplierImportPublishedPayloadWatchdogTest.php`;
- focused Redis republication and stale-worker tests.

**Phase X**

- `app/Services/Suppliers/DbBackedSupplierImportDispatchMonitorReadiness.php`;
- replacement of the Phase VI unavailable binding in
  `app/Providers/AppServiceProvider.php` only after the DB-backed implementation
  exists;
- `app/Services/Suppliers/SupplierImportDispatchAlertSink.php`;
- monitor/observer/alert repositories and provider capability value objects;
- `app/Console/Commands/MonitorSupplierImportDispatchWatchdogs.php`;
- `app/Console/Commands/ObserveSupplierImportDispatchMonitorHealth.php`;
- `routes/console.php` only when a separately reviewed disabled-by-default
  schedule gate is introduced;
- `tests/Feature/SupplierImportDispatchWatchdogMonitorTest.php`;
- provider adapter contract and monitor/alert adversarial tests.

**Phase XI**

- `config/supplier_snapshot_capture.php`;
- `app/Services/Suppliers/Onboarding/OperationalSupplierOfferEvidenceProducer.php`;
- `app/Console/Commands/PrepareOperationalSupplierOfferLifecycleEvidence.php`;
- `tests/Feature/OperationalSupplierOfferEvidenceProducerTest.php`;
- aggregate traceability, zero-mutation, rollout and operational rollback
  tests.

## Recommended implementation phases

### Phase I - Canonical MySQL schema foundation

**Status.** Implemented, merged through PR #212, CI-verified, deployed to
staging, and inactive. The text below records its approved implementation
contract; it is no longer an unimplemented proposal.

**Goal.** Create the complete ten-table schema and required existing-table
indexes/constraints without changing runtime dispatch or capture.

**Why one schema PR.** The approved downgrade contract evaluates one complete
ten-table empty/pristine predicate before the first destructive DDL. Splitting
the canonical schema across independently reversible PRs would make that
predicate partial or allow a partial rollback. This is the only intentionally
broad PR; it remains schema-only and behaviorally dormant.

**Planned components.** The 10 create migrations, the additive
`import_jobs` ownership migration, the additive `import_histories` range-index
migration, append-only/path triggers, and a migration-local process-scoped
empty-schema downgrade guard shared for one rollback invocation.

**Tests.** New MySQL migration contract tests inspect `SHOW CREATE TABLE`,
`information_schema.statistics`, named FKs/indexes/checks/triggers, generated
columns, the pristine monitor singleton, partial-schema rejection, every
protected-domain non-empty rejection, environment/confirmation rejection, and
the exact empty-schema up/down/up success path.

**Gates.** All new runtime gates absent or false; singleton starts `unknown` at
generation/sequence zero.

**Exclusions.** No Eloquent runtime use, queue change, Redis operation,
schedule, recovery, import hook, backfill, or evidence row.

**Mutation boundary.** The completed Phase I staging deployment added schema
only. It did not mutate `products` or `supplier_products`.

**PR gate.** Independent Database, Security, and Catalog Sync Safety review;
real MySQL 8.4 is mandatory.

### Phase II - Models and canonical byte contracts

**Status.** Implemented, merged through PR #213, CI-verified, deployed to
staging, and uncalled. The canonical 22 identities, 20-field expected state,
16-field resume state, 42-field generation header, 13-field observation,
5-field enrollment, reason allowlist, and golden hashes remain frozen.

**Goal.** Add guarded Eloquent models, value objects, enums/constants, canonical
serializers, and all approved digest producers without connecting them to live
imports.

**Planned components.** The ten models in the approved Future Implementation
Map; `SnapshotSourceIdentity`; immutable model guards; dispatch payload,
expected-state v2, 16-field resume-state, recovery-result, snapshot, cohort,
and alert serializers/fingerprint services.

**Tests.** Unit tests reproduce every normative byte/digest vector, enforce
exact key order/type/UTC microseconds/lowercase hex, reject unknown or missing
fields, and prove model serialization hides sensitive hashes/tokens where
required. Model mutation/delete tests prove append-only behavior.

**Gates.** No runtime caller; all activation remains false.

**Exclusions.** No queue, lock, importer, recovery UI, monitor, provider, or
schedule integration.

**Mutation boundary.** No Product or staging mutation.

**PR gate.** Independent Security and Database review of exact bytes and
immutability.

### Phase III-P0 - Protected source provenance prerequisite foundation

**Status.** Architecture design only. Nothing in this subphase is implemented
or implementation-authorized. It is additive to, and does not rewrite, the
historical deployed Phase I ten-table foundation or frozen Phase II contracts.

**Goal.** Implement the dormant prerequisites that make source provenance and
source capture mechanically enforceable before snapshot persistence can be
activated: immutable profile/execution authority, stable logical heads,
append-only revisions, guarded staging pointers, exact claim/generation source
binding, the immutable resolved-source-context API, bounded source download,
and operational policy v2 with all ten values still unapproved.

**Dependency and activation.** Phase III-P0 depends on deployed Phase I and
Phase II. Its schema and models deploy dormant. No protected downloader,
parser, capture or import path may activate until every Phase III-P0 migration,
model, byte contract, A-to-B test, policy-v2 test and crash reconstruction test
passes independent review. Phase III depends on this complete inactive
foundation; no later phase may substitute for it.

**Rollback/readiness.** Operational rollback is forward-only and preserves all
new provenance evidence. Destructive local/testing downgrade uses the canonical
P0-P9 exact-prefix state machine and shared invocation-scoped
`CanonicalSupplierPhaseThreeP0Schema` coordinator. It removes only the terminal
migration group after complete structural and empty/pristine validation before
first DDL; arbitrary subsets and process-wide cached authorization are
forbidden. Any
legacy row without unambiguous immutable provenance remains ineligible; no
backfill may infer history from current feed URL, mapping or credentials.

**Exclusions.** No import activation, staging recapture, Product mutation,
Catalog Sync, source fetch, production bound value, provider, queue, schedule or
historical backfill.

**PR gate.** Independent Database, Supplier Import, Security, QA and Catalog
Sync Safety review with real MySQL 8.4 for schema authority.

### Phase III - Snapshot persistence and cohort authorization core

**Status.** Architecture design only. Runtime implementation is not authorized.
The exact status map is referenced from the canonical architecture contract.
No class listed below may be implemented until Phase III-P0 is implemented and
independently accepted, all ten operational bounds are evidence-backed and
approved, and a later explicit implementation authorization is issued after
independent review.

**Goal.** Implement deterministic, atomic snapshot persistence behind a
service API that is not yet called by production import paths.

**Planned components.** `SupplierImportCohortAuthorizationRepository`,
`SupplierImportCohortAuthorizationService`,
`ImmutableSupplierOfferSnapshotRepository`, `SupplierOfferSnapshotCollector`,
`SupplierOfferSnapshotCaptureService`, and `ImportHistorySnapshotSourceAdapter`.
These consume the persisted Phase III-P0 provenance/context/policy authorities;
they do not create those prerequisites inside Phase III.

**Tests.** Atomic generation/enrollment/observation reconciliation, duplicate
same-fingerprint acceptance, conflicting duplicate rejection, bounded
collection, complete absence rows only after exhaustive traversal, no mutable
state reread at finalization, source-only authorized baseline, concurrent final
header uniqueness, and zero Product/staging mutation.

**Gates.** `capture_enabled=false` and
`protected_generation_admission_enabled=false` by default.

**Exclusions.** No job hook, download, importer execution, recovery, monitor,
queue publication, lifecycle application, or backfill.

**Mutation boundary.** Synthetic tests may write only the new evidence tables
and parents in isolated databases. Runtime `products` and `supplier_products`
remain untouched.

**PR gate.** Independent Product Data Quality, Database, Security, and Catalog
Sync Safety review.

### Phase IV - Execution claim, allocation and durable outbox core

**Goal.** Implement database authorization/allocation and canonical state
repositories, still without publishing externally.

**Planned components.** `SupplierImportExecutionClaimRepository`,
`SupplierImportAllocationRepository`,
`SupplierImportDispatchOutboxRepository`,
`SupplierImportStateInvariantRepository`, transactional ImportHistory start and
terminal repositories, and the seven-key dispatch serializer.

**Tests.** One claim per logical key/run/job/history, legacy/orchestrated path
shape, allocation rollback, composite parent ownership, canonical pair matrix,
cross-record mismatch rejection, transport deadline/counter immutability,
watchdog tuple rules, and direct-dispatch prohibition in the protected API.

**Gates.** Protected admission remains false; repository methods are not
reachable from current import actions.

**Exclusions.** No Redis publish, job routing, importer call, recovery, monitor,
or snapshot hook.

**Mutation boundary.** New coordination rows only in isolated tests. No Product
or staging mutation.

**PR gate.** Independent Database, Queue, Security, and Catalog Sync Safety
review.

### Phase V - Redis fencing and isolated supplier-import transport

**Goal.** Add the external one-use publication authority and queue isolation
before any protected import can use it.

**Planned components.** `redis_supplier_import`, queue `supplier-imports`,
restricted Redis ACL configuration, dedicated Docker worker, queue timing
validator, fenced publisher/reconciler, and the exact Redis 7 Function library
exporting:

- `supplier_import_advance_fence_v1`;
- `supplier_import_publish_fenced_v1`;
- `supplier_import_retire_fence_v1`.

The fence key is
`supplier-import:dispatch-fence:v1:{<logical_execution_key>}`. Direct queue
publication from the protected path is forbidden. The general worker must not
consume `supplier-imports`; the dedicated worker must not consume unrelated
queues. The exact hierarchy is `3600 < 3900 < 4200 < 4320`, with bootstrap
bounded to 60 seconds.

**Tests.** Real Redis 7 integration and adversarial tests cover one-use
publication, stale generation, suspended worker A/successor B, crash before
advance, crash after advance before DB reconciliation, generation-one missing
key bootstrap only, conflicting state, retirement, and exact external effect
counters. CI gains a Redis service or a dedicated Redis integration job in
this future PR; mocked Redis cannot satisfy acceptance.

**Gates.** `redis_fencing_ready` is derived from exact Function version,
connection/ACL, timing and queue-isolation checks. Protected admission remains
false even when readiness passes.

**Exclusions.** No active importer routing, automatic recovery, monitor
schedule, provider, or Catalog Sync.

**Mutation boundary.** Redis test namespaces and new coordination rows only.
No Product or staging mutation.

**PR gate.** Independent Redis/Queue, Security, Release, Database, and Catalog
Sync Safety review.

### Phase VI - Common protected execution coordinator

**Goal.** Make both import job types use one owner-checked coordinator when,
and only when, protected admission is explicitly enabled after rollout. It also
establishes the fail-closed monitor-readiness contract required by later
recovery phases and excludes the legacy completion-notification path from every
protected execution.

**Planned components.** `SupplierImportDeliveryAdmissionService`,
`SupplierImportExecutionCoordinator`, `SupplierImportInHandleFailureService`,
`SupplierImportTransportFailureService`, corrected
`SupplierImportExecutionLock`, and changes to both jobs, the orchestrator and
legacy dispatch entry points. The protected job contract becomes `$tries=8`,
`$timeout=3600`, no `retryUntil()`, dedicated connection/queue, immutable
24-hour MySQL deadline, cumulative delivery budget, 4,200-second DB lease and
4,320-second Redis lock/watchdog TTL boundary.

The exact application-facing readiness contract is
`SupplierImportDispatchMonitorReadiness`, with the single safety API
`isReadyForProtectedActivity(): bool`. Phase VI binds
`UnavailableSupplierImportDispatchMonitorReadiness`, which always returns
`false`. It does not inspect a config flag, environment variable, process
presence or static state. No protected operation may bypass this contract or
substitute a config-only readiness decision.

Before any importer side effect, the coordinator must own the supplier lock,
bind one complete MySQL owner tuple, require `queued/published`, allocate/bind
the history, commit capture-start authorization, bind the source fingerprint,
and CAS to `processing`. In-handle exceptions retain raw token/lock proof.
Newly deserialized `failed()` is transport-only and cannot close processing.
`forceRelease()` is removed from the protected path.

When the protected path owns an execution, orchestrator completion bypasses
`SupplierImportNotificationService` completely. Successful, failed, duplicate
and stale protected deliveries dispatch zero legacy `SendEmailJob` instances
and do not update `suppliers.last_import_notification_at`. The existing legacy
notification behavior may remain unchanged only while execution remains on the
legacy/unprotected path. There is no hybrid path. Any future protected
notification is owned exclusively by the Phase X durable alert-intent contract
and a later provider adapter proving `native_generation_fence` or
`provider_enforced_idempotency`; Phase VI performs no canonical alert delivery.

**Tests.** Sequential/concurrent duplicate delivery, lock contention,
deadlock/timeout/zero-row CAS, two paths, source fingerprint reuse/conflict,
crash boundaries, failed callback races, no importer replay after processing,
atomic finalization, exhaustive evidence, and existing XML/CSV staging-only
behavior. Add explicit successful/failed protected-execution assertions that
legacy `SendEmailJob` dispatch count is zero and
`last_import_notification_at` is unchanged; repeat them for duplicate/stale
workers. Separately preserve the legacy notification regression while the
protected gate is disabled. Protected success/failure tests may bind an explicit
test-only readiness double returning true after their synthetic prerequisites;
that double is never a runtime fallback. Test the production readiness binding
and every protected safety-sensitive entry point with the unavailable
implementation returning false and producing zero protected effects. Real
MySQL and Redis are required.

**Gates.** Protected admission and capture remain false by default. The Phase
VI readiness binding is unavailable/fail-closed. Startup requires schema,
DB-backed monitor, observer, sink, queue timing, Redis fencing and capture
readiness before either can become true in a later operational authorization.

**Exclusions.** No recovery action, DB-backed monitor logic, monitor schedule,
alert provider, canonical alert delivery, legacy protected-path completion
notification, Product write, Catalog Sync, or automatic activation.

**Mutation boundary.** When later enabled, existing import engines may continue
their existing `supplier_products` staging writes under the new ownership
boundary only through the Phase III-P0 guarded identity-head/current-revision
projection. Those additive provenance pointers do not authorize new catalog
semantics, source-content overwrite or any `products` mutation.
Protected execution never mutates `suppliers.last_import_notification_at`
through the legacy notification flow.

**PR gate.** Independent Database, Redis/Queue, Supplier Import, Security, QA,
and Catalog Sync Safety review.

### Phase VII - Recovery authorization and immutable result framework

**Goal.** Implement issuance, one-time nonce handling, exact fingerprints and
append-only audit infrastructure, with execution disabled.

**Planned components.** Recovery authorization/result repositories, issuer and
non-serializable issued-value object; one read-only recovery-candidate Filament
resource/detail action visible only to active Super Admin; CLI nonce reader;
expected-state v2 and resume-state validation.

Issuer signature remains exactly the approved server-derived API. The browser
cannot submit operator, key, parent, or fingerprint. The raw 32-byte nonce is
returned once as unpadded base64url, accepted only through protected stdin,
hashed with the approved domain, never logged/persisted/queued, and zeroed or
released promptly.

**Tests.** Active Super Admin only, inactive/deleted/non-Super-Admin denial,
last-active-Super-Admin preservation, all five action predicates, 900-second
expiry, nonce uniqueness/constant-time verification, tuple FKs, conflicting
authorization, sequence guards, cross-action result rejection, secret scans,
and no target mutation while execution is disabled. With
`UnavailableSupplierImportDispatchMonitorReadiness`, issuance is rejected and
all target/authorization/result rows remain unchanged.

**Gates.** `recovery_issuance_enabled=false` and
`recovery_execution_enabled=false` by default. Issuance additionally requires
`SupplierImportDispatchMonitorReadiness::isReadyForProtectedActivity()` to
return true. The Phase VI default returns false; config alone cannot satisfy
this requirement.

**Exclusions.** No recovery mutation, Redis call, provider, import, Product, or
staging write.

**PR gate.** Independent Security, Filament Authorization, Database, and
Catalog Sync Safety review.

### Phase VIII - Database-only recovery actions

**Goal.** Implement the four actions that have no external Phase B:

- `recover_expired_queued_ownership`;
- `terminalize_stale_dispatch`;
- `terminalize_publication_mismatch`;
- `terminalize_abandoned_processing`.

**Planned components.** Expired queued-owner, stale dispatch, publication
mismatch and abandoned processing repositories/services/commands, all using
the exact authorization/result framework and fixed lock order.

**Tests.** Exact issue/start predicates, complete owner CAS, successor/live
owner races, rollback/replay, no clear-first operation, action/result
compatibility, parent terminalization, watchdog clearing, one-target dry-run,
no broad selector, no importer replay, and zero snapshot/Product/staging writes.
With unavailable monitor readiness, every Phase-A mutation is rejected before
`started` and all domain/recovery rows remain unchanged.

**Gates.** Execution remains false by default and requires a current unexpired
authorization, nonce proof, active Super Admin continuity before `started`,
the Phase VI monitor-readiness contract returning true, supplier lock and exact
row locks. There is no config-only escape hatch.

**Exclusions.** `republish_same_key`, any Redis effect, new import key,
automatic action, source access, Catalog Sync, Product or staging mutation.

**PR gate.** Independent Database Concurrency, Security, Supplier Import, and
Catalog Sync Safety review.

### Phase IX - Fenced same-key republication

**Goal.** Implement only `republish_same_key` Phase A/B over the already-proven
Redis fence.

**Planned components.** Authorized outbox reconciler/command and publisher
resume logic. Phase A writes the exact resume state and `started`; Phase B0
revalidates the immutable baseline; Phase B1 reserves one ordinal/generation
and uses the original byte-identical payload through the exact fence.

**Tests.** Same-key success, ambiguity, attempts/deadline/response preservation,
boundary closure before call, `action_stopped`, attempt eight, unknown result,
external retirement, successor generation, stale worker zero effect, competing
authorization, idempotent terminal result and proof that republish never
terminalizes claim/outbox/parents. With unavailable monitor readiness, Phase A
is rejected with zero DB mutation; an unavailable or newly stale readiness
check before Phase B produces zero Redis calls and zero external effects.

**Gates.** Recovery execution and Redis readiness must both pass;
`SupplierImportDispatchMonitorReadiness` must return true at mutation admission
and immediately before each external call. The unavailable binding always
fails closed, and no config-only value can replace the readiness result.

**Exclusions.** New logical key/event, attempt nine, direct publish,
cross-action terminalization, importer call, source access, Product/staging
write or automatic recovery.

**PR gate.** Independent Redis Adversarial, Database Concurrency, Security,
Supplier Import, and Catalog Sync Safety review.

### Phase X - Monitor, observer and alert coordination

**Goal.** Implement the domain-read-only watchdog monitor, independent
observer, DB-backed implementation of the Phase VI monitor-readiness contract,
and durable alert-intent state machine while leaving provider delivery and
scheduling disabled.

**Planned components.**
`DbBackedSupplierImportDispatchMonitorReadiness`, monitor/observer repositories
and commands, `MonitorSupplierImportDispatchWatchdogs`,
`ObserveSupplierImportDispatchMonitorHealth`, alert serializer/repository,
`SupplierImportDispatchAlertSink` capability interface, and an explicit
unsupported/unavailable adapter.

Monitor cadence is 300 seconds with overlap prevention only after separate
enablement. Monitor lease is 240 seconds. Derived health requires the latest
complete cycle and matching sink contract within 600 seconds and an independent
observer commit bound to that generation/cycle within 120 seconds. Alert lease
is five minutes; attempt budget is eight. Warning/critical rules and the two
approved alert digest vectors remain exact.

**Tests.** Due ordering and populated `EXPLAIN`, four monitor crash rows,
generation takeover/stale CAS, observer independence/freshness, privacy-safe
output, zero domain writes/jobs, durable alert identity, lease takeover,
attempt budget, provider unsupported closure, and
`delivery_outcome_unknown_exhausted` invariants. The DB-backed readiness
implementation returns true only for the exact current healthy monitor,
observer and sink evidence. `stale`, `failed`, `unknown`, DB unavailable,
generation/lease mismatch and unsupported provider states return false.

**Provider boundary.** No provider is selected. A future provider-specific PR
must prove exactly one capability mode at the real effect boundary:
`native_generation_fence` or `provider_enforced_idempotency`. Until then,
provider readiness is false, no delivery lease/provider call is allowed, and
monitor integrity cannot become operationally ready.

**Gates.** `monitor_schedule_enabled=false`, `observer_schedule_enabled=false`,
and `alert_delivery_enabled=false`. Health is derived, not overridden. Phase X
may bind the DB-backed implementation, but it is eligible to return true only
after canonical monitor/observer/sink prerequisites pass. Recovery remains
inactive until that evidence exists and a separate activation is authorized.

**Exclusions.** No claim/outbox/parent/evidence/staging/Product mutation, no
recovery issuance, no provider credentials/call, no import, and no Catalog
Sync.

**PR gate.** Independent Database, Security, Release, Provider-boundary, and
Catalog Sync Safety review.

### Phase XI - Evidence producer and disabled integration readiness

**Goal.** Connect only the fully proven protected pipeline and add the bounded,
read-only operational evidence producer, while every operational gate remains
off after merge/deployment.

**Planned components.** `OperationalSupplierOfferEvidenceProducer`,
`PrepareOperationalSupplierOfferLifecycleEvidence`, integration configuration,
startup readiness report, operational rollback controls, and complete
cross-subsystem acceptance suites.

**Tests.** All 64 criteria, all 53 focused cases, all 19 protocol outcomes, all
66 crash rows, zero-mutation evidence projection, bounded reads/bytes,
privacy/secret scans, queue isolation, startup failure closure, forward-only
operational rollback and complete feature-gate matrix.

**Gates.** Every gate stays false after code deploy. A later governed sequence
must deploy schema/code disabled, validate MySQL/Redis/provider readiness,
enable monitor/observer/sink separately, observe fresh health, then separately
authorize capture. Import/candidate/preview/closeout remain later distinct
authorizations under the canonical 103-checkpoint rollout.

**Exclusions.** No real candidate, import, lifecycle decision, Product/staging
mutation, Catalog Sync, provider selection, automatic recovery or enablement.

**PR gate.** Full independent aggregate review by Database, Security, Release,
Supplier Import, Product Data Quality, QA, and Catalog Sync Safety.

## Future Phase III-P0 provenance migration allocation

This future allocation is additive and dormant. It is not part of the deployed
Phase I ten-table count and does not amend any Phase I migration.

The canonical design owns the exact B001 retry protocol, B002 parent ownership
tuple and B003 staged downgrade state machine. This subordinate plan repeats
their implementation inputs without creating alternatives:

- source identity uses fresh 16-byte operating-system CSPRNG output, attempt 1
  counts, and the fixed intrinsic maximum is exactly four attempts in one
  parent-locked repository transaction;
- collision classification reads only `QueryException::errorInfo` and requires
  exact string SQLSTATE `23000`, exact PHP integer driver code `1062`, and the
  anchored MySQL 8.4 diagnostic `Duplicate entry '<opaque-nonempty>' for key
  'supplier_import_source_profiles.uq_import_source_profile_identity'`; this
  single-quoted table-qualified key is the sole accepted token; string or float
  `1062`, scientific notation, signs, leading zeroes, surrounding whitespace,
  Unicode digits, booleans, null, arrays and objects are rejected without trim,
  numeric coercion or cast, while bare,
  database-qualified, backtick/double-quote, wrong-table, wrong-key, malformed
  or missing diagnostics are ineligible; the opaque duplicate payload is never
  captured, stored, compared, logged or returned;
- an eligible collision consumes only the boolean classifier result and reuses
  the transaction for a fresh attempt without emitting the original exception;
  every ineligible database error rolls back and becomes exact sanitized
  `source_profile_persistence_failed` with only ordered metadata
  `code>sqlstate>driver_code>operation`, operation fixed to
  `INSERT_SUPPLIER_IMPORT_SOURCE_PROFILE`, `previous === null`, and no original
  exception, SQL, bindings, diagnostic, identity, canonical bytes, URL,
  credential, fingerprint, secret-derived material or deferred callback;
- exhaustion rolls back and throws
  `source_profile_identity_collision_exhausted` with `previous === null` and no
  retained fourth exception; the fixed four-attempt safety
  constant is not environment/configuration, queue tries, fixtures or one of
  all ten still-`NOT SPECIFIED` operational-policy bounds;
- `uq_supplier_feed_id_supplier(id, supplier_id)` is added to
  `supplier_feeds` by a new forward migration before profile creation; profile
  ownership uses only
  `fk_import_source_profile_feed_owner(supplier_feed_id, supplier_id)` with
  supporting `ix_import_source_profile_feed_owner_fk`, both actions `RESTRICT`;
  two independent parent FKs and editing the historical feed migration are
  forbidden;
- destructive local/testing downgrade is coordinated only by
  `database/migrations/support/CanonicalSupplierPhaseThreeP0Schema.php`, uses
  normalized complete P0-P9 signatures over hardcoded baseline and cumulative
  ownership, invocation-local one-terminal-step authorization, complete pre-DDL
  validation and zero DDL on every malformed, same-name-wrong, non-terminal,
  unknown or non-pristine state. Its only downgrade API is
  `runTerminalDowngradeStep(P0MigrationStep $step): void`; it accepts no DDL
  callback and owns each exact DDL sequence. Every P0 `up()`/`down()` uses
  zero-wait MySQL named lock `mycomputer:phase-iii-p0-schema-ddl-v1` on one
  dedicated connection, rejects nested/concurrent entry, and releases in
  `finally`.

The exact nine-step forward migration registry is byte-equal to the canonical
design registry:

<!-- phase-iii-p0-migration-registry classification=CURRENT id=phase-iii-p0-migration-order-v1 -->
| Ordinal/state | Exact forward migration | Responsibility and owned artifacts | Dependencies | Independent rollback | Exact predecessor/successor |
| :---: | :--- | --- | --- | :---: | :---: |
| P0-01 / P1 | `*_add_supplier_ownership_key_to_supplier_feeds.php` | add only UNIQUE `uq_supplier_feed_id_supplier(id, supplier_id)` to existing `supplier_feeds`; historical migration unchanged | deployed Phase I/II baseline; existing unsigned-bigint `id` and `supplier_id` | NO; terminal P1 -> P0 only | P0 / P0-02 |
| P0-02 / P2 | `*_create_supplier_import_source_profiles_table.php` | create `supplier_import_source_profiles` with the exact canonical columns; PRIMARY `id`; `uq_import_source_profile_descriptor`; `uq_import_source_profile_identity`; `uq_import_source_profile_scope`; `ix_import_source_profile_feed_owner_fk`; `fk_import_source_profile_feed_owner`; `trg_import_source_profile_no_update`; `trg_import_source_profile_no_delete` | P0-01 parent key and Phase II canonical serializers | NO; terminal empty P2 -> P1 only | P0-01 / P0-03 |
| P0-03 / P3 | `*_create_supplier_import_source_executions_table.php` | create `supplier_import_source_executions` with the complete canonical execution columns, unique keys, composite ownership/profile keys, checks and no-update/no-delete triggers | P0-02; locked ImportJob/feed/template and ImportHistory parents | NO; terminal empty P3 -> P2 only | P0-02 / P0-04 |
| P0-04 / P4 | `*_create_supplier_import_source_payload_receipts_table.php` | create `supplier_import_source_payload_receipts` with the complete canonical one-receipt columns, execution-scope keys, checks and no-update/no-delete triggers | P0-03 execution receipt scope | NO; terminal empty P4 -> P3 only | P0-03 / P0-05 |
| P0-05 / P5 | `*_create_supplier_product_identity_heads_table.php` | create `supplier_product_identity_heads` with the canonical columns, byte-exact logical key, scope keys, ownership FKs and no-update/no-delete triggers | P0-01 supplier/feed ownership authority | NO; terminal empty P5 -> P4 only | P0-04 / P0-06 |
| P0-06 / P6 | `*_create_supplier_product_source_revisions_table.php` | create `supplier_product_source_revisions` with the complete canonical revision columns, execution/head composite FKs, temporary exact staging-ID FK, unique keys, checks and no-update/no-delete triggers; P0-07 replaces only that staging-ID FK with composite ownership | P0-03, P0-04 and P0-05 | NO; terminal empty P6 -> P5 only | P0-05 / P0-07 |
| P0-07 / P7 | `*_add_source_revision_pointers_to_supplier_products_table.php` | add only canonical identity-head/current-revision columns, composite unique/FK/index/check authority and guarded source-projection/pointer triggers to existing `supplier_products` | P0-05 and P0-06; legacy rows remain pair-null | NO; terminal all-null P7 -> P6 only | P0-06 / P0-08 |
| P0-08 / P8 | `*_add_source_authority_to_snapshot_claims_and_generations.php` | add only claim `cohort_source_identity`, exact five-field claim source binding, generation-to-claim composite source authority, indexes, checks, FKs and immutable transition triggers | P0-02 and deployed claim/generation tables | NO; terminal no-evidence P8 -> P7 only | P0-07 / P0-09 |
| P0-09 / P9 | `*_add_policy_v2_authority_to_snapshot_claims_and_generations.php` | add only canonical policy-v2 key/version/fingerprint authority, composite generation binding, indexes, checks, FKs and immutable transition triggers | P0-08 and the ten-bound policy-v2 contract; numeric values remain NOT SPECIFIED | NO; terminal no-evidence P9 -> P8 only | P0-08 / FULL P0 |
<!-- phase-iii-p0-migration-registry:end id=phase-iii-p0-migration-order-v1 -->

The structural artifact inventory below is exhaustive for every P0-owned
surface. Column order inside each `>` list is canonical. An index, key,
constraint or trigger not listed for its ordinal is unknown P0 structure and
makes prefix detection fail closed. Existing constraints named as `replace`
are dropped and recreated under the same name in the owning migration; they
are not additional authority. P0-06 initially binds a revision to
`supplier_products.id`; P0-07 adds the head columns and atomically replaces
that simple binding with the exact composite revision/product ownership FK
before protected runtime use is permitted.

<!-- phase-iii-p0-artifact-registry classification=CURRENT id=phase-iii-p0-artifact-inventory-v1 -->
| Ordinal | Exact tables and columns owned | Exact indexes and keys owned | Exact constraints and triggers owned |
| :---: | --- | --- | --- |
| P0-01 | `supplier_feeds`; existing `id>supplier_id` | `uq_supplier_feed_id_supplier(id>supplier_id)` | none |
| P0-02 | `supplier_import_source_profiles`: `id>supplier_id>supplier_feed_id>source_identity>descriptor_version>source_locator_contract_key>source_locator_contract_version>source_locator_key>source_locator_canonical_bytes>source_access_scope_key>feed_type>importer_key>importer_version>mapping_contract_version>mapping_canonical_bytes>mapping_contract_fingerprint>source_descriptor_fingerprint>created_at` | `PRIMARY(id)`; `uq_import_source_profile_descriptor(supplier_id>supplier_feed_id>source_descriptor_fingerprint)`; `uq_import_source_profile_identity(source_identity)`; `uq_import_source_profile_scope(id>supplier_id>supplier_feed_id>source_identity>source_descriptor_fingerprint)`; `ix_import_source_profile_feed_owner_fk(supplier_feed_id>supplier_id)` | `fk_import_source_profile_feed_owner(supplier_feed_id>supplier_id)`; `chk_import_source_profile_descriptor_version`; `chk_import_source_profile_source_identity`; `chk_import_source_profile_locator_key`; `chk_import_source_profile_access_scope`; `chk_import_source_profile_feed_type`; `chk_import_source_profile_fingerprints`; `trg_import_source_profile_no_update`; `trg_import_source_profile_no_delete` |
| P0-03 | `supplier_import_source_executions`: `id>supplier_id>supplier_feed_id>import_job_id>import_history_id>supplier_import_source_profile_id>source_identity>source_descriptor_fingerprint>importer_key>importer_version>import_job_identity_version>import_job_identity_canonical_bytes>import_job_identity_fingerprint>resolved_source_context_version>captured_at>source_execution_fingerprint>created_at` | `PRIMARY(id)`; `uq_import_source_execution_fingerprint(source_execution_fingerprint)`; `uq_import_source_execution_history(import_history_id)`; `uq_import_source_execution_scope(id>supplier_id>supplier_feed_id>source_identity>source_descriptor_fingerprint>source_execution_fingerprint)`; `uq_import_source_execution_receipt_scope(id>source_execution_fingerprint)`; `ix_import_source_execution_profile_fk(supplier_import_source_profile_id>supplier_id>supplier_feed_id>source_identity>source_descriptor_fingerprint)`; `ix_import_source_execution_feed_owner_fk(supplier_feed_id>supplier_id)`; `ix_import_source_execution_job_scope_fk(import_job_id>supplier_id>supplier_feed_id)` | `fk_import_source_execution_profile`; `fk_import_source_execution_feed_owner`; `fk_import_source_execution_job_scope`; `fk_import_source_execution_history`; `chk_import_source_execution_versions`; `chk_import_source_execution_fingerprints`; `trg_import_source_execution_no_update`; `trg_import_source_execution_no_delete` |
| P0-04 | `supplier_import_source_payload_receipts`: `id>supplier_import_source_execution_id>source_execution_fingerprint>receipt_version>accepted_payload_bytes>accepted_payload_sha256>payload_receipt_fingerprint>created_at` | `PRIMARY(id)`; `uq_import_source_payload_receipt_execution(supplier_import_source_execution_id)`; `uq_import_source_payload_receipt_fingerprint(payload_receipt_fingerprint)`; `ix_import_source_payload_receipt_execution_fk(supplier_import_source_execution_id>source_execution_fingerprint)` | `fk_import_source_payload_receipt_execution`; `chk_import_source_payload_receipt_version`; `chk_import_source_payload_receipt_bytes`; `chk_import_source_payload_receipt_fingerprints`; `trg_import_source_payload_receipt_no_update`; `trg_import_source_payload_receipt_no_delete` |
| P0-05 | `supplier_product_identity_heads`: `id>supplier_id>supplier_feed_id>supplier_sku_bytes>created_at` | `PRIMARY(id)`; `uq_supplier_product_identity_head(supplier_id>supplier_feed_id>supplier_sku_bytes)`; `uq_supplier_product_identity_head_scope(id>supplier_id>supplier_feed_id)`; `uq_supplier_product_identity_head_revision_scope(id>supplier_id>supplier_feed_id>supplier_sku_bytes)`; `ix_supplier_product_identity_head_feed_owner_fk(supplier_feed_id>supplier_id)` | `fk_supplier_product_identity_head_feed_owner`; `chk_supplier_product_identity_head_sku_bytes`; `trg_supplier_product_identity_head_no_update`; `trg_supplier_product_identity_head_no_delete` |
| P0-06 | `supplier_product_source_revisions`: `id>supplier_product_identity_head_id>supplier_product_id>supplier_import_source_execution_id>supplier_id>supplier_feed_id>supplier_sku_bytes>source_identity>source_descriptor_fingerprint>source_execution_fingerprint>source_member_identity_hash>source_row_fingerprint>staging_projection_fingerprint>revision_fingerprint>observed_at>created_at` | `PRIMARY(id)`; `uq_supplier_product_revision_execution_head(supplier_import_source_execution_id>supplier_product_identity_head_id)`; `uq_supplier_product_revision_pointer_scope(id>supplier_product_id>supplier_product_identity_head_id>supplier_id>supplier_feed_id)`; `ix_supplier_product_revision_head_fk(supplier_product_identity_head_id>supplier_id>supplier_feed_id>supplier_sku_bytes)`; `ix_supplier_product_revision_execution_fk(supplier_import_source_execution_id>supplier_id>supplier_feed_id>source_identity>source_descriptor_fingerprint>source_execution_fingerprint)`; `ix_supplier_product_revision_product_id_fk(supplier_product_id)` | `fk_supplier_product_revision_head`; `fk_supplier_product_revision_execution`; `fk_supplier_product_revision_product_id`; `chk_supplier_product_revision_source_identity`; `chk_supplier_product_revision_fingerprints`; `trg_supplier_product_revision_no_update`; `trg_supplier_product_revision_no_delete` |
| P0-07 | `supplier_products`: `supplier_product_identity_head_id>current_source_revision_id`; no new revision columns | `uq_supplier_product_identity_head_assignment(supplier_product_identity_head_id)`; `uq_supplier_product_source_revision_scope(id>supplier_product_identity_head_id>supplier_id>supplier_feed_id)`; `ix_supplier_product_identity_head_fk(supplier_product_identity_head_id>supplier_id>supplier_feed_id)`; `ix_supplier_product_current_revision_fk(current_source_revision_id>id>supplier_product_identity_head_id>supplier_id>supplier_feed_id)`; `ix_supplier_product_revision_product_scope_fk(supplier_product_id>supplier_product_identity_head_id>supplier_id>supplier_feed_id)` on `supplier_product_source_revisions` | `fk_supplier_product_identity_head`; `fk_supplier_product_current_revision`; replace `fk_supplier_product_revision_product_id` with `fk_supplier_product_revision_product_scope`; `chk_supplier_product_source_revision_pointers`; `trg_supplier_product_source_projection_guard` |
| P0-08 | `supplier_import_execution_claims`: `cohort_source_identity`; no new generation column | `uq_import_execution_claim_id_cohort_source(id>cohort_source_identity)`; `ix_snapshot_generation_claim_source(supplier_import_execution_claim_id>source_identity)` | replace `chk_import_claim_cohort_authorization_tuple`; replace `chk_import_claim_processing_owner`; `chk_import_execution_claim_cohort_source_identity`; `trg_import_execution_claim_cohort_source_immutable`; `fk_snapshot_generation_claim_source` |
| P0-09 | `supplier_import_execution_claims`: `cohort_bounds_policy_key>cohort_bounds_policy_version>cohort_bounds_policy_fingerprint`; `supplier_offer_snapshot_generations`: `bounds_policy_key>bounds_policy_version>bounds_policy_fingerprint` | `uq_import_claim_id_bounds_policy(id>cohort_bounds_policy_key>cohort_bounds_policy_version>cohort_bounds_policy_fingerprint)`; `ix_snapshot_generation_bounds_policy(supplier_import_execution_claim_id>bounds_policy_key>bounds_policy_version>bounds_policy_fingerprint)` | `chk_import_claim_cohort_policy_authority`; `chk_import_claim_cohort_policy_identity`; replace `chk_import_claim_processing_owner`; `trg_import_execution_claim_cohort_policy_immutable`; `chk_snapshot_generation_bounds_policy_identity`; `fk_snapshot_generation_bounds_policy` |
<!-- phase-iii-p0-artifact-registry:end id=phase-iii-p0-artifact-inventory-v1 -->

The implementation pairs every artifact name above bijectively with one
hardcoded `P0SchemaObjectSignature`; missing, surplus and duplicate ownership
fail tests. Signature bytes use domain
`phase-iii-p0-schema-signature-v1`, NUL and fixed-order UTF-8 JSON with LF, JSON
null, `true`/`false`, canonical base-10 integers and ordered arrays. Expected
bytes and SHA-256 values are independent constants derived from canonical DDL,
never from inspected state.

Treat object/state/operation metadata as closed-world authority too.
`registry_id` is exactly `object_id@first_16_lowercase_hex(signature_sha256)`,
matches `\A[A-Za-z0-9_:]+@[0-9a-f]{16}\z`, is at most 128 ASCII bytes and is
globally unique. Exact owner is `BASELINE` for a `P0_BASELINE` signature and
otherwise the first normal P1..P9 prefix that introduces that exact
`object_id@sha256`. State classification/domain pairs are exactly the canonical
baseline, normal-prefix and recognized-partial map. Every operation uses only
`TRANSCRIBE_EXACT_SQL_AND_REACH_ONLY_DECLARED_RESULT`; its ordered
`affected_objects` must equal the lexicographically sorted full-hash delta
between the bound precondition and result state maps, including JSON null on an
absent side. No count-only or one-way validation is sufficient.

Reproduce the separate canonical metadata JSON in exact registry order with
ordered top-level keys `version>objects>states>operations` and version
`phase-iii-p0-oracle-metadata-v1`. Its exact length is `168474` bytes and the
hardcoded SHA-256 over domain `phase-iii-p0-oracle-metadata-v1`, NUL and those
bytes is
`72e09a922c429ae861dac607d94a376e91b2f40c3ced2ba40178bef794ab0a6a`.
This metadata constant is independent of candidate DDL/database state and does
not alter any schema signature, state or downgrade-SQL hash.

The exact signature sources and field orders are: table
`type>table>table_type>engine>row_format>table_collation>create_options>table_comment`
from `information_schema.TABLES`; column
`type>table>ordinal>name>column_type>nullable>default_kind>default_value>extra>charset>collation>generation_expression>comment`
from `COLUMNS`; index `type>table>name>non_unique>index_type>parts`, with parts
`sequence>column>expression>sub_part>collation`, from `STATISTICS`; FK
`type>name>child_table>child_columns>parent_table>parent_columns>update_rule>delete_rule`
from `TABLE_CONSTRAINTS`/`KEY_COLUMN_USAGE`/`REFERENTIAL_CONSTRAINTS`; CHECK
`type>name>table>clause>enforced` from
`TABLE_CONSTRAINTS`/`CHECK_CONSTRAINTS`; and trigger
`type>name>table>timing>event>action_orientation>action_statement>sql_mode>character_set_client>collation_connection>database_collation`
from `TRIGGERS`. SQL expressions receive only CRLF/CR-to-LF and edge ASCII
whitespace normalization; case, quotes, parentheses and internal whitespace are
exact. `DEFINER` is ignored as deployment-account metadata, while trigger SQL
mode and character-set/collation context are authoritative.

Each P0-created table has exact table comment
`mycomputer:phase-iii-p0:v1:owner=P0-XX`; discovery uses this structural marker,
not table-name text search, and rejects unknown owner/name pairs. Complete object
sets reject same-name malformed or extra columns/indexes/FKs/CHECKs/triggers.
The pre-P0 baseline for `supplier_feeds`, `supplier_products`,
`supplier_import_execution_claims` and
`supplier_offer_snapshot_generations` is independently hardcoded from reviewed
commit `31de89c02f03b10a2aafd3ba58aad083c2f3e780`; candidate state cannot define or
refresh it. P0 through P9 are exact baseline plus cumulative signatures, so a
missing baseline object, unknown shared-table addition or later-prefix artifact
rejects before DDL. Normative schema inventory and signature registry are tested
in both directions.

The implementation transcribes, rather than calculates, the concrete object
records in the canonical design. The exact normal-prefix authority is:

| State | Object count | Exact SHA-256 |
| :---: | ---: | --- |
| P0 | 207 | `77512f147ef9a2fe3889156ac617701c3de7fc7532bb4914329d0735bdfaed79` |
| P1 | 208 | `8ed9f3d479812a2807abcc23411bad1fd60cd495566a94416352f0c4640bf447` |
| P2 | 241 | `45bf4bc963becb79949d88df27dd6223bccf1fcd076beb310f4a2842ba260bc0` |
| P3 | 275 | `9a424ea99f9ddbb68fd420702578fde371e162ff314740c9f856aab5e3926990` |
| P4 | 294 | `336d0b4076cee86f8f3efc8d43a29ba020716629e6088f8ec2ec763d061af8f6` |
| P5 | 309 | `d8ca08020090614ebb31d18d7640a1d46f5757eeacc226a1a3b3367584bda058` |
| P6 | 339 | `3d201119551344241d222fcd3d9db9d136ace4e321cc1ae36dfc86f410fdb7a9` |
| P7 | 349 | `9bea840553b2fd240d0b5bca8206a35e7cb1c3b2a0420a4600c081ca0e0df43d` |
| P8 | 355 | `c2b6d719637a86dccf115e9220b9e2439a986e1da6c23ee51ddda7c5361cc322` |
| P9 | 368 | `4269ff10ce6e8a407b2c790d7260e3f9c6db8f48fd06d456d15ae3e5ca4b579d` |

The only recognized partial hashes are
`P9_DOWN_1=bf96860e7603e774e881f2549d2dfcb9527990a8fe01d88fc417719eb3a37cc0`,
`P9_DOWN_2=1a7b8f70593db3622c7e9477455f7be9f5eba0a8faf42d58a7b98751379f7758`,
`P8_DOWN_1=0fc58b8ab89c138d2ab165b1e4a24e812dc582dab0cafff9526b86c2a24c1fec`,
`P8_DOWN_2=eac8d521280d0debb3ac440247b164dea751ce640c1dc7ea320afeee56197c95`,
`P7_DOWN_1=a8c237b9dd49577e8d6ed7ce5e4d0a46b1334d5cbd51931334b3576fbc13e6cd`
and
`P7_DOWN_2=953f96d7c9e087fd4e2ed29c402b1439d77da390e2a5f5911a70872a87ce380e`.
Every other shape is `UNCLASSIFIED_P0_SCHEMA_STATE` and executes zero DDL.

The coordinator acquires exact `GET_LOCK(..., 0)` on the same dedicated PDO
connection before inspection. A non-`1` result fails
`phase_three_p0_schema_guard_unavailable`; nested entry fails
`phase_three_p0_nested_downgrade_forbidden`; release runs in `finally`. Its exact
sequence is guard, enumerate, normalize, exact-prefix compare, terminal-step
check, pristine/data/dependency checks, invocation-local authorization,
coordinator-owned DDL, re-enumerate after every physical statement,
exact-declared-state compare, exact-predecessor compare and release.
Pre-DDL failure proves DDL count zero. Post-DDL mismatch is terminal
`phase_three_p0_downgrade_postcondition_failed` and never falsely claims MySQL
DDL rollback. Required vectors cover malformed same-name column/index/FK/CHECK/
trigger, unknown created/shared-table object, unknown reserved Phase III table,
missing baseline, source-profile rows, P0-03 present, nested invocation and
concurrent guard failure. Exact reversible Slice 1 proof is
`P0 -> P1 -> P2 -> P1 -> P0`.

The physical statement inventory is fixed at 15: one atomic statement each for
P0-01 through P0-06 and three each for P0-07, P0-08 and P0-09. Exact SQL and
affected object hashes are copied from the canonical operation registry; callers
cannot supply SQL. Only the six exact partial states above may resume. A fresh
ordinary `down()` for the same originating enum reacquires the mutex, fully
re-enumerates, obtains a new invocation-local authorization and executes only
the remaining operation IDs. Wrong-step resume, repeated committed DDL,
best-effort cleanup and arbitrary operator SQL are forbidden.

Connection loss invalidates authorization. An in-flight DDL whose server result
is unknown is never retried; a new session must reacquire the named lock and
classify the full schema. Session termination releases its MySQL named lock but
does not prove success or transfer authorization. Release result 1 is
`RELEASED`; 0 is secondary
`phase_three_p0_schema_guard_release_not_owned`; NULL/error/lost connection is
secondary `phase_three_p0_schema_guard_release_unavailable`. Schema/DDL outcome
has precedence over release outcome. Verified predecessor plus release anomaly
remains primary success with secondary release evidence; any unverified schema
can never report success.

Every invocation emits exactly one authoritative synchronous structured event
`phase_three_p0_downgrade_evidence_v1` with ordered safe fields
`event_version>invocation_id>step>initial_state>target_state>completed_ddl_ids>last_verified_state>partial_state>connection_status>mutex_acquire>mutex_release>primary_outcome>secondary_codes`.
SQL, bindings, row values, source identity, credentials and exception text are
forbidden. Domain exceptions expose only primary code and invocation ID. P0-02
down is one atomic `DROP TABLE`, has no partial state and reaches P1; P0-01 then
uses one atomic index drop to reach P0.

The exact cumulative prefix registry is likewise byte-equal to the canonical
design. A valid state contains all and only the cumulative artifacts in its row.

<!-- phase-iii-p0-prefix-registry classification=CURRENT id=phase-iii-p0-prefix-states-v1 -->
| State | Exact cumulative P0-owned artifact groups | Terminal downgrade prerequisite and result |
| :---: | --- | --- |
| P0 | no Phase III-P0-owned artifact exists | baseline; no P0 downgrade exists |
| P1 | P0-01 parent UNIQUE key exists exactly | profile table and every later group absent; remove parent key -> P0 |
| P2 | P1 plus complete P0-02 source-profile table, keys, FK, index and triggers | profile table zero rows and P3-P9 absent; drop profile artifacts -> P1 |
| P3 | P2 plus complete P0-03 source-execution table authority | execution table zero rows, no receipt/revision child and P4-P9 absent; drop execution artifacts -> P2 |
| P4 | P3 plus complete P0-04 payload-receipt table authority | receipt table zero rows and P5-P9 absent; drop receipt artifacts -> P3 |
| P5 | P4 plus complete P0-05 logical-head table authority | head table zero rows, no revision/pointer child and P6-P9 absent; drop head artifacts -> P4 |
| P6 | P5 plus complete P0-06 source-revision table authority | revision table zero rows, no current pointer and P7-P9 absent; drop revision artifacts -> P5 |
| P7 | P6 plus complete P0-07 `supplier_products` pointer authority | every new pointer null and P8-P9 absent; remove pointer triggers/FKs/checks/indexes/columns -> P6 |
| P8 | P7 plus complete P0-08 claim/generation source authority | every new source field null, no source-bound generation evidence and P9 absent; remove source authority -> P7 |
| P9 | P8 plus complete P0-09 policy-v2 authority | every new policy field null and no policy-v2-bound generation evidence; remove policy authority -> P8 |
<!-- phase-iii-p0-prefix-registry:end id=phase-iii-p0-prefix-states-v1 -->

Forward order is P0-01 through P0-09. Reverse local/testing order is P0-09
through P0-01, one newly validated terminal prefix step per invocation. The
complete profile model/contract work follows P0-01 and P0-02; locked selector
identity, resolved context and receipt-bound downloader/parser adapters follow
P0-03 and P0-04; Phase III snapshot persistence integration follows full P9 and
separate approval of all ten policy values.

The revised minimal Slice 1 is exactly P0-01 plus P0-02, the
`SupplierImportSourceProfile` model, profile/locator/mapping serializers and
immutable value objects, source-profile repository with injectable identity
generator, shared downgrade coordinator, unit tests, and mandatory MySQL 8.4
composite-FK/concurrency/retry/append-only/P0-P2 rollback tests. It remains
dormant and performs no source fetch, importer activation, staging/Product
mutation or Catalog Sync.

## Historical deployed Phase I ten-table migration dependency plan

The historical deployed Phase I canonical table count remains exactly ten.
Existing-table indexes and triggers are not additional Phase I tables. The
future Phase III-P0 provenance tables and additive staging/source/policy
columns above are separate later prerequisites and do not rewrite this history.

### Forward order

0. Add `uq_import_job_id_supplier_feed(id, supplier_id, supplier_feed_id)`.
1. Create `supplier_import_execution_claims` with all five `RESTRICT` parent
   FKs and the exact path/allocation/owner/state checks.
2. Add `trg_import_execution_claim_path_immutable`.
3. Create `supplier_import_dispatch_outbox` with both exact claim FKs and all
   publication, lease, watchdog and state checks.
4. Create `supplier_import_dispatch_monitor_health`, insert exactly the pristine
   `id=1`/`unknown` generation-zero row.
5. Create `supplier_import_dispatch_alert_intents`, then add
   `fk_import_dispatch_alert_outbox` to the outbox.
6. Create `supplier_import_dispatch_recovery_authorizations`, then its immutable
   UPDATE/DELETE guards.
7. Create `supplier_import_dispatch_recovery_results`, then its immutable
   UPDATE/DELETE guards and generated one-start/one-terminal columns.
8. Create `supplier_import_cohort_authorization_members` and append-only guards.
9. Create `supplier_offer_snapshot_generations` with claim/history uniqueness
   and the self-predecessor `RESTRICT` FK.
10. Create `supplier_offer_snapshot_enrollments` with supplier/feed/history
    `RESTRICT` FKs.
11. Create `supplier_offer_snapshot_observations` with generation/enrollment
    `RESTRICT` FKs.
12. Add `ix_import_history_supplier_id(supplier_id, id)`.

This sequence integrates the canonical index-contract order with the
monitor-specific requirement that the singleton precede alert intents and the
alert FK follows the outbox. Historical migration files are never edited.

### Required exact keys

- Claims: `uq_import_execution_claim_logical_key`,
  `uq_import_execution_claim_id_key`, supplier/feed indexes,
  nullable `uq_import_execution_claim_run`, retained
  `uq_import_execution_claim_job`,
  `ix_import_execution_claim_job_owner_fk`,
  `uq_import_execution_claim_history`, and scope/state index.
- Cohort members: `uq_import_cohort_auth_claim_offer`.
- Outbox: claim/event, claim/key and id/claim unique keys plus due, lease and
  watchdog indexes.
- Recovery authorization: nonce unique, complete tuple unique, and exact
  claim/outbox/operator FK-support indexes.
- Recovery result: authorization/sequence, generated started/terminal unique
  guards, complete authorization tuple and audit indexes.
- Monitor: singleton primary key and unique monitor/observer identities.
- Alert intent: unique alert identity, outbox history, due and lease indexes.
- Generation: unique claim and history, feed/scope/qualified/retention and
  predecessor indexes.
- Enrollment: unique scope/offer plus feed/history/effective indexes.
- Observation: unique generation/enrollment and generation/offer plus bounded
  enrollment/offer history indexes.

Every FK uses its explicitly named left-prefix child index. Implicit
MySQL-created indexes fail migration acceptance.

### Reverse/down rule

Operational rollback never runs `down()`. It disables forward gates and
preserves schema/evidence.

Destructive down is allowed only in `local` or `testing`, with the one-run
process confirmation, all runtime/schedule gates disabled, all ten tables and
guard-visible columns readable, nine tables empty, and the monitor table equal
to the exact pristine singleton. The complete predicate is evaluated and
latched in process before the first DDL. Any false, unknown, partial or
unreadable result stops before DDL. A passing local/test invocation drops in
exact dependency-safe reverse order, with each guard immediately before its
table, alert FK before alert table, and the path trigger before claims. Foreign
key checks are never disabled.

## MySQL validation strategy

Current GitHub backend CI already supplies MySQL 8.4, so no MySQL-service
prerequisite is missing. Future implementation tests must continue to run on
that real service and must not silently switch these cases to SQLite.

MySQL-only proof includes:

- exact `SHOW CREATE TABLE` and `information_schema.statistics` inspection;
- named composite FK compatibility and leftmost child index order;
- nullable unique behavior for legacy null run parents;
- `RESTRICT` parent deletion and deletion races;
- binary ASCII enum/path checks, `REGEXP_LIKE`, generated columns and immutable
  triggers;
- microsecond `TIMESTAMP(6)` and one-statement `UTC_TIMESTAMP(6)` lease CAS;
- JSON column/state checks while application code separately proves canonical
  serialized bytes;
- concurrent owner acquisition, finalization, transport failure and recovery
  races using separate DB connections/processes;
- deadlock, timeout, zero-row and rollback behavior;
- transaction isolation assumptions under the repository's configured MySQL
  isolation, with explicit lock ordering `outbox -> claim -> parents`;
- empty and populated migration up/down guards;
- representative `EXPLAIN` assertions for every bounded selection, especially
  the watchdog and ImportHistory range indexes.

SQLite may cover pure model/value-object behavior only. A MySQL-only skip is
not acceptable as final evidence for schema, CAS, FK, trigger, generated-column
or concurrency acceptance.

## Redis fencing and validation strategy

Use a versioned Redis 7 Function library on the dedicated restricted
connection. Startup validation proves the exact library/version and all three
operations. The operation itself, not a preceding application check, is the
external-effect authority.

Required real-Redis tests:

1. **One use:** same generation/token/payload twice produces queue effect count
   exactly one.
2. **Stale generation:** A=N, B advances to N+1, A returns
   `stale_generation`; A effect count is zero.
3. **Suspended worker:** pause A after DB call-boundary CAS, let B retire/advance,
   resume A, and inspect the Redis effect oracle rather than only DB rows.
4. **Crash before advance:** committed reservation exists but no Redis
   authority; no publication and no invented fence.
5. **Crash after advance:** exact `authorized_unused` state reconciles forward;
   Redis never rolls backward.
6. **Lost publish response:** consumed receipt is reconciled without a second
   call for the same ordinal.
7. **Retirement:** owner loss creates/reconciles a monotonic tombstone before DB
   unknown classification.
8. **Bootstrap:** absent state accepts only exact generation 1 with matching
   token/payload within the approved bootstrap path.
9. **Conflict:** missing/conflicting/non-current state fails closed; no arbitrary
   reconstruction or generation jump.
10. **Isolation/timing:** only the dedicated worker/queue/connection is used and
    `3600 < 3900 < 4200 < 4320` remains true.

The future CI change belongs to Phase V and adds real Redis coverage. It must
not change unrelated queue behavior.

## Recovery strategy

### Authorization

The authenticated Filament detail action is the only issuer. It derives the
complete target under the supplier lock and canonical row locks, requires an
active Super Admin and current derived monitor health, and writes one immutable
15-minute authorization. CLI execution requires authorization ID plus a raw
nonce from protected stdin. There is no `--operator-id`, nonce argument,
environment nonce, or browser-supplied fingerprint.

### Action isolation

- `republish_same_key` is the only external-effect action. It may record only
  republish success, publication failure, or action stopped. It never
  terminalizes domain rows.
- `recover_expired_queued_ownership` performs the complete expired-owner CAS
  and writes `ownership_recovery_succeeded` atomically. It never publishes.
- `terminalize_stale_dispatch` owns only the exact exhausted pre-processing
  tuple/reason.
- `terminalize_publication_mismatch` owns only one explicitly identified
  mismatch tuple.
- `terminalize_abandoned_processing` owns only one exact expired
  `processing/published` tuple and never replays the importer.

No monitor, observer, `failed()` callback, duplicate delivery, timeout or
elapsed time may silently select or change a recovery action.

## Monitor, observer and alert-provider strategy

The monitor performs bounded indexed reads of domain state and writes only the
singleton monitor row and durable alert intents. The observer independently
binds the latest successful monitor generation/cycle and configured
`sink_contract_key`. Neither may update the other's freshness fields.

The provider capability interface must expose at least:

- stable non-secret `sink_contract_key` including adapter, capability mode and
  contract version;
- one declared mode: `native_generation_fence` or
  `provider_enforced_idempotency`;
- bounded health acknowledgement;
- delivery using immutable `alert_identity` and privacy-safe payload;
- authoritative receipt/reconciliation semantics;
- attempt-eight unknown/retirement semantics.

Provider selected: **NO**. No credentials or adapter become active in this
implementation plan. An unsupported/unverified adapter returns not ready before
lease acquisition and keeps monitor admission closed. A future provider PR must
include an external fake/gateway/provider receipt counter proving the stale
worker oracle; local DB assertions are insufficient.

## Feature-gate strategy

Exact public configuration names will be reviewed in the phase that introduces
`config/supplier_snapshot_capture.php`; their semantic keys and defaults are:

| Gate/readiness | Default | Authority |
| --- | --- | --- |
| schema version/readiness | derived false until exact schema passes | startup validator |
| Redis fencing readiness | derived false until connection, ACL, Function version and timing pass | startup validator |
| provider readiness | derived false; no provider selected | capability verifier |
| monitor schedule | false | forward config |
| observer schedule | false | forward config |
| alert delivery | false | forward config plus provider readiness |
| monitor admission | derived false until fresh complete cycle/sink and observer exist | database gate |
| recovery issuance | false | forward config plus monitor admission |
| recovery execution | false | forward config plus exact authorization and monitor admission |
| protected generation admission | false | forward config plus every readiness dependency |
| snapshot capture | false | forward config plus protected admission |

Deployment never flips a gate. Operational rollback turns forward gates off
and preserves all coordination/evidence. Catalog Sync flags are separate and
never used as snapshot/import gates.

The binding lifecycle is exact: Phase VI introduces
`SupplierImportDispatchMonitorReadiness` and binds
`UnavailableSupplierImportDispatchMonitorReadiness`; Phase X supplies and may
bind `DbBackedSupplierImportDispatchMonitorReadiness`. Code/config deployment,
`MONITOR_ENABLED=true`, schedule enablement, process liveness, or any equivalent
environment/config value is never readiness evidence. Only the DB-backed
implementation may return true, and only from the canonical fresh
monitor/observer/sink and integrity predicates. Recovery gates remain
inactivatable until that evidence exists and activation is separately
authorized.

## Test traceability

The canonical source remains the approved design; executable tests will carry
criterion/case IDs in dataset names or PHPUnit attributes so coverage can be
audited without copying prose into a second authority.

### Acceptance criteria 1-64

| Criteria | Planned phase | Future test class/type |
| --- | --- | --- |
| 1-5 | V | queue configuration/isolation feature and Docker contract tests |
| 6-15 | VI | MySQL/Redis owner, failure, timing and crash integration tests |
| 16-20 | I, IV, VI | migration checks plus state-invariant feature/concurrency tests |
| 21-35 | V, VI, VIII, IX | queue timing, delivery budget, finalization and recovery race tests |
| 36-39 | III, VI | cohort authorization and immutable capture tests |
| 40-47 | V, VI, IX | publication attempt, result ambiguity and watchdog transition tests |
| 48 | X | monitor/observer scheduler, privacy and zero-domain-mutation tests |
| 49 | VII | Filament issuance, nonce, tuple and authorization tests |
| 50-55 | VII-IX | recovery result compatibility and Redis adversarial tests |
| 56-57 | II, X | alert vector, schema/CAS and provider capability tests |
| 58 | every PR | release-process evidence and unchanged-head review gate |
| 59 | II, VII | exact expected-state v2 serializer/vector tests |
| 60 | V, VI, IX | 66-row crash dataset and suspended Redis worker test |
| 61 | X | four dedicated monitor crash cases |
| 62-64 | X and future provider PR | ten alert cases, stale-provider worker oracle and unknown-exhausted proof |

### Focused cases 1-53

| Cases | Planned phase | Future test class/type |
| --- | --- | --- |
| 1-5 | VI, X | watchdog/delivery observation race and lock-contention tests |
| 6-11 | VIII, IX | same-key, budgets, expired/null/live owner recovery tests |
| 12-17 | VIII | mismatch dry-run/apply/replay/conflict and zero-mutation tests |
| 18-20 | VI, X | watchdog lifecycle, monitor cadence/freshness/privacy tests |
| 21-24 | VII-IX, X | issuance, objective boundary, resume and health-gate tests |
| 25-30 | VIII, IX | action-stop, Redis ambiguity, authorization release and expired-owner CAS tests |
| 31-36 | II, X | alert vectors and monitor generation crash/takeover tests |
| 37-49 | X and future provider PR | provider readiness, fencing/idempotency, attempts and unknown-exhausted tests |
| 50-53 | V, IX | Redis reservation/effect/advance/retire and final-attempt tests |

Additional suites retain the 19-outcome protocol matrix, 66-row crash matrix,
103-step rollout graph, migration contract, 22 identity inventory, full
supplier-import regressions, Filament authorization, secret scans, and
zero-mutation assertions.

### Planning-review remediation traceability

| Finding | Owning phases | Required future executable proof |
| --- | --- | --- |
| RPR-001 protected notification boundary | VI, X and future provider PR | Protected success/failure/duplicate/stale execution bypasses `SupplierImportNotificationService`, dispatches zero legacy `SendEmailJob` instances and preserves `last_import_notification_at`; the gate-disabled legacy path retains its current behavior; any future protected notification originates from one durable alert intent and passes only through a proven provider capability |
| RPR-002 readiness contract and unavailable default | VI | Container resolution returns `SupplierImportDispatchMonitorReadiness` backed by `UnavailableSupplierImportDispatchMonitorReadiness`; `isReadyForProtectedActivity()` is false for every protected entry point with zero effects |
| RPR-002 issuance admission | VII | Unavailable readiness rejects issuance and creates/updates zero target, authorization or result rows |
| RPR-002 DB-only mutation admission | VIII | Unavailable readiness rejects every Phase-A start before mutation and leaves claim/outbox/parents/results unchanged |
| RPR-002 republication admission/effect | IX | Unavailable readiness rejects Phase A; unavailable/stale readiness before Phase B makes zero Redis calls and zero external effects |
| RPR-002 DB-backed readiness | X | Only exact fresh healthy monitor/observer/sink evidence returns true; stale, failed, unknown, DB unavailable, lease/generation mismatch and unsupported-provider states return false |

## Catalog Sync and data-ownership safety

The implementation preserves:

```text
CATALOG_SYNC_CREATE_ENABLED=true
CATALOG_SYNC_UPDATE_ENABLED=false
CATALOG_SYNC_SYNC_ALL_ENABLED=false
CATALOG_SYNC_AUTO_ENABLED=false
```

- CREATE remains manual, selected and controlled.
- UPDATE remains disabled by default.
- Sync All remains disabled and no button/command/path is added.
- Automatic sync remains disabled and unrelated to supplier snapshot gates.
- Existing import engines continue to own only `supplier_products` staging
  writes when an import is separately authorized and executed.
- No phase adds supplier-driven Product creation/update.
- No supplier overwrite of name, slug, SEO, descriptions, images, categories,
  attributes/specifications or localized manual content is allowed.
- No supplier image import, category overwrite, attribute overwrite, retention
  cleanup, lifecycle application or automatic replacement import is allowed.

| Future phase | May mutate `products`? | May mutate `supplier_products`? | May protected execution mutate `suppliers.last_import_notification_at` through the legacy notification flow? |
| --- | --- | --- | --- |
| I-V | no | no | no |
| VI | no | only through the unchanged existing importer after separate protected-path activation; no new fields/semantics | no; legacy notification behavior exists only while the legacy/unprotected path is selected |
| VII-XI | no | no | no |

Tests use isolated synthetic databases. This planning artifact performs no data
mutation.

## Risks and blockers

1. **External queue effect fencing.** Local CAS is insufficient; Phase V is
   blocked until Redis Function behavior and queue effect counters are proven
   on real Redis.
2. **Provider absence.** No provider is selected. Alert delivery and operational
   monitor readiness remain blocked after generic coordination code exists.
3. **Current direct dispatch.** Multiple UI/CLI paths bypass durable outbox.
   Protected activation is blocked until all are routed through the common
   coordinator and regression-tested.
4. **Current force lock release.** `forceRelease()` can remove another owner.
   It must not exist in the protected path; migration to owner-checked release
   requires concurrency evidence.
5. **Importer non-idempotency.** Counters, failures, attributes and staging rows
   can change incrementally. Any replay after `processing` is prohibited.
6. **Schema complexity.** Composite FKs, generated guards, binary checks,
   triggers and the cross-migration one-run down guard require MySQL 8.4 and
   exact metadata audits.
7. **Current CI lacks Redis.** Redis acceptance cannot be green until Phase V
   adds a real service/job. MySQL is already available.
8. **Monitor/provider coupling.** Monitor success cannot be claimed with an
   unsupported adapter; an unavailable adapter must keep health unknown/failed.
9. **Activation sequencing.** Merge/deploy/readiness/monitor enablement/capture
   enablement/import/evidence candidate/preview are distinct authorizations.
10. **No backfill.** Existing mutable staging cannot be transformed into
    historical immutable evidence.
11. **Legacy notification isolation.** Protected execution must bypass the
    existing completion notification and Supplier timestamp mutation. Phase X
    durable alert intents are the only future protected notification source.

The Phase III architecture design selects exact provenance and durable claim-
source mechanisms without adding runtime artifacts. The authorized importer
still has no evidence-backed hard limits from which all required operational
bounds can be approved. Claim source binding does not repair candidate provenance;
both selected mechanisms remain mandatory. Phase III implementation is
prohibited while the bounds gate is blocked and until a later explicit
implementation authorization follows independent design review.

## Review and rollout boundary

Each phase is one independently reviewable PR. A later phase begins only after
its dependencies are merged into current `main`. No phase authorization implies
push, PR, merge, deployment, feature enablement, import, candidate preparation,
preview or closeout. The canonical 103-checkpoint governance remains the source
of truth for those operational transitions.

Phase VI must merge before VII through X because it owns the readiness
interface and unavailable binding. VII through IX may then merge behind that
binding but cannot become active. Phase X supplies the DB-backed binding; even
that merge does not activate recovery without canonical fresh evidence and a
separate operational authorization.

The first safe next action is a fresh independent read-only review of the
complete Phase III architecture-design checkpoint. The exact current status
map remains exclusively in the referenced canonical architecture contract.
The remaining numeric-evidence gate requires separately authorized production-
evidence collection and review for all ten bounds. That review cannot
authorize Phase III implementation, which remains unimplemented and
unauthorized until the remaining gate is separately closed.

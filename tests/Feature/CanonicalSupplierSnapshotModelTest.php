<?php

namespace Tests\Feature;

use App\Models\Concerns\GuardsCanonicalSupplierMassAssignment;
use App\Models\Concerns\GuardsImmutableCanonicalSupplierRecord;
use App\Models\ImportHistory;
use App\Models\ImportJob;
use App\Models\Supplier;
use App\Models\SupplierFeed;
use App\Models\SupplierImportCohortAuthorizationMember;
use App\Models\SupplierImportDispatchAlertIntent;
use App\Models\SupplierImportDispatchMonitorHealth;
use App\Models\SupplierImportDispatchOutbox;
use App\Models\SupplierImportDispatchRecoveryAuthorization;
use App\Models\SupplierImportDispatchRecoveryResult;
use App\Models\SupplierImportExecutionClaim;
use App\Models\SupplierImportRun;
use App\Models\SupplierOfferSnapshotEnrollment;
use App\Models\SupplierOfferSnapshotGeneration;
use App\Models\SupplierOfferSnapshotObservation;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use ReflectionClass;
use Tests\TestCase;

final class CanonicalSupplierSnapshotModelTest extends TestCase
{
    use RefreshDatabase;

    private const MODEL_TABLES = [
        SupplierImportExecutionClaim::class => 'supplier_import_execution_claims',
        SupplierImportDispatchOutbox::class => 'supplier_import_dispatch_outbox',
        SupplierImportDispatchMonitorHealth::class => 'supplier_import_dispatch_monitor_health',
        SupplierImportDispatchAlertIntent::class => 'supplier_import_dispatch_alert_intents',
        SupplierImportDispatchRecoveryAuthorization::class => 'supplier_import_dispatch_recovery_authorizations',
        SupplierImportDispatchRecoveryResult::class => 'supplier_import_dispatch_recovery_results',
        SupplierImportCohortAuthorizationMember::class => 'supplier_import_cohort_authorization_members',
        SupplierOfferSnapshotGeneration::class => 'supplier_offer_snapshot_generations',
        SupplierOfferSnapshotEnrollment::class => 'supplier_offer_snapshot_enrollments',
        SupplierOfferSnapshotObservation::class => 'supplier_offer_snapshot_observations',
    ];

    private const APPEND_ONLY_MODELS = [
        SupplierImportDispatchRecoveryAuthorization::class,
        SupplierImportDispatchRecoveryResult::class,
        SupplierImportCohortAuthorizationMember::class,
        SupplierOfferSnapshotGeneration::class,
        SupplierOfferSnapshotEnrollment::class,
        SupplierOfferSnapshotObservation::class,
    ];

    private const MASS_ASSIGNMENT_ATTRIBUTES = [
        SupplierImportExecutionClaim::class => ['logical_execution_key', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'],
        SupplierImportDispatchOutbox::class => ['dispatch_payload_hash', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'],
        SupplierImportDispatchMonitorHealth::class => ['monitor_identity', 'mass-assignment-probe'],
        SupplierImportDispatchAlertIntent::class => ['alert_identity', 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc'],
        SupplierImportDispatchRecoveryAuthorization::class => ['authorization_nonce_hash', 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd'],
        SupplierImportDispatchRecoveryResult::class => ['result_fingerprint', 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee'],
        SupplierImportCohortAuthorizationMember::class => ['supplier_sku_hash', 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff'],
        SupplierOfferSnapshotGeneration::class => ['generation_fingerprint', '1111111111111111111111111111111111111111111111111111111111111111'],
        SupplierOfferSnapshotEnrollment::class => ['enrollment_fingerprint', '2222222222222222222222222222222222222222222222222222222222222222'],
        SupplierOfferSnapshotObservation::class => ['observation_fingerprint', '3333333333333333333333333333333333333333333333333333333333333333'],
    ];

    public function test_all_ten_canonical_models_have_exact_table_key_timestamp_and_guarded_contracts(): void
    {
        $this->assertCount(10, self::MODEL_TABLES);

        foreach (self::MODEL_TABLES as $class => $table) {
            /** @var Model $model */
            $model = new $class;

            $this->assertSame($table, $model->getTable(), $class);
            $this->assertSame('id', $model->getKeyName(), $class);
            $this->assertSame('int', $model->getKeyType(), $class);
            $this->assertSame([], $model->getFillable(), $class);
            $this->assertSame(['*'], $model->getGuarded(), $class);
            $this->assertContains(GuardsCanonicalSupplierMassAssignment::class, class_uses_recursive($class));
            $this->assertSame([], $model->getGlobalScopes(), $class);

            $reflection = new ReflectionClass($class);
            $this->assertNotSame(
                $class,
                $reflection->getProperty('dispatchesEvents')->getDeclaringClass()->getName(),
                $class,
            );
            $this->assertFalse($reflection->hasMethod('booted')
                && $reflection->getMethod('booted')->getDeclaringClass()->getName() === $class, $class);
            $this->assertFalse($reflection->hasMethod('boot')
                && $reflection->getMethod('boot')->getDeclaringClass()->getName() === $class, $class);
        }

        $this->assertFalse((new SupplierImportDispatchMonitorHealth)->getIncrementing());
        foreach (array_diff(array_keys(self::MODEL_TABLES), [SupplierImportDispatchMonitorHealth::class]) as $class) {
            $this->assertTrue((new $class)->getIncrementing(), $class);
        }

        foreach ([
            SupplierImportExecutionClaim::class,
            SupplierImportDispatchOutbox::class,
            SupplierImportDispatchMonitorHealth::class,
            SupplierImportDispatchAlertIntent::class,
        ] as $class) {
            $model = new $class;
            $this->assertTrue($model->usesTimestamps(), $class);
            $this->assertSame('Y-m-d H:i:s.u', $model->getDateFormat(), $class);
        }

        foreach ([
            SupplierImportDispatchRecoveryAuthorization::class,
            SupplierImportDispatchRecoveryResult::class,
        ] as $class) {
            $this->assertFalse((new $class)->usesTimestamps(), $class);
        }

        foreach ([
            SupplierImportCohortAuthorizationMember::class,
            SupplierOfferSnapshotGeneration::class,
            SupplierOfferSnapshotEnrollment::class,
            SupplierOfferSnapshotObservation::class,
        ] as $class) {
            $model = new $class;
            $this->assertTrue($model->usesTimestamps(), $class);
            $this->assertSame('created_at', $model->getCreatedAtColumn(), $class);
            $this->assertNull($model->getUpdatedAtColumn(), $class);
        }
    }

    public function test_models_expose_only_schema_faithful_casts_and_relationships(): void
    {
        $this->assertCasts(SupplierImportExecutionClaim::class, [
            'allocated_at' => 'immutable_datetime',
            'cohort_seed_count' => 'integer',
            'claimed_at' => 'immutable_datetime',
            'attempt_lease_expires_at' => 'immutable_datetime',
        ]);
        $this->assertCasts(SupplierImportDispatchOutbox::class, [
            'dispatch_payload' => 'array',
            'attempt_count' => 'integer',
            'transport_deadline_at' => 'immutable_datetime',
        ]);
        $this->assertCasts(SupplierImportDispatchMonitorHealth::class, [
            'monitor_generation' => 'integer',
            'monitor_lease_expires_at' => 'immutable_datetime',
        ]);
        $this->assertCasts(SupplierImportDispatchAlertIntent::class, [
            'payload' => 'array',
            'delivery_generation' => 'integer',
            'delivery_lease_expires_at' => 'immutable_datetime',
        ]);
        $this->assertCasts(SupplierOfferSnapshotGeneration::class, [
            'policy_versions' => 'array',
            'qualification_reason_codes' => 'array',
            'product_drop_percent' => 'decimal:6',
            'freshness_policy_approved' => 'boolean',
        ]);
        $this->assertCasts(SupplierOfferSnapshotObservation::class, [
            'present' => 'boolean',
            'price' => 'decimal:2',
            'raw_quantity_observed' => 'integer',
        ]);

        $this->assertBelongsTo(SupplierImportExecutionClaim::class, 'supplier', Supplier::class, 'supplier_id');
        $this->assertBelongsTo(SupplierImportExecutionClaim::class, 'feed', SupplierFeed::class, 'supplier_feed_id');
        $this->assertBelongsTo(SupplierImportExecutionClaim::class, 'importRun', SupplierImportRun::class, 'supplier_import_run_id');
        $this->assertBelongsTo(SupplierImportExecutionClaim::class, 'importJob', ImportJob::class, 'import_job_id');
        $this->assertBelongsTo(SupplierImportExecutionClaim::class, 'importHistory', ImportHistory::class, 'import_history_id');
        $this->assertBelongsTo(SupplierImportDispatchOutbox::class, 'executionClaim', SupplierImportExecutionClaim::class, 'supplier_import_execution_claim_id');
        $this->assertBelongsTo(SupplierImportDispatchAlertIntent::class, 'dispatchOutbox', SupplierImportDispatchOutbox::class, 'dispatch_outbox_id');
        $this->assertBelongsTo(SupplierImportDispatchRecoveryAuthorization::class, 'executionClaim', SupplierImportExecutionClaim::class, 'supplier_import_execution_claim_id');
        $this->assertBelongsTo(SupplierImportDispatchRecoveryAuthorization::class, 'dispatchOutbox', SupplierImportDispatchOutbox::class, 'supplier_import_dispatch_outbox_id');
        $this->assertBelongsTo(SupplierImportDispatchRecoveryAuthorization::class, 'authorizedOperator', User::class, 'authorized_operator_id');
        $this->assertBelongsTo(SupplierImportDispatchRecoveryResult::class, 'authorization', SupplierImportDispatchRecoveryAuthorization::class, 'supplier_import_dispatch_recovery_authorization_id');
        $this->assertBelongsTo(SupplierImportDispatchRecoveryResult::class, 'executionClaim', SupplierImportExecutionClaim::class, 'supplier_import_execution_claim_id');
        $this->assertBelongsTo(SupplierImportDispatchRecoveryResult::class, 'dispatchOutbox', SupplierImportDispatchOutbox::class, 'supplier_import_dispatch_outbox_id');
        $this->assertBelongsTo(SupplierImportDispatchRecoveryResult::class, 'authorizedOperator', User::class, 'authorized_operator_id');
        $this->assertBelongsTo(SupplierImportCohortAuthorizationMember::class, 'executionClaim', SupplierImportExecutionClaim::class, 'supplier_import_execution_claim_id');
        $this->assertBelongsTo(SupplierOfferSnapshotGeneration::class, 'supplier', Supplier::class, 'supplier_id');
        $this->assertBelongsTo(SupplierOfferSnapshotGeneration::class, 'feed', SupplierFeed::class, 'supplier_feed_id');
        $this->assertBelongsTo(SupplierOfferSnapshotGeneration::class, 'executionClaim', SupplierImportExecutionClaim::class, 'supplier_import_execution_claim_id');
        $this->assertBelongsTo(SupplierOfferSnapshotGeneration::class, 'importHistory', ImportHistory::class, 'import_history_id');
        $this->assertBelongsTo(SupplierOfferSnapshotGeneration::class, 'predecessor', SupplierOfferSnapshotGeneration::class, 'predecessor_snapshot_generation_id');
        $this->assertBelongsTo(SupplierOfferSnapshotEnrollment::class, 'supplier', Supplier::class, 'supplier_id');
        $this->assertBelongsTo(SupplierOfferSnapshotEnrollment::class, 'feed', SupplierFeed::class, 'supplier_feed_id');
        $this->assertBelongsTo(SupplierOfferSnapshotEnrollment::class, 'effectiveImportHistory', ImportHistory::class, 'effective_import_history_id');
        $this->assertBelongsTo(SupplierOfferSnapshotObservation::class, 'generation', SupplierOfferSnapshotGeneration::class, 'snapshot_generation_id');
        $this->assertBelongsTo(SupplierOfferSnapshotObservation::class, 'enrollment', SupplierOfferSnapshotEnrollment::class, 'snapshot_enrollment_id');

        $this->assertArrayNotHasKey('captured_at', (new SupplierOfferSnapshotGeneration)->getCasts());
        $this->assertArrayNotHasKey('enrolled_at', (new SupplierOfferSnapshotEnrollment)->getCasts());
    }

    public function test_all_ten_canonical_models_reject_every_eloquent_mass_assignment_path(): void
    {
        $before = $this->canonicalTableCounts();

        foreach (self::MASS_ASSIGNMENT_ATTRIBUTES as $class => [$attribute, $value]) {
            /** @var Model $model */
            $model = new $class;
            $attributes = [$attribute => $value];

            $this->assertMassAssignmentRejected(fn () => $model->fill($attributes), "{$class}::fill");
            $this->assertMassAssignmentRejected(fn () => new $class($attributes), "{$class}::__construct");
            $this->assertMassAssignmentRejected(fn () => $class::create($attributes), "{$class}::create");
            $this->assertMassAssignmentRejected(fn () => $class::query()->createQuietly($attributes), "{$class}::createQuietly");
            $this->assertMassAssignmentRejected(fn () => $model->forceFill($attributes), "{$class}::forceFill");
            $this->assertMassAssignmentRejected(fn () => $class::query()->forceCreate($attributes), "{$class}::forceCreate");
            $this->assertMassAssignmentRejected(fn () => $class::query()->forceCreateQuietly($attributes), "{$class}::forceCreateQuietly");
            $this->assertMassAssignmentRejected(fn () => $class::query()->firstOrCreate($attributes), "{$class}::firstOrCreate");
            $this->assertMassAssignmentRejected(
                fn () => $class::query()->updateOrCreate($attributes, [$attribute => $value]),
                "{$class}::updateOrCreate",
            );
            $this->assertMassAssignmentRejected(
                fn () => Model::unguarded(fn () => new $class($attributes)),
                "{$class}::unguarded",
            );
            $this->assertSame([], $model->getAttributes(), $class);
        }

        $this->assertSame($before, $this->canonicalTableCounts());
    }

    public function test_low_level_fixture_rows_still_hydrate_through_all_ten_models(): void
    {
        $ids = $this->seedCanonicalFixtureGraph();

        foreach (self::MODEL_TABLES as $class => $table) {
            $id = $ids[$class];
            $model = $class::query()->findOrFail($id);

            $this->assertSame($id, $model->getKey(), $class);
            $this->assertSame($table, $model->getTable(), $class);
            $this->assertNotSame([], $model->getAttributes(), $class);
        }
    }

    public function test_append_only_models_reject_every_available_instance_mutation_and_preserve_rows(): void
    {
        $ids = $this->seedCanonicalFixtureGraph();

        $operations = [
            'save(existing)' => fn (Model $model) => $model->save(),
            'saveQuietly' => fn (Model $model) => $model->saveQuietly(),
            'saveOrFail' => fn (Model $model) => $model->saveOrFail(),
            'push' => fn (Model $model) => $model->push(),
            'pushQuietly' => fn (Model $model) => $model->pushQuietly(),
            'update' => fn (Model $model) => $model->update(['phase_two_probe' => 'changed']),
            'updateQuietly' => fn (Model $model) => $model->updateQuietly(['phase_two_probe' => 'changed']),
            'updateOrFail' => fn (Model $model) => $model->updateOrFail(['phase_two_probe' => 'changed']),
            'touch' => fn (Model $model) => $model->touch(),
            'touchQuietly' => fn (Model $model) => $model->touchQuietly(),
            'increment' => fn (Model $model) => $model->increment('id'),
            'decrement' => fn (Model $model) => $model->decrement('id'),
            'incrementQuietly' => fn (Model $model) => $model->incrementQuietly('id'),
            'decrementQuietly' => fn (Model $model) => $model->decrementQuietly('id'),
            'delete' => fn (Model $model) => $model->delete(),
            'deleteQuietly' => fn (Model $model) => $model->deleteQuietly(),
            'deleteOrFail' => fn (Model $model) => $model->deleteOrFail(),
            'forceDelete' => fn (Model $model) => $model->forceDelete(),
        ];

        foreach (self::APPEND_ONLY_MODELS as $class) {
            $this->assertContains(GuardsImmutableCanonicalSupplierRecord::class, class_uses_recursive($class));
            $table = self::MODEL_TABLES[$class];
            $id = $ids[$class];
            $before = (array) DB::table($table)->where('id', $id)->first();

            foreach ($operations as $operation => $mutate) {
                /** @var Model $model */
                $model = $class::query()->findOrFail($id);

                try {
                    $mutate($model);
                    $this->fail("Expected {$class}::{$operation} to be rejected.");
                } catch (LogicException $exception) {
                    $this->assertSame(
                        'Immutable canonical supplier records cannot be mutated.',
                        $exception->getMessage(),
                        "{$class}::{$operation}",
                    );
                }

                $this->assertSame(
                    $before,
                    (array) DB::table($table)->where('id', $id)->first(),
                    "{$class}::{$operation}",
                );
            }
        }
    }

    public function test_model_serialization_hides_every_restricted_identity_and_payload(): void
    {
        $hiddenAttributes = [
            SupplierImportExecutionClaim::class => [
                'logical_execution_key',
                'active_attempt_token_hash',
                'source_fingerprint',
                'cohort_seed_fingerprint',
            ],
            SupplierImportDispatchOutbox::class => [
                'logical_execution_key',
                'dispatch_payload',
                'dispatch_payload_hash',
                'publication_attempt_token_hash',
                'lease_token_hash',
            ],
            SupplierImportDispatchMonitorHealth::class => ['monitor_owner_token_hash'],
            SupplierImportDispatchAlertIntent::class => ['alert_identity', 'delivery_owner_token_hash'],
            SupplierImportDispatchRecoveryAuthorization::class => [
                'logical_execution_key',
                'expected_state_fingerprint',
                'authorization_nonce_hash',
            ],
            SupplierImportDispatchRecoveryResult::class => [
                'logical_execution_key',
                'resume_state_fingerprint',
                'result_fingerprint',
            ],
            SupplierImportCohortAuthorizationMember::class => ['supplier_sku_hash'],
            SupplierOfferSnapshotGeneration::class => [
                'source_identity',
                'source_fingerprint',
                'cohort_fingerprint',
                'observation_set_fingerprint',
                'generation_fingerprint',
            ],
            SupplierOfferSnapshotEnrollment::class => [
                'source_identity',
                'supplier_sku_hash',
                'enrollment_fingerprint',
            ],
            SupplierOfferSnapshotObservation::class => [
                'supplier_sku_hash',
                'reliable_manufacturer_mpn_hash',
                'observation_fingerprint',
            ],
        ];

        $this->assertCount(10, $hiddenAttributes);

        foreach ($hiddenAttributes as $class => $attributes) {
            /** @var Model $model */
            $model = new $class;
            $model->setRawAttributes([
                'id' => 1,
                'visible_sentinel' => 'visible',
                ...array_fill_keys($attributes, str_repeat('a', 64)),
            ]);

            $serialized = $model->toArray();
            $this->assertSame('visible', $serialized['visible_sentinel'] ?? null, $class);

            foreach ($attributes as $attribute) {
                $this->assertArrayNotHasKey($attribute, $serialized, "{$class}::{$attribute}");
            }
        }
    }

    public function test_model_construction_and_relation_definition_perform_zero_database_writes(): void
    {
        $before = $this->canonicalTableCounts();
        $mutations = [];

        DB::listen(static function ($query) use (&$mutations): void {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql) === 1) {
                $mutations[] = $query->sql;
            }
        });

        foreach (self::MODEL_TABLES as $class => $table) {
            /** @var Model $model */
            $model = new $class;
            $model->toArray();
            $model->newQuery();
            $this->assertSame($table, $model->getTable());
        }

        $this->assertSame([], $mutations);
        $this->assertSame($before, $this->canonicalTableCounts());
    }

    private function assertMassAssignmentRejected(callable $operation, string $context): void
    {
        try {
            $operation();
            $this->fail("Expected {$context} to reject mass assignment.");
        } catch (MassAssignmentException $exception) {
            $this->assertSame(
                'Canonical supplier records do not support mass assignment.',
                $exception->getMessage(),
                $context,
            );
        }
    }

    /** @return array<class-string<Model>, int> */
    private function seedCanonicalFixtureGraph(): array
    {
        $now = '2026-08-20 08:00:00.000000';
        $supplierId = DB::table('suppliers')->insertGetId([
            'company_name' => 'Phase II Model Supplier',
            'slug' => 'phase-ii-model-supplier',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $feedId = DB::table('supplier_feeds')->insertGetId([
            'supplier_id' => $supplierId,
            'feed_name' => 'Phase II Model Feed',
            'feed_url' => 'https://example.test/phase-ii-model.xml',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $jobId = DB::table('import_jobs')->insertGetId([
            'supplier_id' => $supplierId,
            'supplier_feed_id' => $feedId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $runId = DB::table('supplier_import_runs')->insertGetId([
            'supplier_id' => $supplierId,
            'supplier_feed_id' => $feedId,
            'import_job_id' => $jobId,
            'trigger_type' => 'manual',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $historyId = DB::table('import_histories')->insertGetId([
            'import_job_id' => $jobId,
            'supplier_id' => $supplierId,
            'supplier_feed_id' => $feedId,
            'event' => 'started',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $userId = DB::table('users')->insertGetId([
            'name' => 'Phase II Model Operator',
            'email' => 'phase-ii-model-operator@example.test',
            'password' => 'not-used',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $logicalKey = str_repeat('a', 64);
        $claimId = DB::table('supplier_import_execution_claims')->insertGetId([
            'logical_execution_key' => $logicalKey,
            'supplier_id' => $supplierId,
            'supplier_feed_id' => $feedId,
            'supplier_import_run_id' => $runId,
            'import_job_id' => $jobId,
            'allocated_at' => $now,
            'import_history_id' => $historyId,
            'execution_path' => 'orchestrated',
        ]);
        $outboxId = DB::table('supplier_import_dispatch_outbox')->insertGetId([
            'supplier_import_execution_claim_id' => $claimId,
            'logical_execution_key' => $logicalKey,
            'event_type' => 'initial_dispatch',
            'job_type' => 'process_xml_supplier_feed',
            'dispatch_payload' => '{}',
            'dispatch_payload_hash' => str_repeat('b', 64),
            'transport_deadline_at' => '2026-08-21 08:00:00.000000',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $alertId = DB::table('supplier_import_dispatch_alert_intents')->insertGetId([
            'alert_identity' => str_repeat('c', 64),
            'schema_version' => 'supplier-import-dispatch-alert-v1',
            'alert_type' => 'dispatch_watchdog_overdue',
            'dispatch_outbox_id' => $outboxId,
            'delivery_watchdog_at' => '2026-08-20 09:00:00.000000',
            'severity' => 'warning',
            'payload' => '{}',
            'next_attempt_at' => '2026-08-20 09:00:00.000000',
        ]);
        $authorizationId = DB::table('supplier_import_dispatch_recovery_authorizations')->insertGetId([
            'supplier_import_execution_claim_id' => $claimId,
            'supplier_import_dispatch_outbox_id' => $outboxId,
            'logical_execution_key' => $logicalKey,
            'target_parent_type' => 'supplier_import_run',
            'target_parent_id' => $runId,
            'authorization_action' => 'republish_same_key',
            'expected_state_fingerprint' => str_repeat('d', 64),
            'canonical_reason_code' => 'dispatch_durable_progress_stalled',
            'authorized_operator_id' => $userId,
            'authorized_at' => '2026-08-20 08:00:00.000000',
            'expires_at' => '2026-08-20 08:15:00.000000',
            'authorization_nonce_hash' => str_repeat('e', 64),
        ]);
        $resultId = DB::table('supplier_import_dispatch_recovery_results')->insertGetId([
            'supplier_import_dispatch_recovery_authorization_id' => $authorizationId,
            'authorization_action' => 'republish_same_key',
            'authorized_operator_id' => $userId,
            'supplier_import_execution_claim_id' => $claimId,
            'supplier_import_dispatch_outbox_id' => $outboxId,
            'logical_execution_key' => $logicalKey,
            'target_parent_type' => 'supplier_import_run',
            'target_parent_id' => $runId,
            'event_sequence' => 1,
            'event_kind' => 'started',
            'canonical_result_code' => 'authorization_attempt_started',
            'resume_state_fingerprint' => str_repeat('f', 64),
            'occurred_at' => '2026-08-20 08:01:00.000000',
            'result_fingerprint' => str_repeat('1', 64),
        ]);
        $cohortMemberId = DB::table('supplier_import_cohort_authorization_members')->insertGetId([
            'supplier_import_execution_claim_id' => $claimId,
            'supplier_sku_hash' => str_repeat('2', 64),
        ]);
        $generationId = DB::table('supplier_offer_snapshot_generations')->insertGetId([
            'supplier_id' => $supplierId,
            'supplier_key' => 'phase-ii-model-v1',
            'supplier_feed_id' => $feedId,
            'supplier_import_execution_claim_id' => $claimId,
            'import_history_id' => $historyId,
            'schema_version' => 'supplier-offer-snapshot-v1',
            'producer_version' => 'phase-ii-model-test-v1',
            'qualification_policy_key' => 'qualification-v1',
            'capture_integrity_policy_key' => 'capture-integrity-v1',
            'policy_versions' => '{}',
            'source_identity' => 'snapshot-source-v1:synthetic:phase-ii-model',
            'source_fingerprint' => str_repeat('3', 64),
            'captured_at' => '2026-08-20T08:05:00+00:00',
            'capture_started_at' => '2026-08-20T08:00:00+00:00',
            'capture_completed_at' => '2026-08-20T08:05:00+00:00',
            'capture_outcome' => 'failed',
            'capture_failure_reason_code' => 'capture_persistence_failure',
            'qualification_state' => 'frozen',
            'qualification_reason_codes' => '["capture_persistence_failure"]',
            'minimum_product_count' => 1,
            'maximum_product_drop_percent' => 40,
            'generation_fingerprint' => str_repeat('4', 64),
        ]);
        $enrollmentId = DB::table('supplier_offer_snapshot_enrollments')->insertGetId([
            'supplier_id' => $supplierId,
            'supplier_feed_id' => $feedId,
            'source_identity' => 'snapshot-source-v1:synthetic:phase-ii-model',
            'supplier_sku_hash' => str_repeat('2', 64),
            'effective_import_history_id' => $historyId,
            'enrollment_source' => 'capture_start_seed',
            'enrollment_fingerprint' => str_repeat('5', 64),
            'enrolled_at' => '2026-08-20T08:05:00+00:00',
        ]);
        $observationId = DB::table('supplier_offer_snapshot_observations')->insertGetId([
            'snapshot_generation_id' => $generationId,
            'snapshot_enrollment_id' => $enrollmentId,
            'supplier_sku_hash' => str_repeat('2', 64),
            'present' => false,
            'observation_fingerprint' => str_repeat('6', 64),
        ]);

        return [
            SupplierImportExecutionClaim::class => $claimId,
            SupplierImportDispatchOutbox::class => $outboxId,
            SupplierImportDispatchMonitorHealth::class => 1,
            SupplierImportDispatchAlertIntent::class => $alertId,
            SupplierImportDispatchRecoveryAuthorization::class => $authorizationId,
            SupplierImportDispatchRecoveryResult::class => $resultId,
            SupplierImportCohortAuthorizationMember::class => $cohortMemberId,
            SupplierOfferSnapshotGeneration::class => $generationId,
            SupplierOfferSnapshotEnrollment::class => $enrollmentId,
            SupplierOfferSnapshotObservation::class => $observationId,
        ];
    }

    /** @param array<string, string> $expected */
    private function assertCasts(string $class, array $expected): void
    {
        $casts = (new $class)->getCasts();

        foreach ($expected as $attribute => $cast) {
            $this->assertSame($cast, $casts[$attribute] ?? null, "{$class}::{$attribute}");
        }
    }

    private function assertBelongsTo(
        string $class,
        string $method,
        string $related,
        string $foreignKey,
    ): void {
        /** @var BelongsTo $relation */
        $relation = (new $class)->{$method}();

        $this->assertInstanceOf(BelongsTo::class, $relation);
        $this->assertSame($related, $relation->getRelated()::class);
        $this->assertSame($foreignKey, $relation->getForeignKeyName());
        $this->assertSame('id', $relation->getOwnerKeyName());
    }

    /** @return array<string, int> */
    private function canonicalTableCounts(): array
    {
        $counts = [];

        foreach (self::MODEL_TABLES as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}

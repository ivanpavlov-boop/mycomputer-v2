<?php

namespace Tests\Feature;

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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    public function test_all_ten_canonical_models_have_exact_table_key_timestamp_and_fillable_contracts(): void
    {
        $this->assertCount(10, self::MODEL_TABLES);

        foreach (self::MODEL_TABLES as $class => $table) {
            /** @var Model $model */
            $model = new $class;

            $this->assertSame($table, $model->getTable(), $class);
            $this->assertSame('id', $model->getKeyName(), $class);
            $this->assertSame('int', $model->getKeyType(), $class);
            $this->assertNotSame([], $model->getFillable(), $class);
            $this->assertNotSame([], $model->getGuarded(), $class);
            $this->assertSame([], $model->getGlobalScopes(), $class);

            $columns = array_values(array_diff(
                Schema::getColumnListing($table),
                ['id', 'created_at', 'updated_at', 'started_once_guard', 'terminal_once_guard'],
            ));
            $fillable = $model->getFillable();
            sort($columns);
            sort($fillable);
            $this->assertSame($columns, $fillable, $class);

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

    public function test_append_only_models_fail_closed_before_update_or_delete(): void
    {
        foreach (self::APPEND_ONLY_MODELS as $class) {
            $this->assertContains(GuardsImmutableCanonicalSupplierRecord::class, class_uses_recursive($class));

            /** @var Model $model */
            $model = new $class;
            $model->exists = true;
            $model->setRawAttributes(['id' => 1, 'sentinel' => 'before'], true);
            $model->setAttribute('sentinel', 'after');

            try {
                $model->save();
                $this->fail("Expected {$class} update to be rejected.");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }

            try {
                $model->delete();
                $this->fail("Expected {$class} delete to be rejected.");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
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
            $model->fill(array_fill_keys($model->getFillable(), null));
            $model->toArray();
            $model->newQuery();
            $this->assertSame($table, $model->getTable());
        }

        $this->assertSame([], $mutations);
        $this->assertSame($before, $this->canonicalTableCounts());
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

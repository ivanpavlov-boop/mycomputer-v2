<?php

namespace Database\Migrations\Support;

use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

final class CanonicalSupplierSnapshotSchema
{
    private static bool $destructiveDownAuthorized = false;

    /** @var array<string, list<string>> */
    private const GUARDED_TABLE_COLUMNS = [
        'supplier_import_execution_claims' => [
            'id', 'logical_execution_key', 'supplier_id', 'supplier_feed_id',
            'supplier_import_run_id', 'import_job_id', 'allocated_at',
            'import_history_id', 'execution_path', 'state',
            'active_attempt_token_hash', 'source_fingerprint',
            'cohort_authorization_version', 'cohort_authorized_at',
            'cohort_seed_count', 'cohort_seed_fingerprint',
            'terminal_reason_code', 'claimed_at', 'attempt_lease_expires_at',
            'processing_started_at', 'terminal_at', 'created_at', 'updated_at',
        ],
        'supplier_import_dispatch_outbox' => [
            'id', 'supplier_import_execution_claim_id', 'logical_execution_key',
            'event_type', 'job_type', 'dispatch_payload', 'dispatch_payload_hash',
            'transport_deadline_at', 'state', 'attempt_count',
            'publication_attempt_generation', 'publication_attempt_state',
            'publication_attempt_token_hash', 'publication_attempt_reserved_at',
            'publication_attempt_lease_expires_at',
            'publication_external_fence_installed_at',
            'publication_call_boundary_at', 'publication_attempt_resolved_at',
            'delivery_attempt_count', 'lease_owner_key', 'lease_token_hash',
            'leased_at', 'lease_expires_at', 'next_attempt_at', 'published_at',
            'last_published_at', 'delivery_watchdog_at', 'recovery_required_at',
            'recovery_reason_code', 'terminal_at',
            'terminal_failure_reason_code', 'created_at', 'updated_at',
        ],
        'supplier_import_dispatch_monitor_health' => [
            'id', 'monitor_identity', 'monitor_generation',
            'last_successful_monitor_generation', 'monitor_owner_token_hash',
            'monitor_lease_acquired_at', 'monitor_lease_expires_at',
            'cycle_sequence', 'last_successful_cycle_at',
            'last_successful_sink_health_at',
            'last_successful_sink_contract_key', 'observer_identity',
            'observer_sequence', 'observed_monitor_generation',
            'observed_cycle_sequence', 'last_successful_observer_at',
            'integrity_state', 'last_failure_code', 'created_at', 'updated_at',
        ],
        'supplier_import_dispatch_alert_intents' => [
            'id', 'alert_identity', 'schema_version', 'alert_type',
            'dispatch_outbox_id', 'delivery_watchdog_at', 'severity',
            'critical_bucket', 'payload', 'delivery_state', 'attempt_count',
            'delivery_generation', 'delivery_owner_token_hash',
            'delivery_lease_acquired_at', 'delivery_lease_expires_at',
            'next_attempt_at', 'acknowledged_at', 'last_failure_code',
            'created_at', 'updated_at',
        ],
        'supplier_import_dispatch_recovery_authorizations' => [
            'id', 'supplier_import_execution_claim_id',
            'supplier_import_dispatch_outbox_id', 'logical_execution_key',
            'target_parent_type', 'target_parent_id', 'authorization_action',
            'expected_state_fingerprint', 'canonical_reason_code',
            'authorized_operator_id', 'authorized_at', 'expires_at',
            'authorization_nonce_hash',
        ],
        'supplier_import_dispatch_recovery_results' => [
            'id', 'supplier_import_dispatch_recovery_authorization_id',
            'authorization_action', 'authorized_operator_id',
            'supplier_import_execution_claim_id',
            'supplier_import_dispatch_outbox_id', 'logical_execution_key',
            'target_parent_type', 'target_parent_id', 'event_sequence',
            'event_kind', 'canonical_result_code', 'resume_state_fingerprint',
            'occurred_at', 'result_fingerprint', 'started_once_guard',
            'terminal_once_guard',
        ],
        'supplier_import_cohort_authorization_members' => [
            'id', 'supplier_import_execution_claim_id', 'supplier_sku_hash',
            'created_at',
        ],
        'supplier_offer_snapshot_generations' => [
            'id', 'supplier_id', 'supplier_key', 'supplier_feed_id',
            'supplier_import_execution_claim_id', 'import_history_id',
            'predecessor_snapshot_generation_id', 'schema_version',
            'producer_version', 'qualification_policy_key',
            'capture_integrity_policy_key', 'policy_versions',
            'freshness_policy_key', 'freshness_max_age_hours',
            'freshness_policy_approved', 'source_identity', 'source_fingerprint',
            'captured_at', 'authoritative_snapshot_at', 'capture_started_at',
            'capture_completed_at', 'capture_outcome',
            'capture_failure_reason_code', 'qualification_state',
            'qualification_reason_codes', 'successful', 'full', 'schema_valid',
            'truncated', 'fatal_integrity_blocker',
            'supplier_identity_confirmed', 'comparable', 'total_observed_count',
            'valid_observation_count', 'invalid_observation_count',
            'rejected_observation_count', 'duplicate_observation_count',
            'enrolled_observation_count', 'minimum_product_count',
            'product_drop_percent', 'maximum_product_drop_percent',
            'cohort_fingerprint', 'observation_set_fingerprint',
            'generation_fingerprint', 'created_at',
        ],
        'supplier_offer_snapshot_enrollments' => [
            'id', 'supplier_id', 'supplier_feed_id', 'source_identity',
            'supplier_sku_hash', 'effective_import_history_id',
            'enrollment_source', 'enrollment_fingerprint', 'enrolled_at',
            'created_at',
        ],
        'supplier_offer_snapshot_observations' => [
            'id', 'snapshot_generation_id', 'snapshot_enrollment_id',
            'supplier_sku_hash', 'present', 'price', 'currency',
            'raw_quantity_observed', 'eol_flag', 'canonical_public_status',
            'supplier_mapper_valid', 'exact_supplier_sku_match',
            'identifier_conflict', 'blocking_validation_issue',
            'duplicate_offer', 'reliable_manufacturer_mpn_hash',
            'observation_fingerprint', 'created_at',
        ],
    ];

    /** @var list<string> */
    private const FORWARD_GATES = [
        'supplier_snapshot_capture.capture_enabled',
        'supplier_snapshot_capture.protected_generation_admission_enabled',
        'supplier_snapshot_capture.recovery_issuance_enabled',
        'supplier_snapshot_capture.recovery_execution_enabled',
        'supplier_snapshot_capture.monitor_schedule_enabled',
        'supplier_snapshot_capture.observer_schedule_enabled',
        'supplier_snapshot_capture.alert_delivery_enabled',
    ];

    public static function isMySql(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }

    public static function ascii(ColumnDefinition $column): ColumnDefinition
    {
        if (self::isMySql()) {
            $column->charset('ascii')->collation('ascii_bin');
        }

        return $column;
    }

    /** @param array<string, string> $checks */
    public static function addChecks(string $table, array $checks): void
    {
        if (! self::isMySql()) {
            return;
        }

        foreach ($checks as $name => $expression) {
            DB::unprepared(sprintf(
                'ALTER TABLE `%s` ADD CONSTRAINT `%s` CHECK (%s)',
                $table,
                $name,
                $expression,
            ));
        }
    }

    public static function addNoMutationTriggers(
        string $table,
        string $updateTrigger,
        string $deleteTrigger,
    ): void {
        if (! self::isMySql()) {
            return;
        }

        DB::unprepared(sprintf(
            "CREATE TRIGGER `%s` BEFORE UPDATE ON `%s` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable supplier snapshot row cannot be updated'",
            $updateTrigger,
            $table,
        ));
        DB::unprepared(sprintf(
            "CREATE TRIGGER `%s` BEFORE DELETE ON `%s` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable supplier snapshot row cannot be deleted'",
            $deleteTrigger,
            $table,
        ));
    }

    /** @param list<string> $triggers */
    public static function dropTriggers(array $triggers): void
    {
        if (! self::isMySql()) {
            return;
        }

        foreach ($triggers as $trigger) {
            DB::unprepared(sprintf('DROP TRIGGER IF EXISTS `%s`', $trigger));
        }
    }

    public static function assertDestructiveDownAllowed(): void
    {
        if (self::$destructiveDownAuthorized) {
            return;
        }

        try {
            self::assertEnvironment();
            self::assertConfirmation();
            self::assertForwardGatesDisabled();
            self::assertCompleteReadableSchema();
            self::assertNineTablesEmpty();
            self::assertPristineMonitorSingleton();
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Canonical supplier snapshot schema downgrade rejected before destructive DDL: '.$exception->getMessage(),
                0,
                $exception,
            );
        }

        self::$destructiveDownAuthorized = true;
    }

    private static function assertEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('environment must be exactly local or testing');
        }
    }

    private static function assertConfirmation(): void
    {
        if (getenv('SUPPLIER_SNAPSHOT_EMPTY_SCHEMA_DOWN_CONFIRMED') !== 'true') {
            throw new RuntimeException('explicit process confirmation is missing');
        }
    }

    private static function assertForwardGatesDisabled(): void
    {
        foreach (self::FORWARD_GATES as $gate) {
            if (config($gate, false) !== false) {
                throw new RuntimeException(sprintf('forward gate %s is not disabled', $gate));
            }
        }
    }

    private static function assertCompleteReadableSchema(): void
    {
        foreach (self::GUARDED_TABLE_COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException(sprintf('expected table %s is missing', $table));
            }

            if (! Schema::hasColumns($table, $columns)) {
                throw new RuntimeException(sprintf('guard-visible columns for %s are incomplete', $table));
            }
        }
    }

    private static function assertNineTablesEmpty(): void
    {
        $populated = [];

        foreach (array_keys(self::GUARDED_TABLE_COLUMNS) as $table) {
            if ($table === 'supplier_import_dispatch_monitor_health') {
                continue;
            }

            if (DB::table($table)->count() !== 0) {
                $populated[] = $table;
            }
        }

        if ($populated !== []) {
            throw new RuntimeException(sprintf(
                'protected tables are not empty: %s',
                implode(', ', $populated),
            ));
        }
    }

    private static function assertPristineMonitorSingleton(): void
    {
        $rows = DB::table('supplier_import_dispatch_monitor_health')->get();

        if ($rows->count() !== 1) {
            throw new RuntimeException('monitor table is not the exact singleton');
        }

        $row = (array) $rows->first();
        $expected = [
            'id' => 1,
            'monitor_identity' => 'supplier-import-dispatch-watchdog-v1',
            'monitor_generation' => 0,
            'last_successful_monitor_generation' => 0,
            'monitor_owner_token_hash' => null,
            'monitor_lease_acquired_at' => null,
            'monitor_lease_expires_at' => null,
            'cycle_sequence' => 0,
            'last_successful_cycle_at' => null,
            'last_successful_sink_health_at' => null,
            'last_successful_sink_contract_key' => null,
            'observer_identity' => 'supplier-import-dispatch-observer-v1',
            'observer_sequence' => 0,
            'observed_monitor_generation' => 0,
            'observed_cycle_sequence' => 0,
            'last_successful_observer_at' => null,
            'integrity_state' => 'unknown',
            'last_failure_code' => null,
        ];

        foreach ($expected as $column => $value) {
            if (($row[$column] ?? null) != $value) {
                throw new RuntimeException(sprintf('monitor singleton column %s is not pristine', $column));
            }
        }
    }
}

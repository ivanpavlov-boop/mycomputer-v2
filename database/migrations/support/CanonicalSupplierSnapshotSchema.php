<?php

namespace Database\Migrations\Support;

use Closure;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Throwable;

final class CanonicalSupplierSnapshotSchema
{
    private const DOWN_CAPABILITY_ENV = 'SUPPLIER_SNAPSHOT_EMPTY_SCHEMA_DOWN_CAPABILITY';

    private const DOWN_CAPABILITY_VERSION = 'canonical-supplier-snapshot-down-v1';

    private const DOWN_CAPABILITY_TTL_SECONDS = 300;

    /** @var list<string> */
    private const DOWN_MIGRATIONS = [
        '2026_08_20_120011_add_supplier_range_index_to_import_histories',
        '2026_08_20_120010_create_supplier_offer_snapshot_observations_table',
        '2026_08_20_120009_create_supplier_offer_snapshot_enrollments_table',
        '2026_08_20_120008_create_supplier_offer_snapshot_generations_table',
        '2026_08_20_120007_create_supplier_import_cohort_authorization_members_table',
        '2026_08_20_120006_create_supplier_import_dispatch_recovery_results_table',
        '2026_08_20_120005_create_supplier_import_dispatch_recovery_authorizations_table',
        '2026_08_20_120004_create_supplier_import_dispatch_alert_intents_table',
        '2026_08_20_120003_create_supplier_import_dispatch_monitor_health_table',
        '2026_08_20_120002_create_supplier_import_dispatch_outbox_table',
        '2026_08_20_120001_create_supplier_import_execution_claims_table',
        '2026_08_20_120000_add_supplier_ownership_key_to_import_jobs',
    ];

    /** @var list<string> */
    private const GUARDED_TRIGGERS = [
        'trg_import_cohort_auth_no_delete',
        'trg_import_cohort_auth_no_update',
        'trg_import_execution_claim_path_immutable',
        'trg_import_recovery_auth_no_delete',
        'trg_import_recovery_auth_no_update',
        'trg_import_recovery_result_no_delete',
        'trg_import_recovery_result_no_update',
        'trg_snapshot_enrollment_no_delete',
        'trg_snapshot_enrollment_no_update',
        'trg_snapshot_generation_no_delete',
        'trg_snapshot_generation_no_update',
        'trg_snapshot_observation_no_delete',
        'trg_snapshot_observation_no_update',
    ];

    /** @var array{input: InputInterface, next_step: int}|null */
    private static ?array $destructiveDownScope = null;

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

    public static function issueDestructiveDownCapability(): string
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Destructive down capability issuance requires local or testing');
        }

        $token = bin2hex(random_bytes(32));
        $path = self::capabilityPath($token);
        $handle = @fopen($path, 'x+b');

        if ($handle === false) {
            throw new RuntimeException('Unable to create one-use destructive down capability');
        }

        try {
            @chmod($path, 0600);
            $payload = json_encode([
                'version' => self::DOWN_CAPABILITY_VERSION,
                'token_hash' => hash('sha256', $token),
                'expires_at' => time() + self::DOWN_CAPABILITY_TTL_SECONDS,
            ], JSON_THROW_ON_ERROR);

            $offset = 0;
            while ($offset < strlen($payload)) {
                $written = fwrite($handle, substr($payload, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Unable to persist one-use destructive down capability');
                }
                $offset += $written;
            }

            if (! fflush($handle)) {
                throw new RuntimeException('Unable to flush one-use destructive down capability');
            }
        } catch (Throwable $exception) {
            fclose($handle);
            @unlink($path);

            throw $exception;
        }

        fclose($handle);

        return $token;
    }

    public static function revokeDestructiveDownCapability(string $token): void
    {
        if (preg_match('/\A[0-9a-f]{64}\z/', $token) === 1) {
            @unlink(self::capabilityPath($token));
        }
    }

    public static function runDestructiveDownStep(string $migration, Closure $operation): void
    {
        $input = self::currentConsoleInput();

        if (self::$destructiveDownScope !== null && self::$destructiveDownScope['input'] !== $input) {
            self::invalidateDestructiveDownScope();
        }

        if (self::$destructiveDownScope === null) {
            if ($migration !== self::DOWN_MIGRATIONS[0]) {
                self::discardSuppliedCapability();

                throw self::downgradeRejected(sprintf(
                    'rollback sequence must begin with %s',
                    self::DOWN_MIGRATIONS[0],
                ));
            }

            self::authorizeDestructiveDownScope($input);
        }

        $step = self::$destructiveDownScope['next_step'];
        $expected = self::DOWN_MIGRATIONS[$step] ?? null;

        if ($expected !== $migration) {
            self::invalidateDestructiveDownScope();

            throw self::downgradeRejected(sprintf(
                'unexpected rollback step %s; expected %s',
                $migration,
                $expected ?? 'completed',
            ));
        }

        try {
            self::assertMigrationSequenceState($step);
            $operation();
        } catch (Throwable $exception) {
            self::invalidateDestructiveDownScope();

            throw $exception;
        }

        $nextStep = $step + 1;
        if ($nextStep === count(self::DOWN_MIGRATIONS)) {
            self::invalidateDestructiveDownScope();
        } else {
            self::$destructiveDownScope['next_step'] = $nextStep;
        }
    }

    private static function authorizeDestructiveDownScope(InputInterface $input): void
    {
        try {
            self::consumeInvocationCapability();
            self::assertEnvironment();
            self::assertForwardGatesDisabled();
            self::assertCompleteReadableSchema();
            self::assertNineTablesEmpty();
            self::assertPristineMonitorSingleton();
            self::assertMigrationSequenceState(0);
        } catch (Throwable $exception) {
            self::invalidateDestructiveDownScope();

            throw self::downgradeRejected($exception->getMessage(), $exception);
        }

        self::$destructiveDownScope = [
            'input' => $input,
            'next_step' => 0,
        ];
    }

    private static function assertEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('environment must be exactly local or testing');
        }
    }

    private static function consumeInvocationCapability(): void
    {
        $token = getenv(self::DOWN_CAPABILITY_ENV);
        self::clearSuppliedCapabilityEnvironment();

        if (! is_string($token) || preg_match('/\A[0-9a-f]{64}\z/', $token) !== 1) {
            throw new RuntimeException('one-use invocation capability is missing or malformed');
        }

        $path = self::capabilityPath($token);
        $consumedPath = $path.'.consumed.'.bin2hex(random_bytes(8));

        if (! @rename($path, $consumedPath)) {
            throw new RuntimeException('one-use invocation capability artifact is missing or already consumed');
        }

        try {
            $payload = json_decode((string) file_get_contents($consumedPath), true, flags: JSON_THROW_ON_ERROR);
            $expectedHash = hash('sha256', $token);

            if (($payload['version'] ?? null) !== self::DOWN_CAPABILITY_VERSION
                || ! is_string($payload['token_hash'] ?? null)
                || ! hash_equals($expectedHash, $payload['token_hash'])
                || ! is_int($payload['expires_at'] ?? null)
                || $payload['expires_at'] < time()
                || $payload['expires_at'] > time() + self::DOWN_CAPABILITY_TTL_SECONDS) {
                throw new RuntimeException('one-use invocation capability is invalid or expired');
            }
        } finally {
            @unlink($consumedPath);
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

        $triggers = collect(DB::select(<<<'SQL'
            SELECT TRIGGER_NAME AS trigger_name
            FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE()
                AND EVENT_OBJECT_TABLE IN (
                    'supplier_import_execution_claims',
                    'supplier_import_dispatch_outbox',
                    'supplier_import_dispatch_monitor_health',
                    'supplier_import_dispatch_alert_intents',
                    'supplier_import_dispatch_recovery_authorizations',
                    'supplier_import_dispatch_recovery_results',
                    'supplier_import_cohort_authorization_members',
                    'supplier_offer_snapshot_generations',
                    'supplier_offer_snapshot_enrollments',
                    'supplier_offer_snapshot_observations'
                )
            ORDER BY TRIGGER_NAME
            SQL))->pluck('trigger_name')->all();

        if ($triggers !== self::GUARDED_TRIGGERS) {
            throw new RuntimeException('canonical trigger inventory is incomplete or unexpected');
        }

        foreach ([
            ['import_jobs', 'uq_import_job_id_supplier_feed'],
            ['import_histories', 'ix_import_history_supplier_id'],
        ] as [$table, $index]) {
            if ((int) DB::scalar(<<<'SQL'
                SELECT COUNT(*)
                FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
                SQL, [$table, $index]) === 0) {
                throw new RuntimeException(sprintf('expected support index %s is missing', $index));
            }
        }

        if ((int) DB::scalar(<<<'SQL'
            SELECT COUNT(*)
            FROM information_schema.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE()
                AND TABLE_NAME = 'supplier_import_dispatch_alert_intents'
                AND CONSTRAINT_NAME = 'fk_import_dispatch_alert_outbox'
            SQL) !== 1) {
            throw new RuntimeException('expected alert outbox foreign key is missing');
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

    private static function assertMigrationSequenceState(int $step): void
    {
        if (! Schema::hasTable('migrations')) {
            throw new RuntimeException('migration repository is unavailable');
        }

        $expected = array_slice(array_reverse(self::DOWN_MIGRATIONS), 0, count(self::DOWN_MIGRATIONS) - $step);
        sort($expected);
        $actual = DB::table('migrations')
            ->whereIn('migration', self::DOWN_MIGRATIONS)
            ->pluck('migration')
            ->all();
        sort($actual);

        if ($actual !== $expected) {
            throw new RuntimeException('Phase I migration repository sequence does not match the owned rollback step');
        }
    }

    private static function currentConsoleInput(): InputInterface
    {
        foreach (debug_backtrace(0, 64) as $frame) {
            foreach ($frame['args'] ?? [] as $argument) {
                if ($argument instanceof InputInterface) {
                    return $argument;
                }
            }
        }

        throw self::downgradeRejected('destructive down must run inside one console command invocation');
    }

    private static function discardSuppliedCapability(): void
    {
        $token = getenv(self::DOWN_CAPABILITY_ENV);
        self::clearSuppliedCapabilityEnvironment();

        if (! is_string($token) || preg_match('/\A[0-9a-f]{64}\z/', $token) !== 1) {
            return;
        }

        $path = self::capabilityPath($token);
        $consumedPath = $path.'.discarded.'.bin2hex(random_bytes(8));
        if (@rename($path, $consumedPath)) {
            @unlink($consumedPath);
        }
    }

    private static function clearSuppliedCapabilityEnvironment(): void
    {
        putenv(self::DOWN_CAPABILITY_ENV);
        unset($_ENV[self::DOWN_CAPABILITY_ENV], $_SERVER[self::DOWN_CAPABILITY_ENV]);
    }

    private static function capabilityPath(string $token): string
    {
        return sys_get_temp_dir().DIRECTORY_SEPARATOR
            .'mycomputer-canonical-snapshot-down-'.hash('sha256', $token).'.cap';
    }

    private static function invalidateDestructiveDownScope(): void
    {
        self::$destructiveDownScope = null;
    }

    private static function downgradeRejected(string $message, ?Throwable $previous = null): RuntimeException
    {
        return new RuntimeException(
            'Canonical supplier snapshot schema downgrade rejected before destructive DDL: '.$message,
            0,
            $previous,
        );
    }
}

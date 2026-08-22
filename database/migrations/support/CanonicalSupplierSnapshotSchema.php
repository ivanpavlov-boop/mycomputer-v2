<?php

namespace Database\Migrations\Support;

use Closure;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Process\Process;
use Throwable;

final class CanonicalSupplierSnapshotSchema
{
    private const DOWN_CAPABILITY_ENV = 'SUPPLIER_SNAPSHOT_EMPTY_SCHEMA_DOWN_CAPABILITY';

    private const DOWN_CAPABILITY_VERSION = 'canonical-supplier-snapshot-down-v3';

    private const DOWN_CAPABILITY_LEDGER_VERSION = 'canonical-supplier-snapshot-down-ledger-v2';

    private const DOWN_CAPABILITY_TARGET_IDENTITY_VERSION = 'canonical-supplier-snapshot-down-target-v1';

    private const DOWN_CAPABILITY_TARGET_IDENTITY_DOMAIN = 'mycomputer:phase-i-down-target:v1';

    private const DOWN_CAPABILITY_TTL_SECONDS = 300;

    private const DOWN_COMMAND = 'migrate:rollback';

    private const DOWN_CAPABILITY_ROOT = 'mycomputer-phase-i-down';

    private const DOWN_CAPABILITY_LEDGER_ROOT = 'mycomputer-phase-i-down-ledger';

    private const DOWN_CAPABILITY_FILE = 'capability.json';

    private const DOWN_CAPABILITY_LEDGER_KEY_DOMAIN = 'mycomputer:phase-i-down-capability-ledger:v2';

    /** @var list<string> */
    private const DOWN_CAPABILITY_SPENT_REASONS = [
        'consumption_claimed',
        'explicit_revocation',
        'issuance_failed',
    ];

    /** @var list<string> */
    private const DESTRUCTIVE_MIGRATION_COMMANDS = [
        'migrate:rollback',
        'migrate:reset',
        'migrate:refresh',
    ];

    /** @var list<string> */
    private const REJECTED_ROLLBACK_OPTIONS = [
        '--step',
        '--batch',
        '--path',
        '--realpath',
        '--pretend',
    ];

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

    /**
     * @var array{
     *     input: InputInterface,
     *     invocation_id: string,
     *     command: string,
     *     connection: string,
     *     database: string,
     *     plan_sha256: string,
     *     selected_migrations: list<string>,
     *     capability_directory: string,
     *     capability_directory_identity: string,
     *     next_step: int
     * }|null
     */
    private static ?array $destructiveDownScope = null;

    private static ?object $destructiveDownLifecycleDispatcher = null;

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

    public static function bootstrapDestructiveDownGuard(): void
    {
        self::registerDestructiveDownLifecycle();

        $input = self::currentConsoleInputOrNull();
        if ($input === null) {
            return;
        }

        $command = (string) ($input->getFirstArgument() ?? '');
        if (in_array($command, self::DESTRUCTIVE_MIGRATION_COMMANDS, true)) {
            self::prepareDestructiveDownInvocation($command, $input);
        }
    }

    public static function issueDestructiveDownCapability(?string $connection = null): string
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Destructive down capability issuance requires local or testing');
        }

        $target = self::canonicalTargetIdentity($connection ?? DB::getDefaultConnection());

        for ($attempt = 0; $attempt < 8; $attempt++) {
            try {
                return self::issueDestructiveDownCapabilityAttempt($target);
            } catch (RuntimeException $exception) {
                if ($exception->getMessage() !== 'Unable to exclusively create authoritative destructive down issuance record') {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException('Unable to allocate a unique destructive down capability');
    }

    /** @param array{connection_name: string, target_identity_sha256: string} $target */
    private static function issueDestructiveDownCapabilityAttempt(array $target): string
    {
        $token = bin2hex(random_bytes(32));
        $tokenSha256 = hash('sha256', $token);
        $rollbackPlanSha256 = self::canonicalRollbackPlanSha256();
        $targetIdentitySha256 = $target['target_identity_sha256'];
        $expiresAt = self::currentTimestamp() + self::DOWN_CAPABILITY_TTL_SECONDS;
        $issuanceId = bin2hex(random_bytes(32));
        $issuedPayload = [
            'version' => self::DOWN_CAPABILITY_LEDGER_VERSION,
            'token_sha256' => $tokenSha256,
            'rollback_plan_sha256' => $rollbackPlanSha256,
            'target_identity_sha256' => $targetIdentitySha256,
            'expires_at' => $expiresAt,
            'issuance_id' => $issuanceId,
        ];
        $issuedRaw = self::canonicalJson($issuedPayload);
        $directory = self::capabilityDirectory($token, $targetIdentitySha256);
        $path = self::capabilityPath($token, $targetIdentitySha256);
        $issuedPath = self::capabilityIssuedLedgerPath(
            $tokenSha256,
            $rollbackPlanSha256,
            $targetIdentitySha256,
        );
        $issuedCreated = false;

        try {
            self::ensureCapabilityRoot();
            self::ensureCapabilityLedgerRoot();

            $issuedCreated = self::createExclusiveSecureFile($issuedPath, $issuedRaw);
            if (! $issuedCreated) {
                throw new RuntimeException('Unable to exclusively create authoritative destructive down issuance record');
            }
            $spentPath = self::capabilitySpentLedgerPath(self::capabilityLedgerKey(
                $tokenSha256,
                $rollbackPlanSha256,
                $targetIdentitySha256,
            ));
            clearstatcache(true, $spentPath);
            if (file_exists($spentPath) || is_link($spentPath)) {
                throw new RuntimeException('Unable to exclusively create authoritative destructive down issuance record');
            }

            self::createPrivateDirectory($directory);
            self::assertSecureDirectory($directory);
            $artifactCreated = self::createExclusiveSecureFile($path, self::canonicalJson([
                'version' => self::DOWN_CAPABILITY_VERSION,
                'token_sha256' => $tokenSha256,
                'rollback_plan_sha256' => $rollbackPlanSha256,
                'target_identity_sha256' => $targetIdentitySha256,
                'expires_at' => $expiresAt,
                'issuance_id' => $issuanceId,
            ]));
            if (! $artifactCreated) {
                throw new RuntimeException('Unable to exclusively create one-use destructive down capability');
            }

            self::loadAuthoritativeIssuedRecord($token, $targetIdentitySha256);
            self::assertSecureDirectory($directory);
            self::assertSecureArtifact($path);
        } catch (Throwable $exception) {
            if ($issuedCreated) {
                try {
                    $issued = self::loadAuthoritativeIssuedRecord($token, $targetIdentitySha256);
                    self::claimCapabilitySpent($issued, 'issuance_failed');
                } catch (Throwable) {
                    // A failed issuance never returns its token; preserve any ledger state fail closed.
                }
            }
            self::removeCapabilityArtifact($token, $targetIdentitySha256);

            throw $exception;
        }

        return $token;
    }

    public static function revokeDestructiveDownCapability(string $token, ?string $connection = null): void
    {
        if (preg_match('/\A[0-9a-f]{64}\z/', $token) !== 1) {
            return;
        }

        $target = self::canonicalTargetIdentity($connection ?? DB::getDefaultConnection());

        try {
            $issued = self::loadAuthoritativeIssuedRecord($token, $target['target_identity_sha256']);
            self::claimCapabilitySpent($issued, 'explicit_revocation');
        } catch (Throwable $exception) {
            if (! str_contains($exception->getMessage(), 'authoritative issued ledger record is missing')) {
                throw $exception;
            }
        }

        self::removeCapabilityArtifact($token, $target['target_identity_sha256']);
    }

    public static function runDestructiveDownStep(string $migration, Closure $operation): void
    {
        $input = self::currentConsoleInput();
        $scope = self::$destructiveDownScope;

        if ($scope === null) {
            self::discardSuppliedCapability();

            throw self::downgradeRejected('destructive rollback invocation was not authorized before migration execution');
        }

        if ($scope['input'] !== $input
            || $scope['command'] !== self::DOWN_COMMAND
            || $input->getFirstArgument() !== self::DOWN_COMMAND) {
            self::invalidateDestructiveDownScope();

            throw self::downgradeRejected('rollback invocation identity changed');
        }

        $connection = DB::getDefaultConnection();
        $database = DB::connection()->getDatabaseName();
        $target = self::canonicalTargetIdentity($connection);
        $planSha256 = self::invocationRollbackPlanSha256(
            $connection,
            $database,
            $target['target_identity_sha256'],
            $scope['selected_migrations'],
        );
        if ($scope['connection'] !== $connection
            || $scope['database'] !== $database
            || ! hash_equals($scope['target_identity_sha256'], $target['target_identity_sha256'])
            || ! hash_equals($scope['plan_sha256'], $planSha256)
            || $scope['capability_directory_identity'] !== self::directoryIdentity($scope['capability_directory'])) {
            self::invalidateDestructiveDownScope();

            throw self::downgradeRejected('rollback invocation plan or private capability directory identity changed');
        }

        $step = $scope['next_step'];
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

    private static function registerDestructiveDownLifecycle(): void
    {
        $dispatcher = Event::getFacadeRoot();
        if (! is_object($dispatcher)) {
            throw self::downgradeRejected('console event dispatcher is unavailable');
        }

        if (self::$destructiveDownLifecycleDispatcher === $dispatcher) {
            return;
        }

        $dispatcher->listen(CommandStarting::class, static function (CommandStarting $event): void {
            if (in_array($event->command, self::DESTRUCTIVE_MIGRATION_COMMANDS, true)) {
                self::prepareDestructiveDownInvocation($event->command, $event->input);
            }
        });
        $dispatcher->listen(CommandFinished::class, static function (CommandFinished $event): void {
            if (self::$destructiveDownScope !== null) {
                self::invalidateDestructiveDownScope();
            }
        });

        self::$destructiveDownLifecycleDispatcher = $dispatcher;
    }

    private static function prepareDestructiveDownInvocation(string $command, InputInterface $input): void
    {
        $connection = self::rollbackConnectionName($input);

        if (self::$destructiveDownScope !== null) {
            if (self::$destructiveDownScope['input'] === $input
                && self::$destructiveDownScope['command'] === $command) {
                return;
            }

            self::discardSuppliedCapability($connection);
            self::invalidateDestructiveDownScope();

            throw self::downgradeRejected('nested or re-entrant destructive migration command is not allowed');
        }

        if ($command !== self::DOWN_COMMAND || $input->getFirstArgument() !== self::DOWN_COMMAND) {
            self::discardSuppliedCapability($connection);

            throw self::downgradeRejected(sprintf('command %s is not allowed', $command));
        }

        try {
            self::assertAllowedRollbackSelectors($input);
            self::withRollbackConnection($input, function (Migrator $migrator) use ($input): void {
                $selectedMigrations = self::exactSelectedRollbackMigrations($migrator);
                self::assertCanonicalMigrationFilesAvailable($migrator, $selectedMigrations);
                self::authorizeDestructiveDownScope($input, $selectedMigrations);
            });
        } catch (Throwable $exception) {
            self::discardSuppliedCapability($connection);
            self::invalidateDestructiveDownScope();

            if (str_starts_with($exception->getMessage(), 'Canonical supplier snapshot schema downgrade rejected')) {
                throw $exception;
            }

            throw self::downgradeRejected($exception->getMessage(), $exception);
        }
    }

    /** @param list<string> $selectedMigrations */
    private static function authorizeDestructiveDownScope(InputInterface $input, array $selectedMigrations): void
    {
        $capability = null;

        try {
            $capability = self::consumeInvocationCapability();
            self::assertEnvironment();
            self::assertForwardGatesDisabled();
            self::assertCompleteReadableSchema();
            self::assertNineTablesEmpty();
            self::assertPristineMonitorSingleton();
            self::assertMigrationSequenceState(0);
        } catch (Throwable $exception) {
            if (is_array($capability)) {
                self::removeConsumedCapabilityDirectory(
                    $capability['directory'],
                    $capability['directory_identity'],
                );
            }
            self::invalidateDestructiveDownScope();

            throw self::downgradeRejected($exception->getMessage(), $exception);
        }

        $connection = DB::getDefaultConnection();
        $database = DB::connection()->getDatabaseName();
        self::$destructiveDownScope = [
            'input' => $input,
            'invocation_id' => bin2hex(random_bytes(32)),
            'command' => self::DOWN_COMMAND,
            'connection' => $connection,
            'database' => $database,
            'target_identity_sha256' => $capability['target_identity_sha256'],
            'plan_sha256' => self::invocationRollbackPlanSha256(
                $connection,
                $database,
                $capability['target_identity_sha256'],
                $selectedMigrations,
            ),
            'selected_migrations' => $selectedMigrations,
            'capability_directory' => $capability['directory'],
            'capability_directory_identity' => $capability['directory_identity'],
            'next_step' => 0,
        ];
    }

    private static function assertEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('environment must be exactly local or testing');
        }
    }

    /** @return array{directory: string, directory_identity: string, target_identity_sha256: string} */
    private static function consumeInvocationCapability(): array
    {
        $token = getenv(self::DOWN_CAPABILITY_ENV);
        self::clearSuppliedCapabilityEnvironment();

        if (! is_string($token) || preg_match('/\A[0-9a-f]{64}\z/', $token) !== 1) {
            throw new RuntimeException('one-use invocation capability is missing or malformed');
        }

        $target = self::canonicalTargetIdentity(DB::getDefaultConnection());

        try {
            $issued = self::loadAuthoritativeIssuedRecord($token, $target['target_identity_sha256']);
        } catch (Throwable $exception) {
            self::removeCapabilityArtifact($token, $target['target_identity_sha256']);

            throw $exception;
        }

        if (! self::claimCapabilitySpent($issued, 'consumption_claimed')) {
            throw new RuntimeException('one-use invocation capability is already spent or revoked');
        }

        if ($issued['expires_at'] < self::currentTimestamp()) {
            self::rejectCapabilityConsumption(
                $token,
                $target['target_identity_sha256'],
                new RuntimeException('authoritative issued ledger record is expired'),
            );
        }

        return [
            ...self::consumeClaimedCapabilityArtifact($token, $target['target_identity_sha256'], $issued),
            'target_identity_sha256' => $target['target_identity_sha256'],
        ];
    }

    /**
     * @param array{
     *     version: string,
     *     token_sha256: string,
     *     rollback_plan_sha256: string,
     *     target_identity_sha256: string,
     *     expires_at: int,
     *     issuance_id: string,
     *     capability_key: string,
     *     issued_record_sha256: string
     * } $issued
     * @return array{directory: string, directory_identity: string}
     */
    private static function consumeClaimedCapabilityArtifact(
        string $token,
        string $targetIdentitySha256,
        array $issued,
    ): array {
        $directory = self::capabilityDirectory($token, $targetIdentitySha256);
        $path = self::capabilityPath($token, $targetIdentitySha256);
        $consumedPath = null;

        try {
            clearstatcache(true, $directory);
            if (! file_exists($directory) && ! is_link($directory)) {
                throw new RuntimeException('one-use invocation capability artifact is missing after consumption claim');
            }
            self::assertSecureDirectory($directory);
            clearstatcache(true, $path);
            if (! file_exists($path) && ! is_link($path)) {
                throw new RuntimeException('one-use invocation capability artifact is missing after consumption claim');
            }
            self::assertSecureArtifact($path);
            $directoryIdentity = self::directoryIdentity($directory);
            $consumedPath = $directory.DIRECTORY_SEPARATOR.'consumed-'.bin2hex(random_bytes(16)).'.json';

            if (! @rename($path, $consumedPath)) {
                throw new RuntimeException('one-use invocation capability artifact could not be atomically consumed');
            }

            if ($directoryIdentity !== self::directoryIdentity($directory)) {
                throw new RuntimeException('private capability directory identity changed during consumption');
            }
            self::assertSecureArtifact($consumedPath);
            $rawPayload = file_get_contents($consumedPath);
            if (! is_string($rawPayload) || strlen($rawPayload) > 4096) {
                throw new RuntimeException('one-use invocation capability payload is unreadable or oversized');
            }

            $payload = json_decode($rawPayload, true, flags: JSON_THROW_ON_ERROR);
            $expectedKeys = [
                'version',
                'token_sha256',
                'rollback_plan_sha256',
                'target_identity_sha256',
                'expires_at',
                'issuance_id',
            ];
            $canonicalPayload = is_array($payload)
                ? self::canonicalJson($payload)
                : null;

            if (! is_array($payload)
                || array_keys($payload) !== $expectedKeys
                || ! is_string($canonicalPayload)
                || ! hash_equals($canonicalPayload, $rawPayload)
                || $payload['version'] !== self::DOWN_CAPABILITY_VERSION
                || ! is_string($payload['token_sha256'])
                || preg_match('/\A[0-9a-f]{64}\z/', $payload['token_sha256']) !== 1
                || ! hash_equals($issued['token_sha256'], $payload['token_sha256'])
                || ! is_string($payload['rollback_plan_sha256'])
                || preg_match('/\A[0-9a-f]{64}\z/', $payload['rollback_plan_sha256']) !== 1
                || ! hash_equals($issued['rollback_plan_sha256'], $payload['rollback_plan_sha256'])
                || ! is_string($payload['target_identity_sha256'])
                || preg_match('/\A[0-9a-f]{64}\z/', $payload['target_identity_sha256']) !== 1
                || ! hash_equals($issued['target_identity_sha256'], $payload['target_identity_sha256'])
                || ! is_int($payload['expires_at'] ?? null)
                || $payload['expires_at'] !== $issued['expires_at']
                || ! is_string($payload['issuance_id'])
                || preg_match('/\A[0-9a-f]{64}\z/', $payload['issuance_id']) !== 1
                || ! hash_equals($issued['issuance_id'], $payload['issuance_id'])) {
                throw new RuntimeException('one-use invocation capability is invalid or expired');
            }

            if (! @unlink($consumedPath)) {
                throw new RuntimeException('consumed one-use invocation capability could not be removed');
            }
            clearstatcache(true, $consumedPath);
            if (file_exists($consumedPath) || is_link($consumedPath)) {
                throw new RuntimeException('consumed one-use invocation capability still exists after removal');
            }

            return [
                'directory' => $directory,
                'directory_identity' => $directoryIdentity,
            ];
        } catch (Throwable $exception) {
            self::rejectCapabilityConsumption($token, $targetIdentitySha256, $exception);
        }
    }

    private static function assertAllowedRollbackSelectors(InputInterface $input): void
    {
        foreach (self::REJECTED_ROLLBACK_OPTIONS as $option) {
            if ($input->hasParameterOption($option, true)) {
                throw new RuntimeException(sprintf('rollback selector %s is not allowed', $option));
            }
        }
    }

    private static function withRollbackConnection(InputInterface $input, Closure $operation): void
    {
        $requested = $input->getOption('database');
        $connection = is_string($requested) && $requested !== '' ? $requested : null;

        app(Migrator::class)->usingConnection($connection, function () use ($operation): void {
            $operation(app(Migrator::class));
        });
    }

    private static function rollbackConnectionName(InputInterface $input): string
    {
        $requested = $input->getOption('database');

        return is_string($requested) && $requested !== ''
            ? $requested
            : DB::getDefaultConnection();
    }

    /** @return list<string> */
    private static function exactSelectedRollbackMigrations(Migrator $migrator): array
    {
        $selected = array_map(
            static fn (array|object $migration): string => (string) (
                is_array($migration) ? $migration['migration'] : $migration->migration
            ),
            $migrator->getRepository()->getLast(),
        );

        if ($selected !== self::DOWN_MIGRATIONS) {
            throw new RuntimeException(sprintf(
                'latest migration batch must be exactly the %d canonical Phase I migrations in reverse order',
                count(self::DOWN_MIGRATIONS),
            ));
        }

        return $selected;
    }

    /** @param list<string> $selectedMigrations */
    private static function assertCanonicalMigrationFilesAvailable(Migrator $migrator, array $selectedMigrations): void
    {
        $paths = array_values(array_unique([
            database_path('migrations'),
            ...$migrator->paths(),
        ]));
        $files = $migrator->getMigrationFiles($paths);

        foreach ($selectedMigrations as $migration) {
            if (! isset($files[$migration])) {
                throw new RuntimeException(sprintf('selected canonical migration file %s is unavailable', $migration));
            }
        }
    }

    private static function canonicalRollbackPlanSha256(): string
    {
        return hash('sha256', json_encode([
            'command' => self::DOWN_COMMAND,
            'reverse_migrations' => self::DOWN_MIGRATIONS,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array{
     *     version: string,
     *     driver: string,
     *     connection_name: string,
     *     server_uuid: string,
     *     database_name: string,
     *     target_identity_sha256: string
     * }
     */
    private static function canonicalTargetIdentity(string $connectionName): array
    {
        if ($connectionName === '' || str_contains($connectionName, "\0")) {
            throw new RuntimeException('Canonical rollback target connection name is invalid');
        }

        $connection = DB::connection($connectionName);
        $driver = $connection->getDriverName();
        if ($driver !== 'mysql') {
            throw new RuntimeException('Canonical rollback target must use MySQL');
        }

        $identity = $connection->selectOne('SELECT LOWER(@@server_uuid) AS server_uuid, DATABASE() AS database_name');
        $serverUuid = is_object($identity) ? ($identity->server_uuid ?? null) : null;
        $databaseName = is_object($identity) ? ($identity->database_name ?? null) : null;

        if (! is_string($serverUuid)
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $serverUuid) !== 1) {
            throw new RuntimeException('Canonical rollback target MySQL server UUID is invalid');
        }
        if (! is_string($databaseName) || $databaseName === '' || str_contains($databaseName, "\0")) {
            throw new RuntimeException('Canonical rollback target database name is invalid');
        }

        $targetIdentitySha256 = hash(
            'sha256',
            self::DOWN_CAPABILITY_TARGET_IDENTITY_DOMAIN
                ."\0".$driver
                ."\0".$connectionName
                ."\0".$serverUuid
                ."\0".$databaseName,
        );

        return [
            'version' => self::DOWN_CAPABILITY_TARGET_IDENTITY_VERSION,
            'driver' => $driver,
            'connection_name' => $connectionName,
            'server_uuid' => $serverUuid,
            'database_name' => $databaseName,
            'target_identity_sha256' => $targetIdentitySha256,
        ];
    }

    /** @param list<string> $selectedMigrations */
    private static function invocationRollbackPlanSha256(
        string $connection,
        string $database,
        string $targetIdentitySha256,
        array $selectedMigrations,
    ): string {
        return hash('sha256', json_encode([
            'canonical_plan_sha256' => self::canonicalRollbackPlanSha256(),
            'command' => self::DOWN_COMMAND,
            'connection' => $connection,
            'database' => $database,
            'target_identity_sha256' => $targetIdentitySha256,
            'selected_migrations' => $selectedMigrations,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private static function currentTimestamp(): int
    {
        return Carbon::now()->getTimestamp();
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
        $input = self::currentConsoleInputOrNull();
        if ($input !== null) {
            return $input;
        }

        throw self::downgradeRejected('destructive down must run inside one console command invocation');
    }

    private static function currentConsoleInputOrNull(): ?InputInterface
    {
        foreach (debug_backtrace(0, 64) as $frame) {
            foreach ($frame['args'] ?? [] as $argument) {
                if ($argument instanceof InputInterface) {
                    return $argument;
                }
            }
        }

        return null;
    }

    private static function discardSuppliedCapability(?string $connection = null): void
    {
        $token = getenv(self::DOWN_CAPABILITY_ENV);
        self::clearSuppliedCapabilityEnvironment();

        if (! is_string($token) || preg_match('/\A[0-9a-f]{64}\z/', $token) !== 1) {
            return;
        }

        self::revokeDestructiveDownCapability($token, $connection);
    }

    private static function clearSuppliedCapabilityEnvironment(): void
    {
        putenv(self::DOWN_CAPABILITY_ENV);
        unset($_ENV[self::DOWN_CAPABILITY_ENV], $_SERVER[self::DOWN_CAPABILITY_ENV]);
    }

    /**
     * @return array{
     *     version: string,
     *     token_sha256: string,
     *     rollback_plan_sha256: string,
     *     target_identity_sha256: string,
     *     expires_at: int,
     *     issuance_id: string,
     *     capability_key: string,
     *     issued_record_sha256: string
     * }
     */
    private static function loadAuthoritativeIssuedRecord(string $token, string $targetIdentitySha256): array
    {
        self::ensureCapabilityLedgerRoot();

        $tokenSha256 = hash('sha256', $token);
        $rollbackPlanSha256 = self::canonicalRollbackPlanSha256();
        $capabilityKey = self::capabilityLedgerKey(
            $tokenSha256,
            $rollbackPlanSha256,
            $targetIdentitySha256,
        );
        $path = self::capabilityIssuedLedgerPath(
            $tokenSha256,
            $rollbackPlanSha256,
            $targetIdentitySha256,
        );
        clearstatcache(true, $path);
        if (! file_exists($path) && ! is_link($path)) {
            throw new RuntimeException('authoritative issued ledger record is missing');
        }

        $raw = self::readSecureRecord($path);
        $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $expectedKeys = [
            'version',
            'token_sha256',
            'rollback_plan_sha256',
            'target_identity_sha256',
            'expires_at',
            'issuance_id',
        ];

        if (! is_array($payload)
            || array_keys($payload) !== $expectedKeys
            || ! hash_equals(self::canonicalJson($payload), $raw)
            || $payload['version'] !== self::DOWN_CAPABILITY_LEDGER_VERSION
            || ! is_string($payload['token_sha256'])
            || preg_match('/\A[0-9a-f]{64}\z/', $payload['token_sha256']) !== 1
            || ! hash_equals($tokenSha256, $payload['token_sha256'])
            || ! is_string($payload['rollback_plan_sha256'])
            || preg_match('/\A[0-9a-f]{64}\z/', $payload['rollback_plan_sha256']) !== 1
            || ! hash_equals($rollbackPlanSha256, $payload['rollback_plan_sha256'])
            || ! is_string($payload['target_identity_sha256'])
            || preg_match('/\A[0-9a-f]{64}\z/', $payload['target_identity_sha256']) !== 1
            || ! hash_equals($targetIdentitySha256, $payload['target_identity_sha256'])
            || ! is_int($payload['expires_at'] ?? null)
            || $payload['expires_at'] > self::currentTimestamp() + self::DOWN_CAPABILITY_TTL_SECONDS
            || ! is_string($payload['issuance_id'])
            || preg_match('/\A[0-9a-f]{64}\z/', $payload['issuance_id']) !== 1
            || ! hash_equals(
                $capabilityKey,
                pathinfo($path, PATHINFO_FILENAME),
            )) {
            throw new RuntimeException('authoritative issued ledger record is invalid');
        }

        return [
            ...$payload,
            'capability_key' => $capabilityKey,
            'issued_record_sha256' => hash('sha256', $raw),
        ];
    }

    /**
     * @param array{
     *     version: string,
     *     token_sha256: string,
     *     rollback_plan_sha256: string,
     *     target_identity_sha256: string,
     *     expires_at: int,
     *     issuance_id: string,
     *     capability_key: string,
     *     issued_record_sha256: string
     * } $issued
     */
    private static function claimCapabilitySpent(array $issued, string $reasonClass): bool
    {
        if (! in_array($reasonClass, self::DOWN_CAPABILITY_SPENT_REASONS, true)) {
            throw new RuntimeException('Capability spent reason class is not allowed');
        }

        self::ensureCapabilityLedgerRoot();
        $path = self::capabilitySpentLedgerPath($issued['capability_key']);
        $created = self::createExclusiveSecureFile($path, self::canonicalJson([
            'version' => self::DOWN_CAPABILITY_LEDGER_VERSION,
            'issued_record_sha256' => $issued['issued_record_sha256'],
            'spent_at' => self::currentTimestamp(),
            'reason_class' => $reasonClass,
        ]));

        if (! $created) {
            self::assertValidSpentRecord($path, $issued);
        }

        return $created;
    }

    /**
     * @param array{
     *     version: string,
     *     token_sha256: string,
     *     rollback_plan_sha256: string,
     *     target_identity_sha256: string,
     *     expires_at: int,
     *     issuance_id: string,
     *     capability_key: string,
     *     issued_record_sha256: string
     * } $issued
     */
    private static function assertValidSpentRecord(string $path, array $issued): void
    {
        $raw = self::readSecureRecord($path);
        $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $expectedKeys = ['version', 'issued_record_sha256', 'spent_at', 'reason_class'];

        if (! is_array($payload)
            || array_keys($payload) !== $expectedKeys
            || ! hash_equals(self::canonicalJson($payload), $raw)
            || $payload['version'] !== self::DOWN_CAPABILITY_LEDGER_VERSION
            || ! is_string($payload['issued_record_sha256'])
            || preg_match('/\A[0-9a-f]{64}\z/', $payload['issued_record_sha256']) !== 1
            || ! hash_equals($issued['issued_record_sha256'], $payload['issued_record_sha256'])
            || ! is_int($payload['spent_at'] ?? null)
            || $payload['spent_at'] > self::currentTimestamp() + self::DOWN_CAPABILITY_TTL_SECONDS
            || ! is_string($payload['reason_class'])
            || ! in_array($payload['reason_class'], self::DOWN_CAPABILITY_SPENT_REASONS, true)) {
            throw new RuntimeException('authoritative spent ledger record is invalid');
        }
    }

    private static function readSecureRecord(string $path): string
    {
        self::assertSecureArtifact($path);
        $raw = file_get_contents($path);
        if (! is_string($raw) || $raw === '' || strlen($raw) > 4096) {
            throw new RuntimeException('Capability ledger record is unreadable or oversized');
        }

        return $raw;
    }

    private static function createExclusiveSecureFile(string $path, string $payload): bool
    {
        self::assertSecureDirectory(dirname($path));
        $previousUmask = null;
        if (PHP_OS_FAMILY !== 'Windows') {
            $previousUmask = umask(0077);
        }

        try {
            $handle = @fopen($path, 'x+b');
        } finally {
            if ($previousUmask !== null) {
                umask($previousUmask);
            }
        }

        if ($handle === false) {
            clearstatcache(true, $path);
            if (! file_exists($path) && ! is_link($path)) {
                throw new RuntimeException('Unable to atomically create capability control-plane record');
            }
            self::assertSecureArtifact($path);

            return false;
        }

        try {
            self::assertSecureArtifact($path);
            $offset = 0;
            while ($offset < strlen($payload)) {
                $written = fwrite($handle, substr($payload, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Unable to persist capability control-plane record');
                }
                $offset += $written;
            }
            if (! fflush($handle)) {
                throw new RuntimeException('Unable to flush capability control-plane record');
            }
        } finally {
            fclose($handle);
        }

        self::assertSecureArtifact($path);

        return true;
    }

    /** @param array<string, mixed> $payload */
    private static function canonicalJson(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private static function capabilityLedgerKey(
        string $tokenSha256,
        string $rollbackPlanSha256,
        string $targetIdentitySha256,
    ): string {
        return hash(
            'sha256',
            self::DOWN_CAPABILITY_LEDGER_KEY_DOMAIN
                ."\0".$tokenSha256
                ."\0".$rollbackPlanSha256
                ."\0".$targetIdentitySha256,
        );
    }

    private static function capabilityIssuedLedgerPath(
        string $tokenSha256,
        string $rollbackPlanSha256,
        string $targetIdentitySha256,
    ): string {
        return self::capabilityLedgerRootPath().DIRECTORY_SEPARATOR
            .self::capabilityLedgerKey($tokenSha256, $rollbackPlanSha256, $targetIdentitySha256).'.issued';
    }

    private static function capabilityIssuedLedgerPathForToken(
        string $token,
        ?string $targetIdentitySha256 = null,
    ): string {
        return self::capabilityIssuedLedgerPath(
            hash('sha256', $token),
            self::canonicalRollbackPlanSha256(),
            $targetIdentitySha256 ?? self::canonicalTargetIdentity(DB::getDefaultConnection())['target_identity_sha256'],
        );
    }

    private static function capabilitySpentLedgerPath(string $capabilityKey): string
    {
        return self::capabilityLedgerRootPath().DIRECTORY_SEPARATOR.$capabilityKey.'.spent';
    }

    private static function capabilitySpentLedgerPathForToken(
        string $token,
        ?string $targetIdentitySha256 = null,
    ): string {
        return self::capabilitySpentLedgerPath(self::capabilityLedgerKey(
            hash('sha256', $token),
            self::canonicalRollbackPlanSha256(),
            $targetIdentitySha256 ?? self::canonicalTargetIdentity(DB::getDefaultConnection())['target_identity_sha256'],
        ));
    }

    private static function capabilityLedgerRootPath(): string
    {
        return rtrim(sys_get_temp_dir(), '\\/').DIRECTORY_SEPARATOR.self::DOWN_CAPABILITY_LEDGER_ROOT;
    }

    private static function capabilityPath(string $token, ?string $targetIdentitySha256 = null): string
    {
        return self::capabilityDirectory($token, $targetIdentitySha256).DIRECTORY_SEPARATOR.self::DOWN_CAPABILITY_FILE;
    }

    private static function capabilityDirectory(string $token, ?string $targetIdentitySha256 = null): string
    {
        $targetIdentitySha256 ??= self::canonicalTargetIdentity(
            DB::getDefaultConnection(),
        )['target_identity_sha256'];

        return self::capabilityRootPath().DIRECTORY_SEPARATOR
            .hash(
                'sha256',
                "canonical-supplier-snapshot-down-directory:v2\0".$token."\0".$targetIdentitySha256,
            );
    }

    private static function capabilityRootPath(): string
    {
        return rtrim(sys_get_temp_dir(), '\\/').DIRECTORY_SEPARATOR.self::DOWN_CAPABILITY_ROOT;
    }

    private static function ensureCapabilityRoot(): void
    {
        $root = self::capabilityRootPath();
        if (! file_exists($root) && ! is_link($root)) {
            try {
                self::createPrivateDirectory($root);
            } catch (Throwable $exception) {
                if (! file_exists($root) && ! is_link($root)) {
                    throw $exception;
                }
            }
        }

        self::assertSecureDirectory($root);
    }

    private static function ensureCapabilityLedgerRoot(): void
    {
        $root = self::capabilityLedgerRootPath();
        if (! file_exists($root) && ! is_link($root)) {
            try {
                self::createPrivateDirectory($root);
            } catch (Throwable $exception) {
                if (! file_exists($root) && ! is_link($root)) {
                    throw $exception;
                }
            }
        }

        self::assertSecureDirectory($root);
    }

    private static function createPrivateDirectory(string $path): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::createPrivateWindowsDirectory($path);

            return;
        }

        $previousUmask = umask(0077);
        try {
            if (! @mkdir($path, 0700)) {
                throw new RuntimeException('Unable to create private destructive down capability directory');
            }
        } finally {
            umask($previousUmask);
        }
    }

    private static function createPrivateWindowsDirectory(string $path): void
    {
        $script = <<<'POWERSHELL'
            $ErrorActionPreference = 'Stop'
            $path = [IO.Path]::GetFullPath([string] $env:MYCOMPUTER_PHASE_I_CAPABILITY_PATH)
            if ([IO.Directory]::Exists($path) -or [IO.File]::Exists($path)) {
                throw 'Capability directory already exists'
            }
            $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
            $security = New-Object Security.AccessControl.DirectorySecurity
            $security.SetOwner($identity.User)
            $security.SetAccessRuleProtection($true, $false)
            $rule = New-Object Security.AccessControl.FileSystemAccessRule(
                $identity.User,
                [Security.AccessControl.FileSystemRights]::FullControl,
                [Security.AccessControl.InheritanceFlags]'ContainerInherit, ObjectInherit',
                [Security.AccessControl.PropagationFlags]::None,
                [Security.AccessControl.AccessControlType]::Allow
            )
            [void] $security.AddAccessRule($rule)
            [void] [IO.Directory]::CreateDirectory($path, $security)
            POWERSHELL;

        self::runWindowsSecurityProcess($script, [$path]);
    }

    private static function assertSecureDirectory(string $path): void
    {
        self::assertExpectedPathType($path, true);

        if (PHP_OS_FAMILY === 'Windows') {
            self::assertSecureWindowsPath($path, true);

            return;
        }

        $stat = self::requiredLstat($path);
        if (($stat['mode'] & 0777) !== 0700) {
            throw new RuntimeException('Private capability directory permissions are not exactly 0700');
        }
        if (! function_exists('posix_geteuid') || $stat['uid'] !== posix_geteuid()) {
            throw new RuntimeException('Private capability directory owner cannot be proven');
        }
    }

    private static function assertSecureArtifact(string $path): void
    {
        self::assertExpectedPathType($path, false);
        $stat = self::requiredLstat($path);
        if (($stat['nlink'] ?? 0) !== 1) {
            throw new RuntimeException('Capability artifact has an unsafe hard-link count');
        }

        if (PHP_OS_FAMILY === 'Windows') {
            self::assertSecureWindowsPath($path, false);

            return;
        }

        if (($stat['mode'] & 0777) !== 0600) {
            throw new RuntimeException('Capability artifact permissions are not exactly 0600');
        }
        if (! function_exists('posix_geteuid') || $stat['uid'] !== posix_geteuid()) {
            throw new RuntimeException('Capability artifact owner cannot be proven');
        }
    }

    private static function assertExpectedPathType(string $path, bool $directory): void
    {
        clearstatcache(true, $path);
        if (is_link($path)) {
            throw new RuntimeException('Capability path must not be a symbolic link');
        }

        $stat = self::requiredLstat($path);
        $type = $stat['mode'] & 0170000;
        $expectedType = $directory ? 0040000 : 0100000;
        if ($type !== $expectedType || ($directory ? ! is_dir($path) : ! is_file($path))) {
            throw new RuntimeException($directory
                ? 'Capability directory is not a regular directory'
                : 'Capability artifact is not a regular file');
        }
    }

    /** @return array<string|int, int> */
    private static function requiredLstat(string $path): array
    {
        $stat = @lstat($path);
        if (! is_array($stat)) {
            throw new RuntimeException('Capability path metadata is unavailable');
        }

        return $stat;
    }

    private static function assertSecureWindowsPath(string $path, bool $directory): void
    {
        $script = <<<'POWERSHELL'
            $ErrorActionPreference = 'Stop'
            $path = [IO.Path]::GetFullPath([string] $env:MYCOMPUTER_PHASE_I_CAPABILITY_PATH)
            $isDirectory = [IO.Directory]::Exists($path)
            $isFile = [IO.File]::Exists($path)
            if (-not $isDirectory -and -not $isFile) {
                throw 'Capability path is missing'
            }
            $acl = $(if ($isDirectory) {
                [IO.Directory]::GetAccessControl($path)
            } else {
                [IO.File]::GetAccessControl($path)
            })
            $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
            $rules = @($acl.GetAccessRules($true, $true, [Security.Principal.SecurityIdentifier]) | ForEach-Object {
                [ordered]@{
                    sid = $_.IdentityReference.Value
                    type = $_.AccessControlType.ToString()
                    rights = $_.FileSystemRights.ToString()
                    inherited = [bool] $_.IsInherited
                }
            })
            [ordered]@{
                path = $path
                type = $(if ($isDirectory) { 'directory' } else { 'file' })
                reparse = [bool] (([IO.File]::GetAttributes($path) -band [IO.FileAttributes]::ReparsePoint) -ne 0)
                owner_sid = $acl.GetOwner([Security.Principal.SecurityIdentifier]).Value
                current_sid = $identity.User.Value
                protected = [bool] $acl.AreAccessRulesProtected
                rules = $rules
            } | ConvertTo-Json -Compress -Depth 4
            POWERSHELL;

        $raw = self::runWindowsSecurityProcess($script, [$path]);
        $security = json_decode(trim($raw), true, flags: JSON_THROW_ON_ERROR);
        $expectedType = $directory ? 'directory' : 'file';
        $rules = is_array($security['rules'] ?? null) ? $security['rules'] : [];

        if (($security['type'] ?? null) !== $expectedType
            || ($security['reparse'] ?? true) !== false
            || ! is_string($security['current_sid'] ?? null)
            || ($security['owner_sid'] ?? null) !== $security['current_sid']
            || ($directory && ($security['protected'] ?? false) !== true)
            || count($rules) !== 1
            || ($rules[0]['sid'] ?? null) !== $security['current_sid']
            || ($rules[0]['type'] ?? null) !== 'Allow'
            || ($rules[0]['rights'] ?? null) !== 'FullControl'
            || ($directory && ($rules[0]['inherited'] ?? true) !== false)) {
            throw new RuntimeException('Windows capability ACL, owner, or reparse-point proof failed');
        }
    }

    /** @param list<string> $arguments */
    private static function runWindowsSecurityProcess(string $script, array $arguments): string
    {
        $systemRoot = getenv('SystemRoot');
        if (! is_string($systemRoot) || $systemRoot === '') {
            throw new RuntimeException('Windows system root is unavailable for ACL enforcement');
        }

        $powerShell = $systemRoot.'\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
        if (! is_file($powerShell)) {
            throw new RuntimeException('Windows ACL enforcement tool is unavailable');
        }

        if (count($arguments) !== 1) {
            throw new RuntimeException('Windows ACL operation requires exactly one path');
        }

        $process = new Process([
            $powerShell,
            '-NoLogo',
            '-NoProfile',
            '-NonInteractive',
            '-ExecutionPolicy',
            'Bypass',
            '-Command',
            $script,
        ], env: ['MYCOMPUTER_PHASE_I_CAPABILITY_PATH' => $arguments[0]]);
        $process->setTimeout(10);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Windows ACL enforcement or validation failed closed');
        }

        return $process->getOutput();
    }

    private static function secureWindowsDirectoryForCleanup(string $path): void
    {
        $script = <<<'POWERSHELL'
            $ErrorActionPreference = 'Stop'
            $path = [IO.Path]::GetFullPath([string] $env:MYCOMPUTER_PHASE_I_CAPABILITY_PATH)
            if (-not [IO.Directory]::Exists($path)) {
                throw 'Capability cleanup path is not a directory'
            }
            if (([IO.File]::GetAttributes($path) -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
                throw 'Capability cleanup directory is a reparse point'
            }
            $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
            $security = New-Object Security.AccessControl.DirectorySecurity
            $security.SetOwner($identity.User)
            $security.SetAccessRuleProtection($true, $false)
            $rule = New-Object Security.AccessControl.FileSystemAccessRule(
                $identity.User,
                [Security.AccessControl.FileSystemRights]::FullControl,
                [Security.AccessControl.InheritanceFlags]'ContainerInherit, ObjectInherit',
                [Security.AccessControl.PropagationFlags]::None,
                [Security.AccessControl.AccessControlType]::Allow
            )
            [void] $security.AddAccessRule($rule)
            [IO.Directory]::SetAccessControl($path, $security)
            POWERSHELL;

        self::runWindowsSecurityProcess($script, [$path]);
        self::assertSecureDirectory($path);
    }

    private static function windowsPathIsReparsePoint(string $path): bool
    {
        $script = <<<'POWERSHELL'
            $ErrorActionPreference = 'Stop'
            $path = [IO.Path]::GetFullPath([string] $env:MYCOMPUTER_PHASE_I_CAPABILITY_PATH)
            if (-not [IO.Directory]::Exists($path) -and -not [IO.File]::Exists($path)) {
                throw 'Capability cleanup path is missing'
            }
            if (([IO.File]::GetAttributes($path) -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
                [Console]::Out.Write('1')
            } else {
                [Console]::Out.Write('0')
            }
            POWERSHELL;

        $result = trim(self::runWindowsSecurityProcess($script, [$path]));
        if (! in_array($result, ['0', '1'], true)) {
            throw new RuntimeException('Windows capability reparse-point observation failed closed');
        }

        return $result === '1';
    }

    private static function directoryIdentity(string $path): string
    {
        self::assertSecureDirectory($path);
        $stat = self::requiredLstat($path);

        return hash('sha256', json_encode([
            'path' => self::normalizedAbsolutePath($path),
            'device' => $stat['dev'],
            'inode' => $stat['ino'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private static function normalizedAbsolutePath(string $path): string
    {
        $resolved = realpath($path);
        if (! is_string($resolved)) {
            throw new RuntimeException('Capability path cannot be resolved');
        }

        return PHP_OS_FAMILY === 'Windows' ? strtolower(str_replace('\\', '/', $resolved)) : $resolved;
    }

    private static function removeCapabilityArtifact(string $token, string $targetIdentitySha256): void
    {
        self::removeCapabilityDirectory(self::capabilityDirectory($token, $targetIdentitySha256));
    }

    private static function rejectCapabilityConsumption(
        string $token,
        string $targetIdentitySha256,
        Throwable $rejection,
    ): never {
        try {
            self::removeCapabilityArtifact($token, $targetIdentitySha256);
        } catch (Throwable $cleanupFailure) {
            throw new RuntimeException(
                'one-use invocation capability cleanup integrity failure: '.$cleanupFailure->getMessage(),
                0,
                $rejection,
            );
        }

        throw $rejection;
    }

    private static function removeConsumedCapabilityDirectory(string $directory, string $expectedIdentity): void
    {
        self::removeCapabilityDirectory($directory, $expectedIdentity);
    }

    private static function removeCapabilityDirectory(string $directory, ?string $expectedIdentity = null): void
    {
        clearstatcache(true, $directory);
        if (! file_exists($directory) && ! is_link($directory)) {
            return;
        }
        self::assertCapabilityCleanupPath($directory);

        if (is_link($directory)) {
            $removed = PHP_OS_FAMILY === 'Windows' && is_dir($directory)
                ? @rmdir($directory)
                : @unlink($directory);
            if (! $removed) {
                throw new RuntimeException('Capability cleanup could not remove the private directory link');
            }
            self::assertCapabilityPathAbsent($directory);

            return;
        }

        if (PHP_OS_FAMILY === 'Windows' && self::windowsPathIsReparsePoint($directory)) {
            if (! @rmdir($directory)) {
                throw new RuntimeException('Capability cleanup could not remove the private directory reparse point');
            }
            self::assertCapabilityPathAbsent($directory);

            return;
        }

        self::assertExpectedPathType($directory, true);
        $stat = self::requiredLstat($directory);
        if (PHP_OS_FAMILY === 'Windows') {
            self::secureWindowsDirectoryForCleanup($directory);
        } else {
            if (! function_exists('posix_geteuid') || $stat['uid'] !== posix_geteuid()) {
                throw new RuntimeException('Capability cleanup directory owner cannot be proven');
            }
            if (! @chmod($directory, 0700)) {
                throw new RuntimeException('Capability cleanup could not restore owner-only directory permissions');
            }
            self::assertSecureDirectory($directory);
        }

        if ($expectedIdentity !== null && ! hash_equals($expectedIdentity, self::directoryIdentity($directory))) {
            throw new RuntimeException('Capability cleanup directory identity changed');
        }

        $entries = @scandir($directory);
        if (! is_array($entries)) {
            throw new RuntimeException('Capability cleanup directory cannot be enumerated');
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if ($entry !== self::DOWN_CAPABILITY_FILE
                && preg_match('/\Aconsumed-[0-9a-f]{32}\.json\z/', $entry) !== 1) {
                throw new RuntimeException('Capability cleanup directory contains an unexpected entry');
            }
        }

        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeKnownCapabilityEntry($directory.DIRECTORY_SEPARATOR.$entry);
            }
        }

        if (! @rmdir($directory)) {
            throw new RuntimeException('Capability cleanup could not remove the private directory');
        }
        self::assertCapabilityPathAbsent($directory);
    }

    private static function assertCapabilityCleanupPath(string $directory): void
    {
        $root = self::capabilityRootPath();
        self::assertSecureDirectory($root);
        $rootPath = rtrim(str_replace('\\', '/', self::normalizedAbsolutePath($root)), '/');
        $parent = realpath(dirname($directory));
        if (! is_string($parent)) {
            throw new RuntimeException('Capability cleanup parent cannot be resolved');
        }
        $parentPath = rtrim(str_replace('\\', '/', PHP_OS_FAMILY === 'Windows' ? strtolower($parent) : $parent), '/');

        if (! hash_equals($rootPath, $parentPath)
            || preg_match('/\A[0-9a-f]{64}\z/', basename($directory)) !== 1) {
            throw new RuntimeException('Capability cleanup path is outside the private capability namespace');
        }
    }

    private static function removeKnownCapabilityEntry(string $path): void
    {
        clearstatcache(true, $path);
        if (! file_exists($path) && ! is_link($path)) {
            return;
        }

        if (is_link($path)) {
            $removed = PHP_OS_FAMILY === 'Windows' && is_dir($path) ? @rmdir($path) : @unlink($path);
        } elseif (PHP_OS_FAMILY === 'Windows' && self::windowsPathIsReparsePoint($path)) {
            $removed = is_dir($path) ? @rmdir($path) : @unlink($path);
        } elseif (is_dir($path)) {
            $removed = @rmdir($path);
        } else {
            $removed = @unlink($path);
        }

        if (! $removed) {
            throw new RuntimeException('Capability cleanup could not remove a known issuance entry');
        }
        self::assertCapabilityPathAbsent($path);
    }

    private static function assertCapabilityPathAbsent(string $path): void
    {
        clearstatcache(true, $path);
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('Capability cleanup path still exists after removal');
        }
    }

    private static function invalidateDestructiveDownScope(): void
    {
        $scope = self::$destructiveDownScope;
        self::$destructiveDownScope = null;

        if (is_array($scope)) {
            self::removeConsumedCapabilityDirectory(
                $scope['capability_directory'],
                $scope['capability_directory_identity'],
            );
        }
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

<?php

namespace Database\Migrations\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

require_once __DIR__.'/CanonicalSupplierPhaseThreeP0Oracle.php';
require_once __DIR__.'/CanonicalSupplierPhaseThreeP0ConnectionOutcome.php';
require_once __DIR__.'/CanonicalSupplierPhaseThreeP0NamedLockResult.php';
require_once __DIR__.'/CanonicalSupplierPhaseThreeP0SchemaComparator.php';
require_once __DIR__.'/CanonicalSupplierPhaseThreeP0SchemaException.php';
require_once __DIR__.'/CanonicalSupplierPhaseThreeP0SchemaInspector.php';
require_once __DIR__.'/P0MigrationStep.php';

final class CanonicalSupplierPhaseThreeP0Schema
{
    private const GUARD_NAME = 'mycomputer:phase-iii-p0-schema-ddl-v1';

    private const DOWN_CONFIRMATION_ENV = 'SUPPLIER_PHASE_THREE_P0_EMPTY_SCHEMA_DOWN_CONFIRMED';

    private const TRIGGER_SQL_MODE = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    private const DEDICATED_CONNECTION = 'phase_three_p0_schema_ddl';

    /** @var list<string> */
    private const PROTECTED_GATES = [
        'supplier_snapshot_capture.capture_enabled',
        'supplier_snapshot_capture.protected_generation_admission_enabled',
        'supplier_snapshot_capture.recovery_issuance_enabled',
        'supplier_snapshot_capture.recovery_execution_enabled',
        'supplier_snapshot_capture.monitor_schedule_enabled',
        'supplier_snapshot_capture.observer_schedule_enabled',
        'supplier_snapshot_capture.alert_delivery_enabled',
    ];

    /** @var array<string, array{predecessor: string, target: string}> */
    private const FORWARD_STATES = [
        'P0-01' => ['predecessor' => 'P0', 'target' => 'P1'],
        'P0-02' => ['predecessor' => 'P1', 'target' => 'P2'],
        'P0-03' => ['predecessor' => 'P2', 'target' => 'P3'],
    ];

    /** @var array<string, array{initial: string, target: string, operation: string}> */
    private const DOWN_STATES = [
        'P0-01' => ['initial' => 'P1', 'target' => 'P0', 'operation' => 'P0-01-DOWN-01'],
        'P0-02' => ['initial' => 'P2', 'target' => 'P1', 'operation' => 'P0-02-DOWN-01'],
        'P0-03' => ['initial' => 'P3', 'target' => 'P2', 'operation' => 'P0-03-DOWN-01'],
    ];

    private static bool $active = false;

    public static function isMySql(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }

    public static function runForwardStep(P0MigrationStep $step): void
    {
        $states = self::FORWARD_STATES[$step->value] ?? null;
        if ($states === null) {
            throw new RuntimeException('phase_three_p0_forward_step_not_implemented');
        }

        self::assertProtectedGatesDisabled();
        self::withGuard(function (PDO $pdo) use ($step, $states): void {
            self::assertState($pdo, $states['predecessor'], 'phase_three_p0_forward_precondition_failed');

            match ($step) {
                P0MigrationStep::P0_01 => self::executeP0_01Forward($pdo),
                P0MigrationStep::P0_02 => self::executeP0_02Forward($pdo),
                P0MigrationStep::P0_03 => self::executeP0_03Forward($pdo),
                default => throw new RuntimeException('phase_three_p0_forward_step_not_implemented'),
            };

            self::assertState($pdo, $states['target'], 'phase_three_p0_forward_postcondition_failed');
        });
    }

    public static function runTerminalDowngradeStep(P0MigrationStep $step): void
    {
        $states = self::DOWN_STATES[$step->value] ?? null;
        $invocationId = (string) Str::uuid();
        $evidence = self::initialEvidence($invocationId, $step, $states['target'] ?? null);
        $primaryFailure = null;

        try {
            if ($states === null) {
                self::fail('phase_three_p0_downgrade_step_not_implemented', $invocationId);
            }

            self::assertDestructiveEnvironment($invocationId);
            self::assertProtectedGatesDisabled($invocationId);

            self::withGuard(function (PDO $pdo) use ($step, $states, $invocationId, &$evidence): void {
                $evidence['mutex_acquire'] = 'ACQUIRED';
                $classification = self::classifyForDowngrade(
                    $pdo,
                    $invocationId,
                    $evidence,
                    afterDdl: false,
                );
                $evidence['initial_state'] = $classification['state'];
                $evidence['last_verified_state'] = $classification['state'];
                $evidence['partial_state'] = str_contains($classification['state'], '_DOWN_')
                    ? $classification['state']
                    : null;

                if ($classification['state'] === CanonicalSupplierPhaseThreeP0SchemaComparator::UNCLASSIFIED_STATE) {
                    self::fail('UNCLASSIFIED_P0_SCHEMA_STATE', $invocationId);
                }

                if ($classification['state'] !== $states['initial']) {
                    self::fail('phase_three_p0_step_not_terminal', $invocationId);
                }

                $appendOnlyTable = match ($step) {
                    P0MigrationStep::P0_02 => 'supplier_import_source_profiles',
                    P0MigrationStep::P0_03 => 'supplier_import_source_executions',
                    default => null,
                };

                if ($appendOnlyTable !== null && self::tableHasRowsForDowngrade(
                    $pdo,
                    $appendOnlyTable,
                    $invocationId,
                    $evidence,
                )) {
                    self::fail('phase_three_p0_append_only_table_not_pristine', $invocationId);
                }

                $operation = CanonicalSupplierPhaseThreeP0Oracle::operation($states['operation']);
                self::assertConnectionLiveBeforeDdl($pdo, $invocationId, $evidence);
                try {
                    $pdo->exec($operation['sql']);
                } catch (PDOException $exception) {
                    $code = CanonicalSupplierPhaseThreeP0ConnectionOutcome::isUncertain($exception)
                        ? 'phase_three_p0_connection_outcome_unknown'
                        : 'phase_three_p0_downgrade_ddl_failed';
                    $evidence['connection_status'] = CanonicalSupplierPhaseThreeP0ConnectionOutcome::isUncertain($exception)
                        ? 'UNCERTAIN'
                        : 'CONNECTED';
                    self::fail($code, $invocationId);
                }

                $evidence['completed_ddl_ids'][] = $operation['operation_id'];
                $postcondition = self::classifyForDowngrade(
                    $pdo,
                    $invocationId,
                    $evidence,
                    afterDdl: true,
                );
                $evidence['last_verified_state'] = $postcondition['state'];

                if ($postcondition['state'] !== $states['target']) {
                    self::fail('phase_three_p0_downgrade_postcondition_failed', $invocationId);
                }

                $evidence['primary_outcome'] = 'phase_three_p0_downgrade_succeeded';
            }, $invocationId, $evidence);
        } catch (CanonicalSupplierPhaseThreeP0SchemaException $exception) {
            $primaryFailure = $exception;
            $evidence['primary_outcome'] = $exception->primaryCode;
        } finally {
            Log::info('phase_three_p0_downgrade_evidence_v1', $evidence);
        }

        if ($primaryFailure !== null) {
            throw $primaryFailure;
        }
    }

    /** @return array{state: string, classification: string, sha256: ?string, object_count: int} */
    public static function classify(PDO $pdo): array
    {
        CanonicalSupplierPhaseThreeP0Oracle::assertIntegrity();
        $inspection = (new CanonicalSupplierPhaseThreeP0SchemaInspector($pdo))->inspect();

        return (new CanonicalSupplierPhaseThreeP0SchemaComparator)->classifyObserved(
            $inspection['raw_signatures'],
            $inspection['schema_charset'],
            $inspection['schema_default_collation'],
        );
    }

    private static function executeP0_01Forward(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            ALTER TABLE `supplier_feeds`
              ADD UNIQUE KEY `uq_supplier_feed_id_supplier` (`id`, `supplier_id`)
            SQL);
    }

    private static function executeP0_02Forward(PDO $pdo): void
    {
        self::withCanonicalP0Session($pdo, function (PDO $pdo): void {
            $pdo->exec(<<<'SQL'
                CREATE TABLE `supplier_import_source_profiles` (
                  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                  `supplier_id` bigint unsigned NOT NULL,
                  `supplier_feed_id` bigint unsigned NOT NULL,
                  `source_identity` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                  `descriptor_version` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                  `source_locator_contract_key` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                  `source_locator_contract_version` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                  `source_locator_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                  `source_locator_canonical_bytes` mediumblob NOT NULL,
                  `source_access_scope_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                  `feed_type` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                  `importer_key` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                  `importer_version` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                  `mapping_contract_version` varchar(35) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                  `mapping_canonical_bytes` mediumblob NOT NULL,
                  `mapping_contract_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                  `source_descriptor_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                  `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `uq_import_source_profile_descriptor` (`supplier_id`, `supplier_feed_id`, `source_descriptor_fingerprint`),
                  UNIQUE KEY `uq_import_source_profile_identity` (`source_identity`),
                  UNIQUE KEY `uq_import_source_profile_scope` (`id`, `supplier_id`, `supplier_feed_id`, `source_identity`, `source_descriptor_fingerprint`),
                  KEY `ix_import_source_profile_feed_owner_fk` (`supplier_feed_id`, `supplier_id`),
                  CONSTRAINT `fk_import_source_profile_feed_owner` FOREIGN KEY (`supplier_feed_id`, `supplier_id`) REFERENCES `supplier_feeds` (`id`, `supplier_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
                  CONSTRAINT `chk_import_source_profile_descriptor_version` CHECK ((`descriptor_version` = _ascii'supplier_import_source_profile_v1')),
                  CONSTRAINT `chk_import_source_profile_source_identity` CHECK (((length(`source_identity`) = 59) and regexp_like(`source_identity`,_ascii'^snapshot-source-v1:profile:[0-9a-f]{32}$',_cp866'c'))),
                  CONSTRAINT `chk_import_source_profile_locator_key` CHECK (((length(`source_locator_key`) = 89) and regexp_like(`source_locator_key`,_ascii'^source-locator-v1:sha256:[0-9a-f]{64}$',_cp866'c'))),
                  CONSTRAINT `chk_import_source_profile_access_scope` CHECK (((length(`source_access_scope_key`) between 18 and 128) and regexp_like(`source_access_scope_key`,_ascii'^source-access-v1:[a-z0-9]+([._-][a-z0-9]+)*(:[a-z0-9]+([._-][a-z0-9]+)*)*$',_cp866'c'))),
                  CONSTRAINT `chk_import_source_profile_feed_type` CHECK ((`feed_type` in (_ascii'xml',_ascii'csv'))),
                  CONSTRAINT `chk_import_source_profile_fingerprints` CHECK (((length(`mapping_contract_fingerprint`) = 64) and regexp_like(`mapping_contract_fingerprint`,_ascii'^[0-9a-f]{64}$',_cp866'c') and (length(`source_descriptor_fingerprint`) = 64) and regexp_like(`source_descriptor_fingerprint`,_ascii'^[0-9a-f]{64}$',_cp866'c')))
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='mycomputer:phase-iii-p0:v1:owner=P0-02'
                SQL);
            $pdo->exec(<<<'SQL'
                CREATE TRIGGER `trg_import_source_profile_no_update`
                BEFORE UPDATE ON `supplier_import_source_profiles`
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable source profile cannot be updated'
                SQL);
            $pdo->exec(<<<'SQL'
                CREATE TRIGGER `trg_import_source_profile_no_delete`
                BEFORE DELETE ON `supplier_import_source_profiles`
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable source profile cannot be deleted'
                SQL);
        });
    }

    private static function executeP0_03Forward(PDO $pdo): void
    {
        self::withCanonicalP0Session($pdo, function (PDO $pdo): void {
            $pdo->exec(<<<'SQL'
                CREATE TABLE `supplier_import_source_executions` (
                  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                  `supplier_id` bigint unsigned NOT NULL,
                  `supplier_feed_id` bigint unsigned NOT NULL,
                  `import_job_id` bigint unsigned NOT NULL,
                  `import_history_id` bigint unsigned NOT NULL,
                  `supplier_import_source_profile_id` bigint unsigned NOT NULL,
                  `source_identity` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                  `source_descriptor_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                  `importer_key` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                  `importer_version` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                  `import_job_identity_version` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                  `import_job_identity_canonical_bytes` mediumblob NOT NULL,
                  `import_job_identity_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                  `resolved_source_context_version` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                  `captured_at` timestamp(6) NOT NULL,
                  `source_execution_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                  `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `uq_import_source_execution_fingerprint` (`source_execution_fingerprint`),
                  UNIQUE KEY `uq_import_source_execution_history` (`import_history_id`),
                  UNIQUE KEY `uq_import_source_execution_scope` (`id`, `supplier_id`, `supplier_feed_id`, `source_identity`, `source_descriptor_fingerprint`, `source_execution_fingerprint`),
                  UNIQUE KEY `uq_import_source_execution_receipt_scope` (`id`, `source_execution_fingerprint`),
                  KEY `ix_import_source_execution_profile_fk` (`supplier_import_source_profile_id`, `supplier_id`, `supplier_feed_id`, `source_identity`, `source_descriptor_fingerprint`),
                  KEY `ix_import_source_execution_feed_owner_fk` (`supplier_feed_id`, `supplier_id`),
                  KEY `ix_import_source_execution_job_scope_fk` (`import_job_id`, `supplier_id`, `supplier_feed_id`),
                  CONSTRAINT `fk_import_source_execution_profile` FOREIGN KEY (`supplier_import_source_profile_id`, `supplier_id`, `supplier_feed_id`, `source_identity`, `source_descriptor_fingerprint`) REFERENCES `supplier_import_source_profiles` (`id`, `supplier_id`, `supplier_feed_id`, `source_identity`, `source_descriptor_fingerprint`) ON UPDATE RESTRICT ON DELETE RESTRICT,
                  CONSTRAINT `fk_import_source_execution_feed_owner` FOREIGN KEY (`supplier_feed_id`, `supplier_id`) REFERENCES `supplier_feeds` (`id`, `supplier_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
                  CONSTRAINT `fk_import_source_execution_job_scope` FOREIGN KEY (`import_job_id`, `supplier_id`, `supplier_feed_id`) REFERENCES `import_jobs` (`id`, `supplier_id`, `supplier_feed_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
                  CONSTRAINT `fk_import_source_execution_history` FOREIGN KEY (`import_history_id`) REFERENCES `import_histories` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
                  CONSTRAINT `chk_import_source_execution_versions` CHECK (((`import_job_identity_version` = _ascii'supplier_import_job_identity_v1') and (`resolved_source_context_version` = _ascii'supplier_import_resolved_source_context_v1'))),
                  CONSTRAINT `chk_import_source_execution_fingerprints` CHECK (((length(`source_identity`) between 1 and 128) and regexp_like(`source_identity`,_ascii'^snapshot-source-v1:[a-z0-9]+([._-][a-z0-9]+)*(:[a-z0-9]+([._-][a-z0-9]+)*)*$',_cp866'c') and (length(`source_descriptor_fingerprint`) = 64) and regexp_like(`source_descriptor_fingerprint`,_ascii'^[0-9a-f]{64}$',_cp866'c') and (length(`import_job_identity_fingerprint`) = 64) and regexp_like(`import_job_identity_fingerprint`,_ascii'^[0-9a-f]{64}$',_cp866'c') and (length(`source_execution_fingerprint`) = 64) and regexp_like(`source_execution_fingerprint`,_ascii'^[0-9a-f]{64}$',_cp866'c')))
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='mycomputer:phase-iii-p0:v1:owner=P0-03'
                SQL);
            $pdo->exec(<<<'SQL'
                CREATE TRIGGER `trg_import_source_execution_no_update`
                BEFORE UPDATE ON `supplier_import_source_executions`
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable source execution cannot be updated'
                SQL);
            $pdo->exec(<<<'SQL'
                CREATE TRIGGER `trg_import_source_execution_no_delete`
                BEFORE DELETE ON `supplier_import_source_executions`
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable source execution cannot be deleted'
                SQL);
        });
    }

    private static function assertState(PDO $pdo, string $expected, string $error): void
    {
        try {
            $state = self::classify($pdo)['state'];
        } catch (Throwable) {
            throw new RuntimeException('phase_three_p0_schema_inspection_unavailable');
        }

        if ($state !== $expected) {
            throw new RuntimeException($error);
        }
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array{state: string, classification: string, sha256: ?string, object_count: int}
     */
    private static function classifyForDowngrade(
        PDO $pdo,
        string $invocationId,
        array &$evidence,
        bool $afterDdl,
    ): array {
        try {
            return self::classify($pdo);
        } catch (Throwable $exception) {
            $uncertain = $exception instanceof PDOException
                && CanonicalSupplierPhaseThreeP0ConnectionOutcome::isUncertain($exception);
            $evidence['connection_status'] = $uncertain ? 'UNCERTAIN' : 'CONNECTED';

            self::fail(
                $afterDdl && $uncertain
                    ? 'phase_three_p0_connection_outcome_unknown'
                    : 'phase_three_p0_schema_inspection_unavailable',
                $invocationId,
            );
        }
    }

    /** @param array<string, mixed> $evidence */
    private static function assertConnectionLiveBeforeDdl(
        PDO $pdo,
        string $invocationId,
        array &$evidence,
    ): void {
        try {
            if ($pdo->query('SELECT 1')->fetchColumn() !== 1) {
                $evidence['connection_status'] = 'UNCERTAIN';
                self::fail('phase_three_p0_connection_lost_pre_ddl', $invocationId);
            }
        } catch (CanonicalSupplierPhaseThreeP0SchemaException $exception) {
            throw $exception;
        } catch (Throwable) {
            $evidence['connection_status'] = 'UNCERTAIN';
            self::fail('phase_three_p0_connection_lost_pre_ddl', $invocationId);
        }
    }

    /**
     * @param  callable(PDO): void  $operation
     * @param  array<string, mixed>|null  $evidence
     */
    private static function withGuard(
        callable $operation,
        ?string $invocationId = null,
        ?array &$evidence = null,
    ): void {
        if (self::$active) {
            if ($invocationId !== null) {
                self::fail('phase_three_p0_nested_downgrade_forbidden', $invocationId);
            }

            throw new RuntimeException('phase_three_p0_nested_downgrade_forbidden');
        }

        if (! self::isMySql()) {
            throw new RuntimeException('phase_three_p0_mysql_8_4_required');
        }

        self::$active = true;
        $acquired = false;
        $failure = null;
        $sourceConnection = DB::getDefaultConnection();
        $sourceConfig = config("database.connections.{$sourceConnection}");

        if (! is_array($sourceConfig)) {
            self::$active = false;

            if ($invocationId !== null) {
                self::fail('phase_three_p0_schema_guard_unavailable', $invocationId);
            }

            throw new RuntimeException('phase_three_p0_schema_guard_unavailable');
        }

        config()->set('database.connections.'.self::DEDICATED_CONNECTION, $sourceConfig);
        DB::purge(self::DEDICATED_CONNECTION);

        try {
            $pdo = DB::connection(self::DEDICATED_CONNECTION)->getPdo();
        } catch (Throwable) {
            DB::purge(self::DEDICATED_CONNECTION);
            config()->set('database.connections.'.self::DEDICATED_CONNECTION, null);
            self::$active = false;

            if ($evidence !== null) {
                $evidence['mutex_acquire'] = CanonicalSupplierPhaseThreeP0NamedLockResult::UNAVAILABLE;
            }

            if ($invocationId !== null) {
                self::fail('phase_three_p0_schema_guard_unavailable', $invocationId);
            }

            throw new RuntimeException('phase_three_p0_schema_guard_unavailable');
        }

        try {
            try {
                $result = $pdo->query(
                    "SELECT GET_LOCK('".self::GUARD_NAME."', 0)",
                )->fetchColumn();
            } catch (Throwable) {
                if ($evidence !== null) {
                    $evidence['mutex_acquire'] = CanonicalSupplierPhaseThreeP0NamedLockResult::UNAVAILABLE;
                }

                if ($invocationId !== null) {
                    self::fail('phase_three_p0_schema_guard_unavailable', $invocationId);
                }

                throw new RuntimeException('phase_three_p0_schema_guard_unavailable');
            }

            if (CanonicalSupplierPhaseThreeP0NamedLockResult::acquisition($result)
                !== CanonicalSupplierPhaseThreeP0NamedLockResult::ACQUIRED) {
                if ($evidence !== null) {
                    $evidence['mutex_acquire'] = CanonicalSupplierPhaseThreeP0NamedLockResult::UNAVAILABLE;
                }

                if ($invocationId !== null) {
                    self::fail('phase_three_p0_schema_guard_unavailable', $invocationId);
                }

                throw new RuntimeException('phase_three_p0_schema_guard_unavailable');
            }

            $acquired = true;
            $operation($pdo);
        } catch (Throwable $exception) {
            $failure = $exception;
        } finally {
            if ($acquired) {
                try {
                    $release = $pdo->query(
                        "SELECT RELEASE_LOCK('".self::GUARD_NAME."')",
                    )->fetchColumn();

                    $releaseResult = CanonicalSupplierPhaseThreeP0NamedLockResult::release($release);

                    if ($evidence !== null) {
                        if ($releaseResult === CanonicalSupplierPhaseThreeP0NamedLockResult::RELEASED) {
                            $evidence['mutex_release'] = CanonicalSupplierPhaseThreeP0NamedLockResult::RELEASED;
                        } elseif ($releaseResult === CanonicalSupplierPhaseThreeP0NamedLockResult::NOT_OWNED) {
                            $evidence['mutex_release'] = CanonicalSupplierPhaseThreeP0NamedLockResult::NOT_OWNED;
                            $evidence['secondary_codes'][] = 'phase_three_p0_schema_guard_release_not_owned';
                        } else {
                            $evidence['mutex_release'] = CanonicalSupplierPhaseThreeP0NamedLockResult::UNAVAILABLE;
                            $evidence['secondary_codes'][] = 'phase_three_p0_schema_guard_release_unavailable';
                        }
                    } elseif ($releaseResult !== CanonicalSupplierPhaseThreeP0NamedLockResult::RELEASED) {
                        throw new RuntimeException('phase_three_p0_schema_guard_release_failed');
                    }
                } catch (Throwable) {
                    if ($evidence !== null) {
                        $evidence['mutex_release'] = 'UNAVAILABLE';
                        $evidence['secondary_codes'][] = 'phase_three_p0_schema_guard_release_unavailable';
                    } elseif ($failure === null) {
                        $failure = new RuntimeException('phase_three_p0_schema_guard_release_failed');
                    }
                }
            }

            self::$active = false;
            DB::purge(self::DEDICATED_CONNECTION);
            config()->set('database.connections.'.self::DEDICATED_CONNECTION, null);
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    /** @param callable(PDO): void $operation */
    private static function withCanonicalP0Session(PDO $pdo, callable $operation): void
    {
        $settings = $pdo->query(<<<'SQL'
            SELECT @@SESSION.sql_mode AS sql_mode,
                   @@SESSION.character_set_client AS character_set_client,
                   @@SESSION.character_set_results AS character_set_results,
                   @@SESSION.collation_connection AS collation_connection
            SQL)->fetch(PDO::FETCH_ASSOC);

        if (! is_array($settings)) {
            throw new RuntimeException('phase_three_p0_schema_session_unavailable');
        }

        try {
            $pdo->exec('SET SESSION sql_mode = '.$pdo->quote(self::TRIGGER_SQL_MODE));
            $pdo->exec("SET SESSION character_set_client = 'cp866'");
            $pdo->exec("SET SESSION character_set_results = 'cp866'");
            $pdo->exec("SET SESSION collation_connection = 'cp866_general_ci'");
            $operation($pdo);
        } finally {
            $pdo->exec('SET SESSION sql_mode = '.$pdo->quote((string) $settings['sql_mode']));
            $pdo->exec('SET SESSION character_set_client = '.$pdo->quote((string) $settings['character_set_client']));
            $pdo->exec('SET SESSION character_set_results = '.$pdo->quote((string) $settings['character_set_results']));
            $pdo->exec('SET SESSION collation_connection = '.$pdo->quote((string) $settings['collation_connection']));
        }
    }

    private static function assertDestructiveEnvironment(string $invocationId): void
    {
        if (! app()->environment(['local', 'testing'])
            || getenv(self::DOWN_CONFIRMATION_ENV) !== 'true') {
            self::fail('phase_three_p0_downgrade_not_authorized', $invocationId);
        }
    }

    private static function assertProtectedGatesDisabled(?string $invocationId = null): void
    {
        foreach (self::PROTECTED_GATES as $gate) {
            if (config($gate, false) === true) {
                if ($invocationId !== null) {
                    self::fail('phase_three_p0_protected_gate_enabled', $invocationId);
                }

                throw new RuntimeException('phase_three_p0_protected_gate_enabled');
            }
        }
    }

    private static function tableHasRows(PDO $pdo, string $table): bool
    {
        return $pdo->query("SELECT EXISTS(SELECT 1 FROM `{$table}` LIMIT 1)")->fetchColumn() === 1;
    }

    /** @param array<string, mixed> $evidence */
    private static function tableHasRowsForDowngrade(
        PDO $pdo,
        string $table,
        string $invocationId,
        array &$evidence,
    ): bool {
        try {
            return self::tableHasRows($pdo, $table);
        } catch (Throwable $exception) {
            $evidence['connection_status'] = $exception instanceof PDOException
                && CanonicalSupplierPhaseThreeP0ConnectionOutcome::isUncertain($exception)
                    ? 'UNCERTAIN'
                    : 'CONNECTED';

            self::fail('phase_three_p0_schema_inspection_unavailable', $invocationId);
        }
    }

    /** @return array<string, mixed> */
    private static function initialEvidence(
        string $invocationId,
        P0MigrationStep $step,
        ?string $target,
    ): array {
        return [
            'event_version' => 'phase_three_p0_downgrade_evidence_v1',
            'invocation_id' => $invocationId,
            'step' => $step->value,
            'initial_state' => 'NOT_INSPECTED',
            'target_state' => $target,
            'completed_ddl_ids' => [],
            'last_verified_state' => 'NOT_INSPECTED',
            'partial_state' => null,
            'connection_status' => 'CONNECTED',
            'mutex_acquire' => 'NOT_ATTEMPTED',
            'mutex_release' => 'NOT_ATTEMPTED',
            'primary_outcome' => 'phase_three_p0_downgrade_not_started',
            'secondary_codes' => [],
        ];
    }

    private static function fail(string $code, string $invocationId): never
    {
        throw new CanonicalSupplierPhaseThreeP0SchemaException($code, $invocationId);
    }
}

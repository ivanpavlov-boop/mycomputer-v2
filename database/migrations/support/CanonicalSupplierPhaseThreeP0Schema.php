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
        'P0-04' => ['predecessor' => 'P3', 'target' => 'P4'],
        'P0-05' => ['predecessor' => 'P4', 'target' => 'P5'],
        'P0-06' => ['predecessor' => 'P5', 'target' => 'P6'],
        'P0-07' => ['predecessor' => 'P6', 'target' => 'P7'],
    ];

    /** @var array<string, array{initial: string, target: string, operation: string}> */
    private const DOWN_STATES = [
        'P0-01' => ['initial' => 'P1', 'target' => 'P0', 'operation' => 'P0-01-DOWN-01'],
        'P0-02' => ['initial' => 'P2', 'target' => 'P1', 'operation' => 'P0-02-DOWN-01'],
        'P0-03' => ['initial' => 'P3', 'target' => 'P2', 'operation' => 'P0-03-DOWN-01'],
        'P0-04' => ['initial' => 'P4', 'target' => 'P3', 'operation' => 'P0-04-DOWN-01'],
        'P0-05' => ['initial' => 'P5', 'target' => 'P4', 'operation' => 'P0-05-DOWN-01'],
        'P0-06' => ['initial' => 'P6', 'target' => 'P5', 'operation' => 'P0-06-DOWN-01'],
        'P0-07' => ['initial' => 'P7', 'target' => 'P6', 'operation' => 'P0-07-DOWN-01'],
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

        if ($step === P0MigrationStep::P0_07) {
            self::runP0_07Forward();

            return;
        }

        self::assertProtectedGatesDisabled();
        self::withGuard(function (PDO $pdo) use ($step, $states): void {
            self::assertState($pdo, $states['predecessor'], 'phase_three_p0_forward_precondition_failed');

            match ($step) {
                P0MigrationStep::P0_01 => self::executeP0_01Forward($pdo),
                P0MigrationStep::P0_02 => self::executeP0_02Forward($pdo),
                P0MigrationStep::P0_03 => self::executeP0_03Forward($pdo),
                P0MigrationStep::P0_04 => self::executeP0_04Forward($pdo),
                P0MigrationStep::P0_05 => self::executeP0_05Forward($pdo),
                P0MigrationStep::P0_06 => self::executeP0_06Forward($pdo),
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
            self::consumeDestructiveAuthorization($invocationId);

            if ($states === null) {
                self::fail('phase_three_p0_downgrade_step_not_implemented', $invocationId);
            }

            self::assertProtectedGatesDisabled($invocationId);

            self::withGuard(function (PDO $pdo) use ($step, $states, $invocationId, &$evidence): void {
                $evidence['mutex_acquire'] = 'ACQUIRED';
                $classification = self::classifyForDowngrade(
                    $pdo,
                    $invocationId,
                    $evidence,
                    afterDdl: false,
                    p007: $step === P0MigrationStep::P0_07,
                );
                $evidence['initial_state'] = $classification['state'];
                $evidence['last_verified_state'] = $classification['state'];
                $evidence['partial_state'] = str_contains($classification['state'], '_DOWN_')
                    ? $classification['state']
                    : null;

                if ($classification['state'] === CanonicalSupplierPhaseThreeP0SchemaComparator::UNCLASSIFIED_STATE) {
                    self::fail('UNCLASSIFIED_P0_SCHEMA_STATE', $invocationId);
                }

                if ($step === P0MigrationStep::P0_07) {
                    self::executeP0_07Down($pdo, $classification['state'], $invocationId, $evidence);

                    return;
                }

                if ($classification['state'] !== $states['initial']) {
                    self::fail('phase_three_p0_step_not_terminal', $invocationId);
                }

                $appendOnlyTable = match ($step) {
                    P0MigrationStep::P0_02 => 'supplier_import_source_profiles',
                    P0MigrationStep::P0_03 => 'supplier_import_source_executions',
                    P0MigrationStep::P0_04 => 'supplier_import_source_payload_receipts',
                    P0MigrationStep::P0_05 => 'supplier_product_identity_heads',
                    P0MigrationStep::P0_06 => 'supplier_product_source_revisions',
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

                if ($step === P0MigrationStep::P0_05) {
                    self::assertIdentityHeadHasNoInboundDependencies($pdo, $invocationId, $evidence);
                }

                if ($step === P0MigrationStep::P0_06) {
                    self::assertSourceRevisionHasNoInboundDependencies($pdo, $invocationId, $evidence);
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

    private static function executeP0_04Forward(PDO $pdo): void
    {
        self::withCanonicalP0Session($pdo, function (PDO $pdo): void {
            $pdo->exec(<<<'SQL'
                CREATE TABLE `supplier_import_source_payload_receipts` (
                  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                  `supplier_import_source_execution_id` bigint unsigned NOT NULL,
                  `source_execution_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                  `receipt_version` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                  `accepted_payload_bytes` bigint unsigned NOT NULL,
                  `accepted_payload_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                  `payload_receipt_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                  `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `uq_import_source_payload_receipt_execution` (`supplier_import_source_execution_id`),
                  UNIQUE KEY `uq_import_source_payload_receipt_fingerprint` (`payload_receipt_fingerprint`),
                  KEY `ix_import_source_payload_receipt_execution_fk` (`supplier_import_source_execution_id`, `source_execution_fingerprint`),
                  CONSTRAINT `fk_import_source_payload_receipt_execution` FOREIGN KEY (`supplier_import_source_execution_id`, `source_execution_fingerprint`) REFERENCES `supplier_import_source_executions` (`id`, `source_execution_fingerprint`) ON UPDATE RESTRICT ON DELETE RESTRICT,
                  CONSTRAINT `chk_import_source_payload_receipt_version` CHECK ((`receipt_version` = _ascii'supplier_import_source_payload_receipt_v1')),
                  CONSTRAINT `chk_import_source_payload_receipt_bytes` CHECK ((`accepted_payload_bytes` > 0)),
                  CONSTRAINT `chk_import_source_payload_receipt_fingerprints` CHECK (((length(`source_execution_fingerprint`) = 64) and regexp_like(`source_execution_fingerprint`,_ascii'^[0-9a-f]{64}$',_cp866'c') and (length(`accepted_payload_sha256`) = 64) and regexp_like(`accepted_payload_sha256`,_ascii'^[0-9a-f]{64}$',_cp866'c') and (length(`payload_receipt_fingerprint`) = 64) and regexp_like(`payload_receipt_fingerprint`,_ascii'^[0-9a-f]{64}$',_cp866'c')))
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='mycomputer:phase-iii-p0:v1:owner=P0-04'
                SQL);
            $pdo->exec(<<<'SQL'
                CREATE TRIGGER `trg_import_source_payload_receipt_no_update`
                BEFORE UPDATE ON `supplier_import_source_payload_receipts`
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable source payload receipt cannot be updated'
                SQL);
            $pdo->exec(<<<'SQL'
                CREATE TRIGGER `trg_import_source_payload_receipt_no_delete`
                BEFORE DELETE ON `supplier_import_source_payload_receipts`
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable source payload receipt cannot be deleted'
                SQL);
        });
    }

    private static function executeP0_05Forward(PDO $pdo): void
    {
        self::withCanonicalP0Session($pdo, function (PDO $pdo): void {
            $pdo->exec(<<<'SQL'
                CREATE TABLE `supplier_product_identity_heads` (
                  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                  `supplier_id` bigint unsigned NOT NULL,
                  `supplier_feed_id` bigint unsigned NOT NULL,
                  `supplier_sku_bytes` varbinary(1020) NOT NULL,
                  `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `uq_supplier_product_identity_head` (`supplier_id`, `supplier_feed_id`, `supplier_sku_bytes`),
                  UNIQUE KEY `uq_supplier_product_identity_head_scope` (`id`, `supplier_id`, `supplier_feed_id`),
                  UNIQUE KEY `uq_supplier_product_identity_head_revision_scope` (`id`, `supplier_id`, `supplier_feed_id`, `supplier_sku_bytes`),
                  KEY `ix_supplier_product_identity_head_feed_owner_fk` (`supplier_feed_id`, `supplier_id`),
                  CONSTRAINT `fk_supplier_product_identity_head_feed_owner` FOREIGN KEY (`supplier_feed_id`, `supplier_id`) REFERENCES `supplier_feeds` (`id`, `supplier_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
                  CONSTRAINT `chk_supplier_product_identity_head_sku_bytes` CHECK ((length(`supplier_sku_bytes`) between 1 and 1020))
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='mycomputer:phase-iii-p0:v1:owner=P0-05'
                SQL);
            $pdo->exec(<<<'SQL'
                CREATE TRIGGER `trg_supplier_product_identity_head_no_update`
                BEFORE UPDATE ON `supplier_product_identity_heads`
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable supplier product identity head cannot be updated'
                SQL);
            $pdo->exec(<<<'SQL'
                CREATE TRIGGER `trg_supplier_product_identity_head_no_delete`
                BEFORE DELETE ON `supplier_product_identity_heads`
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable supplier product identity head cannot be deleted'
                SQL);
        });
    }

    private static function executeP0_06Forward(PDO $pdo): void
    {
        self::withCanonicalP0Session($pdo, function (PDO $pdo): void {
            $pdo->exec(<<<'SQL'
                CREATE TABLE `supplier_product_source_revisions` (
                  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                  `supplier_product_identity_head_id` bigint unsigned NOT NULL,
                  `supplier_product_id` bigint unsigned NOT NULL,
                  `supplier_import_source_execution_id` bigint unsigned NOT NULL,
                  `supplier_id` bigint unsigned NOT NULL,
                  `supplier_feed_id` bigint unsigned NOT NULL,
                  `supplier_sku_bytes` varbinary(1020) NOT NULL,
                  `source_identity` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                  `source_descriptor_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                  `source_execution_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                  `source_member_identity_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                  `source_row_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                  `staging_projection_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                  `revision_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                  `observed_at` timestamp(6) NOT NULL,
                  `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `uq_supplier_product_revision_execution_head` (`supplier_import_source_execution_id`, `supplier_product_identity_head_id`),
                  UNIQUE KEY `uq_supplier_product_revision_pointer_scope` (`id`, `supplier_product_id`, `supplier_product_identity_head_id`, `supplier_id`, `supplier_feed_id`),
                  KEY `ix_supplier_product_revision_head_fk` (`supplier_product_identity_head_id`, `supplier_id`, `supplier_feed_id`, `supplier_sku_bytes`),
                  KEY `ix_supplier_product_revision_execution_fk` (`supplier_import_source_execution_id`, `supplier_id`, `supplier_feed_id`, `source_identity`, `source_descriptor_fingerprint`, `source_execution_fingerprint`),
                  KEY `ix_supplier_product_revision_product_id_fk` (`supplier_product_id`),
                  CONSTRAINT `fk_supplier_product_revision_head` FOREIGN KEY (`supplier_product_identity_head_id`, `supplier_id`, `supplier_feed_id`, `supplier_sku_bytes`) REFERENCES `supplier_product_identity_heads` (`id`, `supplier_id`, `supplier_feed_id`, `supplier_sku_bytes`) ON UPDATE RESTRICT ON DELETE RESTRICT,
                  CONSTRAINT `fk_supplier_product_revision_execution` FOREIGN KEY (`supplier_import_source_execution_id`, `supplier_id`, `supplier_feed_id`, `source_identity`, `source_descriptor_fingerprint`, `source_execution_fingerprint`) REFERENCES `supplier_import_source_executions` (`id`, `supplier_id`, `supplier_feed_id`, `source_identity`, `source_descriptor_fingerprint`, `source_execution_fingerprint`) ON UPDATE RESTRICT ON DELETE RESTRICT,
                  CONSTRAINT `fk_supplier_product_revision_product_id` FOREIGN KEY (`supplier_product_id`) REFERENCES `supplier_products` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
                  CONSTRAINT `chk_supplier_product_revision_source_identity` CHECK (((length(`supplier_sku_bytes`) between 1 and 1020) and (length(`source_identity`) between 1 and 128) and regexp_like(`source_identity`,_ascii'^snapshot-source-v1:[a-z0-9]+([._-][a-z0-9]+)*(:[a-z0-9]+([._-][a-z0-9]+)*)*$',_cp866'c'))),
                  CONSTRAINT `chk_supplier_product_revision_fingerprints` CHECK (((length(`source_descriptor_fingerprint`) = 64) and regexp_like(`source_descriptor_fingerprint`,_ascii'^[0-9a-f]{64}$',_cp866'c') and (length(`source_execution_fingerprint`) = 64) and regexp_like(`source_execution_fingerprint`,_ascii'^[0-9a-f]{64}$',_cp866'c') and (length(`source_member_identity_hash`) = 64) and regexp_like(`source_member_identity_hash`,_ascii'^[0-9a-f]{64}$',_cp866'c') and (length(`source_row_fingerprint`) = 64) and regexp_like(`source_row_fingerprint`,_ascii'^[0-9a-f]{64}$',_cp866'c') and (length(`staging_projection_fingerprint`) = 64) and regexp_like(`staging_projection_fingerprint`,_ascii'^[0-9a-f]{64}$',_cp866'c') and (length(`revision_fingerprint`) = 64) and regexp_like(`revision_fingerprint`,_ascii'^[0-9a-f]{64}$',_cp866'c')))
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='mycomputer:phase-iii-p0:v1:owner=P0-06'
                SQL);
            $pdo->exec(<<<'SQL'
                CREATE TRIGGER `trg_supplier_product_revision_no_update`
                BEFORE UPDATE ON `supplier_product_source_revisions`
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable supplier product source revision cannot be updated'
                SQL);
            $pdo->exec(<<<'SQL'
                CREATE TRIGGER `trg_supplier_product_revision_no_delete`
                BEFORE DELETE ON `supplier_product_source_revisions`
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable supplier product source revision cannot be deleted'
                SQL);
        });
    }

    /** P7 preserves compatible predecessor evidence without binding or backfill. */
    private static function assertP0_07Data(PDO $pdo, bool $down): void
    {
        $bad = $pdo->query(<<<'SQL'
            SELECT EXISTS(
                SELECT 1 FROM supplier_product_source_revisions r
                LEFT JOIN supplier_products p ON p.id = r.supplier_product_id
                WHERE p.id IS NULL OR p.supplier_id IS NULL OR p.supplier_feed_id IS NULL
                   OR r.supplier_id IS NULL OR r.supplier_feed_id IS NULL
                   OR NOT (p.supplier_id <=> r.supplier_id)
                   OR NOT (p.supplier_feed_id <=> r.supplier_feed_id)
            )
            SQL)->fetchColumn();
        if (! in_array($bad, [0, 1], true)) {
            throw new RuntimeException('phase_three_p0_schema_inspection_unavailable');
        }
        if ($bad === 1) {
            throw new RuntimeException('phase_three_p0_pointer_scope_incompatible');
        }
        if ($down) {
            $bound = $pdo->query(<<<'SQL'
                SELECT EXISTS(SELECT 1 FROM supplier_products
                    WHERE supplier_product_identity_head_id IS NOT NULL
                       OR current_source_revision_id IS NOT NULL)
                SQL)->fetchColumn();
            if (! in_array($bound, [0, 1], true)) {
                throw new RuntimeException('phase_three_p0_schema_inspection_unavailable');
            }
            if ($bound === 1) {
                throw new RuntimeException('phase_three_p0_pointers_not_pristine');
            }
        }
    }

    /** Include inbound FKs from outside the inspector's P0 table inventory. */
    private static function assertP0_07Dependencies(PDO $pdo, string $state): void
    {
        $parents = ['supplier_products', 'supplier_product_source_revisions', 'supplier_product_identity_heads'];
        $expected = [];
        foreach (CanonicalSupplierPhaseThreeP0Oracle::signaturesFor($state) as $record) {
            $s = $record['signature'];
            if ($s['type'] === 'foreign_key' && in_array($s['parent_table'], $parents, true)) {
                foreach ($s['child_columns'] as $ordinal => $column) {
                    $expected[] = [$s['child_table'], $s['name'], $column, $s['parent_table'],
                        $s['parent_columns'][$ordinal], $ordinal + 1, $s['update_rule'], $s['delete_rule']];
                }
            }
        }
        // Exact pre-existing ID references, not name-only exemptions.
        foreach (['product_supplier_offers', 'product_sync_logs', 'catalog_sync_logs', 'supplier_product_attributes'] as $table) {
            $expected[] = [$table, $table.'_supplier_product_id_foreign', 'supplier_product_id',
                'supplier_products', 'id', 1, 'NO ACTION', $table === 'supplier_product_attributes' ? 'CASCADE' : 'SET NULL'];
        }
        $rows = $pdo->query(<<<'SQL'
            SELECT k.CONSTRAINT_SCHEMA, k.TABLE_SCHEMA, k.TABLE_NAME, k.CONSTRAINT_NAME,
                   k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME,
                   k.ORDINAL_POSITION, r.UPDATE_RULE, r.DELETE_RULE
            FROM information_schema.KEY_COLUMN_USAGE k
            JOIN information_schema.REFERENTIAL_CONSTRAINTS r
              ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
             AND r.TABLE_NAME = k.TABLE_NAME AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
            WHERE k.REFERENCED_TABLE_SCHEMA = DATABASE()
              AND k.REFERENCED_TABLE_NAME IN
                  ('supplier_products', 'supplier_product_source_revisions', 'supplier_product_identity_heads')
            SQL)->fetchAll(PDO::FETCH_ASSOC);
        $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $actual = [];
        foreach ($rows as $r) {
            if ($r['CONSTRAINT_SCHEMA'] !== $database || $r['TABLE_SCHEMA'] !== $database) {
                throw new RuntimeException('phase_three_p0_pointer_has_inbound_dependencies');
            }
            $actual[] = [$r['TABLE_NAME'], $r['CONSTRAINT_NAME'], $r['COLUMN_NAME'],
                $r['REFERENCED_TABLE_NAME'], $r['REFERENCED_COLUMN_NAME'], (int) $r['ORDINAL_POSITION'],
                $r['UPDATE_RULE'], $r['DELETE_RULE']];
        }
        sort($expected);
        sort($actual);
        if ($actual !== $expected) {
            throw new RuntimeException('phase_three_p0_pointer_has_inbound_dependencies');
        }
    }

    private static function runP0_07Forward(): void
    {
        $id = (string) Str::uuid();
        $evidence = self::initialEvidence($id, P0MigrationStep::P0_07, 'P7');
        $evidence['event_version'] = 'phase_three_p0_forward_evidence_v1';
        $failure = null;
        try {
            self::assertProtectedGatesDisabled($id);
            self::withGuard(function (PDO $pdo) use ($id, &$evidence): void {
                $evidence['mutex_acquire'] = 'ACQUIRED';
                $state = self::classifyForDowngrade($pdo, $id, $evidence, afterDdl: false, p007: true);
                $evidence['initial_state'] = $evidence['last_verified_state'] = $state['state'];
                if ($state['state'] !== 'P6') {
                    self::fail('phase_three_p0_forward_precondition_failed', $id);
                }
                try {
                    self::assertP0_07Data($pdo, false);
                    self::assertP0_07Dependencies($pdo, 'P6');
                } catch (Throwable $error) {
                    self::failP0_07Inspection($error, $id, $evidence);
                }
                self::executeP0_07Forward($pdo, $id, $evidence);
                $evidence['primary_outcome'] = 'phase_three_p0_forward_succeeded';
            }, $id, $evidence);
        } catch (CanonicalSupplierPhaseThreeP0SchemaException $error) {
            $failure = $error;
            $evidence['primary_outcome'] = $error->primaryCode;
        } finally {
            Log::info('phase_three_p0_forward_evidence_v1', $evidence);
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    /** @param array<string, mixed> $evidence */
    private static function failP0_07Inspection(Throwable $error, string $id, array &$evidence): never
    {
        $uncertain = $error instanceof PDOException && self::isP0_07ConnectionUncertain($error);
        $evidence['connection_status'] = $uncertain ? 'UNCERTAIN' : 'CONNECTED';
        $known = ['phase_three_p0_pointer_scope_incompatible', 'phase_three_p0_pointers_not_pristine',
            'phase_three_p0_pointer_has_inbound_dependencies'];
        self::fail($uncertain ? 'phase_three_p0_connection_lost_pre_ddl'
            : (in_array($error->getMessage(), $known, true) ? $error->getMessage() : 'phase_three_p0_schema_inspection_unavailable'), $id);
    }

    /** @param array<string, mixed> $evidence */
    private static function executeP0_07Down(PDO $pdo, string $state, string $id, array &$evidence): void
    {
        $next = ['P7' => 'P0-07-DOWN-01', 'P7_DOWN_1' => 'P0-07-DOWN-02',
            'P7_DOWN_2' => 'P0-07-DOWN-03', 'P7_DOWN_3' => 'P0-07-DOWN-04'];
        if (! isset($next[$state])) {
            self::fail('phase_three_p0_step_not_terminal', $id);
        }
        while ($state !== 'P6') {
            try {
                self::assertP0_07Data($pdo, true);
                self::assertP0_07Dependencies($pdo, $state);
            } catch (Throwable $error) {
                self::failP0_07Inspection($error, $id, $evidence);
            }
            $operation = CanonicalSupplierPhaseThreeP0Oracle::operation($next[$state]);
            self::assertConnectionLiveBeforeDdl($pdo, $id, $evidence);
            try {
                if ($pdo->exec($operation['sql']) === false) {
                    self::fail('phase_three_p0_downgrade_ddl_failed', $id);
                }
            } catch (CanonicalSupplierPhaseThreeP0SchemaException $error) {
                throw $error;
            } catch (Throwable $error) {
                $uncertain = $error instanceof PDOException && self::isP0_07ConnectionUncertain($error);
                $evidence['connection_status'] = $uncertain ? 'UNCERTAIN' : 'CONNECTED';
                self::fail($uncertain ? 'phase_three_p0_connection_outcome_unknown' : 'phase_three_p0_downgrade_ddl_failed', $id);
            }
            $evidence['completed_ddl_ids'][] = $operation['operation_id'];
            $post = self::classifyForDowngrade($pdo, $id, $evidence, afterDdl: true, p007: true);
            $evidence['last_verified_state'] = $post['state'];
            $evidence['partial_state'] = str_contains($post['state'], '_DOWN_') ? $post['state'] : null;
            if ($post['state'] !== $operation['result_state'] || $post['sha256'] !== $operation['result_sha256']) {
                self::fail('phase_three_p0_downgrade_postcondition_failed', $id);
            }
            $state = $post['state'];
        }
        $evidence['primary_outcome'] = 'phase_three_p0_downgrade_succeeded';
    }

    /** @param array<string, mixed> $evidence */
    private static function executeP0_07Forward(PDO $pdo, string $id, array &$evidence): void
    {
        $statements = [
            <<<'SQL'
            ALTER TABLE supplier_products
              ADD COLUMN supplier_product_identity_head_id bigint unsigned DEFAULT NULL,
              ADD COLUMN current_source_revision_id bigint unsigned DEFAULT NULL,
              ADD UNIQUE KEY uq_supplier_product_identity_head_assignment (supplier_product_identity_head_id),
              ADD UNIQUE KEY uq_supplier_product_source_revision_scope (id, supplier_id, supplier_feed_id),
              ADD KEY ix_supplier_product_identity_head_fk (supplier_product_identity_head_id, supplier_id, supplier_feed_id),
              ADD KEY ix_supplier_product_current_revision_fk (current_source_revision_id, id, supplier_product_identity_head_id, supplier_id, supplier_feed_id),
              ADD CONSTRAINT fk_supplier_product_identity_head FOREIGN KEY (supplier_product_identity_head_id, supplier_id, supplier_feed_id) REFERENCES supplier_product_identity_heads (id, supplier_id, supplier_feed_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              ADD CONSTRAINT fk_supplier_product_current_revision FOREIGN KEY (current_source_revision_id, id, supplier_product_identity_head_id, supplier_id, supplier_feed_id) REFERENCES supplier_product_source_revisions (id, supplier_product_id, supplier_product_identity_head_id, supplier_id, supplier_feed_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              ADD CONSTRAINT chk_supplier_product_source_revision_pointers CHECK (((supplier_product_identity_head_id is null) and (current_source_revision_id is null)) or ((supplier_product_identity_head_id is not null) and (current_source_revision_id is not null)))
            SQL,
            <<<'SQL'
            ALTER TABLE supplier_product_source_revisions
              DROP FOREIGN KEY fk_supplier_product_revision_product_id,
              DROP INDEX ix_supplier_product_revision_product_id_fk,
              ADD KEY ix_supplier_product_revision_product_scope_fk (supplier_product_id, supplier_id, supplier_feed_id),
              ADD CONSTRAINT fk_supplier_product_revision_product_scope FOREIGN KEY (supplier_product_id, supplier_id, supplier_feed_id) REFERENCES supplier_products (id, supplier_id, supplier_feed_id) ON UPDATE RESTRICT ON DELETE RESTRICT
            SQL,
            <<<'SQL'
            CREATE TRIGGER `trg_supplier_product_source_projection_guard`
            BEFORE UPDATE ON `supplier_products`
            FOR EACH ROW
            BEGIN
              DECLARE matching_revision_count bigint unsigned DEFAULT 0;
              IF (NEW.`supplier_product_identity_head_id` IS NULL) <> (NEW.`current_source_revision_id` IS NULL) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Supplier product source pointers must be complete';
              END IF;
              IF OLD.`supplier_product_identity_head_id` IS NOT NULL AND (
                  NOT (OLD.`supplier_product_identity_head_id` <=> NEW.`supplier_product_identity_head_id`)
                  OR NOT (OLD.`supplier_id` <=> NEW.`supplier_id`)
                  OR NOT (OLD.`supplier_feed_id` <=> NEW.`supplier_feed_id`)) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Bound supplier product identity scope is immutable';
              END IF;
              IF OLD.`current_source_revision_id` IS NOT NULL AND NEW.`current_source_revision_id` IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Supplier product source revision cannot be cleared';
              END IF;
              IF NEW.`supplier_product_identity_head_id` IS NOT NULL AND (NEW.`supplier_id` IS NULL OR NEW.`supplier_feed_id` IS NULL) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Bound source pointer scope cannot be NULL';
              END IF;
              IF NOT (OLD.`current_source_revision_id` <=> NEW.`current_source_revision_id`) THEN
                SELECT COUNT(*) INTO matching_revision_count
                  FROM `supplier_product_source_revisions` AS r
                  INNER JOIN `supplier_product_identity_heads` AS h
                    ON h.`id` = r.`supplier_product_identity_head_id`
                    AND h.`supplier_id` = r.`supplier_id`
                    AND h.`supplier_feed_id` = r.`supplier_feed_id`
                    AND h.`supplier_sku_bytes` = r.`supplier_sku_bytes`
                  WHERE r.`id` = NEW.`current_source_revision_id`
                    AND r.`supplier_product_id` = NEW.`id`
                    AND r.`supplier_product_identity_head_id` = NEW.`supplier_product_identity_head_id`
                    AND r.`supplier_id` = NEW.`supplier_id`
                    AND r.`supplier_feed_id` = NEW.`supplier_feed_id`
                    AND h.`supplier_sku_bytes` = CAST(NEW.`supplier_sku` AS BINARY);
                IF matching_revision_count <> 1 THEN
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Supplier product source revision scope mismatch';
                END IF;
              ELSEIF NEW.`supplier_product_identity_head_id` IS NOT NULL AND (
                  NOT (CAST(OLD.`supplier_sku` AS BINARY) <=> CAST(NEW.`supplier_sku` AS BINARY))
                  OR NOT (CAST(OLD.`ean` AS BINARY) <=> CAST(NEW.`ean` AS BINARY))
                  OR NOT (CAST(OLD.`mpn` AS BINARY) <=> CAST(NEW.`mpn` AS BINARY))
                  OR NOT (CAST(OLD.`name` AS BINARY) <=> CAST(NEW.`name` AS BINARY))
                  OR NOT (CAST(OLD.`brand_name` AS BINARY) <=> CAST(NEW.`brand_name` AS BINARY))
                  OR NOT (CAST(OLD.`category_name` AS BINARY) <=> CAST(NEW.`category_name` AS BINARY))
                  OR NOT (CAST(OLD.`external_availability_status` AS BINARY) <=> CAST(NEW.`external_availability_status` AS BINARY))
                  OR NOT (CAST(OLD.`external_availability_label` AS BINARY) <=> CAST(NEW.`external_availability_label` AS BINARY))
                  OR NOT (CAST(OLD.`currency` AS BINARY) <=> CAST(NEW.`currency` AS BINARY))
                  OR NOT (CAST(OLD.`payload_hash` AS BINARY) <=> CAST(NEW.`payload_hash` AS BINARY))
                  OR NOT (OLD.`price` <=> NEW.`price`)
                  OR NOT (OLD.`supplier_price_raw` <=> NEW.`supplier_price_raw`)
                  OR NOT (OLD.`recommended_price` <=> NEW.`recommended_price`)
                  OR NOT (OLD.`quantity` <=> NEW.`quantity`)
                  OR NOT (OLD.`availability_status_id` <=> NEW.`availability_status_id`)
                  OR NOT (OLD.`raw_data` <=> NEW.`raw_data`)
                  OR NOT (OLD.`received_at` <=> NEW.`received_at`)
              ) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Source-owned projection change requires revision pointer CAS';
              END IF;
            END
            SQL,
            <<<'SQL'
            CREATE TRIGGER `trg_supplier_product_source_pointer_insert_guard`
            BEFORE INSERT ON `supplier_products`
            FOR EACH ROW
            BEGIN
              IF (NEW.`supplier_product_identity_head_id` IS NOT NULL OR NEW.`current_source_revision_id` IS NOT NULL)
                 AND (NEW.`supplier_id` IS NULL OR NEW.`supplier_feed_id` IS NULL) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Bound source pointer scope cannot be NULL';
              END IF;
            END
            SQL,
        ];
        $results = ['P7_DOWN_3', 'P7_DOWN_2', 'P7_DOWN_1', 'P7'];
        $settings = null;
        $failure = null;
        try {
            $settings = $pdo->query(<<<'SQL'
                SELECT @@SESSION.sql_mode AS sql_mode,
                       @@SESSION.character_set_client AS character_set_client,
                       @@SESSION.character_set_results AS character_set_results,
                       @@SESSION.collation_connection AS collation_connection
                SQL)->fetch(PDO::FETCH_ASSOC);
            if (! is_array($settings)) {
                self::fail('phase_three_p0_schema_session_unavailable', $id);
            }
            foreach (['sql_mode' => self::TRIGGER_SQL_MODE, 'character_set_client' => 'cp866',
                'character_set_results' => 'cp866', 'collation_connection' => 'cp866_general_ci'] as $name => $value) {
                if ($pdo->exec('SET SESSION '.$name.' = '.$pdo->quote($value)) === false) {
                    self::fail('phase_three_p0_schema_session_unavailable', $id);
                }
            }
            foreach ($statements as $i => $sql) {
                self::assertConnectionLiveBeforeDdl($pdo, $id, $evidence);
                if ($pdo->exec($sql) === false) {
                    self::fail('phase_three_p0_forward_ddl_failed', $id);
                }
                $evidence['completed_ddl_ids'][] = 'P0-07-UP-0'.($i + 1);
                $post = self::classifyForDowngrade($pdo, $id, $evidence, afterDdl: true, p007: true);
                $evidence['last_verified_state'] = $post['state'];
                $evidence['partial_state'] = str_contains($post['state'], '_DOWN_') ? $post['state'] : null;
                if ($post['state'] !== $results[$i]) {
                    self::fail('phase_three_p0_forward_postcondition_failed', $id);
                }
            }
        } catch (CanonicalSupplierPhaseThreeP0SchemaException $error) {
            $failure = $error;
        } catch (Throwable $error) {
            $uncertain = $error instanceof PDOException && self::isP0_07ConnectionUncertain($error);
            $evidence['connection_status'] = $uncertain ? 'UNCERTAIN' : 'CONNECTED';
            $failure = new CanonicalSupplierPhaseThreeP0SchemaException(
                $uncertain ? 'phase_three_p0_connection_outcome_unknown' : 'phase_three_p0_forward_ddl_failed', $id,
            );
        } finally {
            if (is_array($settings)) {
                foreach ($settings as $name => $value) {
                    try {
                        if ($pdo->exec('SET SESSION '.$name.' = '.$pdo->quote((string) $value)) === false) {
                            throw new RuntimeException('Session restoration failed.');
                        }
                    } catch (Throwable) {
                        $evidence['secondary_codes'][] = 'phase_three_p0_schema_session_restore_failed';
                        $failure ??= new CanonicalSupplierPhaseThreeP0SchemaException('phase_three_p0_schema_session_restore_failed', $id);
                    }
                }
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    private static function isP0_07ConnectionUncertain(PDOException $error): bool
    {
        // mysqlnd also reports server-gone/lost-connection as HY000, not SQLSTATE 08.
        // Keep the frozen P0-01--06 outcome policy unchanged.
        return CanonicalSupplierPhaseThreeP0ConnectionOutcome::isUncertain($error)
            || (($error->errorInfo[0] ?? null) === 'HY000'
                && in_array($error->errorInfo[1] ?? null, [2006, 2013], true));
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
        bool $p007 = false,
    ): array {
        try {
            return self::classify($pdo);
        } catch (Throwable $exception) {
            $uncertain = $exception instanceof PDOException
                && ($p007 ? self::isP0_07ConnectionUncertain($exception)
                    : CanonicalSupplierPhaseThreeP0ConnectionOutcome::isUncertain($exception));
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

    private static function consumeDestructiveAuthorization(string $invocationId): void
    {
        $confirmation = getenv(self::DOWN_CONFIRMATION_ENV);
        self::clearDestructiveAuthorizationEnvironment();

        if (! app()->environment(['local', 'testing']) || $confirmation !== 'true') {
            self::fail('phase_three_p0_downgrade_not_authorized', $invocationId);
        }
    }

    private static function clearDestructiveAuthorizationEnvironment(): void
    {
        putenv(self::DOWN_CONFIRMATION_ENV);
        unset($_ENV[self::DOWN_CONFIRMATION_ENV], $_SERVER[self::DOWN_CONFIRMATION_ENV]);
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

    /** @param array<string, mixed> $evidence */
    private static function assertIdentityHeadHasNoInboundDependencies(
        PDO $pdo,
        string $invocationId,
        array &$evidence,
    ): void {
        try {
            $hasDependency = $pdo->query(<<<'SQL'
                SELECT EXISTS(
                    SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
                    WHERE REFERENCED_TABLE_SCHEMA = DATABASE()
                      AND REFERENCED_TABLE_NAME = 'supplier_product_identity_heads'
                )
                SQL)->fetchColumn();
            if (! in_array($hasDependency, [0, 1], true)) {
                throw new RuntimeException('Invalid dependency inspection result.');
            }
        } catch (Throwable $exception) {
            $evidence['connection_status'] = $exception instanceof PDOException
                && CanonicalSupplierPhaseThreeP0ConnectionOutcome::isUncertain($exception)
                    ? 'UNCERTAIN'
                    : 'CONNECTED';

            self::fail('phase_three_p0_schema_inspection_unavailable', $invocationId);
        }

        if ($hasDependency === 1) {
            self::fail('phase_three_p0_identity_head_has_inbound_dependencies', $invocationId);
        }
    }

    /** @param array<string, mixed> $evidence */
    private static function assertSourceRevisionHasNoInboundDependencies(
        PDO $pdo,
        string $invocationId,
        array &$evidence,
    ): void {
        try {
            $hasDependency = $pdo->query(<<<'SQL'
                SELECT EXISTS(
                    SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
                    WHERE REFERENCED_TABLE_SCHEMA = DATABASE()
                      AND REFERENCED_TABLE_NAME = 'supplier_product_source_revisions'
                )
                SQL)->fetchColumn();
            if (! in_array($hasDependency, [0, 1], true)) {
                throw new RuntimeException('Invalid dependency inspection result.');
            }
        } catch (Throwable $exception) {
            $evidence['connection_status'] = $exception instanceof PDOException
                && CanonicalSupplierPhaseThreeP0ConnectionOutcome::isUncertain($exception)
                    ? 'UNCERTAIN'
                    : 'CONNECTED';

            self::fail('phase_three_p0_schema_inspection_unavailable', $invocationId);
        }

        if ($hasDependency === 1) {
            self::fail('phase_three_p0_source_revision_has_inbound_dependencies', $invocationId);
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

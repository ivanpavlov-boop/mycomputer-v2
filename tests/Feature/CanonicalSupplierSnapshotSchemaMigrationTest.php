<?php

namespace Tests\Feature;

use Database\Migrations\Support\CanonicalSupplierSnapshotSchema;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PDO;
use PDOException;
use ReflectionClass;
use Tests\TestCase;
use Throwable;

require_once __DIR__.'/../../database/migrations/support/CanonicalSupplierSnapshotSchema.php';

final class CanonicalSupplierSnapshotSchemaMigrationTest extends TestCase
{
    private const CHECK_INVENTORY_SHA256 = 'a705540a21477f791c105cb1e871c749ffacc1477732f22a96f6fa45c1c22538';

    private const TRIGGER_INVENTORY_SHA256 = '4a4d7fc3921fff163efcb8f0b921bfa9b59c98019c7e938751bd1e01ba7003bc';

    private const GENERATED_GUARD_INVENTORY_SHA256 = 'fa986b77b80d675c1c4583485ca9af5b26c6a50b782114178e7d0463f7afc398';

    private const SECURITY_COLUMN_INVENTORY_SHA256 = 'e549fbe5760da29b100adab235990b4949e28039620a174d712646b9001cbb98';

    private const DOWN_CAPABILITY_ENV = 'SUPPLIER_SNAPSHOT_EMPTY_SCHEMA_DOWN_CAPABILITY';

    private const FIRST_DOWN_MIGRATION = '2026_08_20_120011_add_supplier_range_index_to_import_histories';

    /** @var list<string> */
    private const CANONICAL_TABLES = [
        'supplier_import_execution_claims',
        'supplier_import_dispatch_outbox',
        'supplier_import_dispatch_monitor_health',
        'supplier_import_dispatch_alert_intents',
        'supplier_import_dispatch_recovery_authorizations',
        'supplier_import_dispatch_recovery_results',
        'supplier_import_cohort_authorization_members',
        'supplier_offer_snapshot_generations',
        'supplier_offer_snapshot_enrollments',
        'supplier_offer_snapshot_observations',
    ];

    /** @var array<string, string> */
    private const SECURITY_COLUMNS = [
        'supplier_import_cohort_authorization_members.supplier_sku_hash' => 'NO',
        'supplier_import_dispatch_alert_intents.alert_identity' => 'NO',
        'supplier_import_dispatch_alert_intents.delivery_owner_token_hash' => 'YES',
        'supplier_import_dispatch_monitor_health.monitor_owner_token_hash' => 'YES',
        'supplier_import_dispatch_outbox.dispatch_payload_hash' => 'NO',
        'supplier_import_dispatch_outbox.lease_token_hash' => 'YES',
        'supplier_import_dispatch_outbox.logical_execution_key' => 'NO',
        'supplier_import_dispatch_outbox.publication_attempt_token_hash' => 'YES',
        'supplier_import_dispatch_recovery_authorizations.authorization_nonce_hash' => 'NO',
        'supplier_import_dispatch_recovery_authorizations.expected_state_fingerprint' => 'NO',
        'supplier_import_dispatch_recovery_authorizations.logical_execution_key' => 'NO',
        'supplier_import_dispatch_recovery_results.logical_execution_key' => 'NO',
        'supplier_import_dispatch_recovery_results.result_fingerprint' => 'NO',
        'supplier_import_dispatch_recovery_results.resume_state_fingerprint' => 'YES',
        'supplier_import_execution_claims.active_attempt_token_hash' => 'YES',
        'supplier_import_execution_claims.cohort_seed_fingerprint' => 'YES',
        'supplier_import_execution_claims.logical_execution_key' => 'NO',
        'supplier_import_execution_claims.source_fingerprint' => 'YES',
        'supplier_offer_snapshot_enrollments.enrollment_fingerprint' => 'NO',
        'supplier_offer_snapshot_enrollments.supplier_sku_hash' => 'NO',
        'supplier_offer_snapshot_generations.cohort_fingerprint' => 'YES',
        'supplier_offer_snapshot_generations.generation_fingerprint' => 'NO',
        'supplier_offer_snapshot_generations.observation_set_fingerprint' => 'YES',
        'supplier_offer_snapshot_generations.source_fingerprint' => 'NO',
        'supplier_offer_snapshot_observations.observation_fingerprint' => 'NO',
        'supplier_offer_snapshot_observations.reliable_manufacturer_mpn_hash' => 'YES',
        'supplier_offer_snapshot_observations.supplier_sku_hash' => 'NO',
    ];

    /** @var list<string> */
    private array $issuedDownCapabilities = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Canonical supplier snapshot schema requires MySQL 8.4.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->issuedDownCapabilities as $capability) {
            CanonicalSupplierSnapshotSchema::revokeDestructiveDownCapability($capability);
        }
        $this->clearDownCapabilityEnvironment();
        $this->resetDownGuard();

        if (DB::getDriverName() === 'mysql') {
            DB::setDefaultConnection((string) config('database.default'));
            $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        }

        parent::tearDown();
    }

    public function test_mysql_84_schema_matches_the_canonical_inventory(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);

        $version = (string) DB::scalar('SELECT VERSION()');
        $this->assertStringStartsWith('8.4.', $version);

        foreach (self::CANONICAL_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing canonical table {$table}.");
            $create = (array) DB::selectOne(sprintf('SHOW CREATE TABLE `%s`', $table));
            $this->assertStringContainsString('ENGINE=InnoDB', (string) array_values($create)[1]);
        }

        $this->assertSame(10, collect(DB::select(<<<'SQL'
            SELECT TABLE_NAME
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN (
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
            SQL))->count());

        $this->assertIndexInventory();
        $this->assertForeignKeyInventory();
        $this->assertCheckInventory();
        $this->assertTriggerInventory();
        $this->assertGeneratedGuardInventory();
        $this->assertHexColumnInventory();
        $this->assertPristineMonitor();
    }

    public function test_mysql_constraints_reject_cross_parent_and_immutable_mutations(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);

        $fixture = $this->seedProtectedGraph();

        $this->assertQueryRejected(function () use ($fixture): void {
            DB::table('supplier_import_execution_claims')->insert([
                'logical_execution_key' => str_repeat('d', 64),
                'supplier_id' => $fixture['supplier_id'],
                'supplier_import_run_id' => $fixture['run_id'],
                'execution_path' => 'ORCHESTRATED',
            ]);
        }, 'check constraint');

        $validOrchestratedClaimId = DB::table('supplier_import_execution_claims')->insertGetId([
            'logical_execution_key' => str_repeat('e', 64),
            'supplier_id' => $fixture['supplier_id'],
            'supplier_import_run_id' => $fixture['run_id'],
            'execution_path' => 'orchestrated',
        ]);

        $this->assertSame(1, DB::table('supplier_import_execution_claims')
            ->where('id', $validOrchestratedClaimId)
            ->update(['state' => 'queued']));
        $this->assertQueryRejected(function () use ($validOrchestratedClaimId): void {
            DB::table('supplier_import_execution_claims')
                ->where('id', $validOrchestratedClaimId)
                ->update(['execution_path' => 'legacy_xml']);
        }, 'immutable');

        $other = $this->seedParentFixture('other');
        $this->assertQueryRejected(function () use ($fixture, $other): void {
            DB::table('supplier_import_execution_claims')->insert([
                'logical_execution_key' => str_repeat('f', 64),
                'supplier_id' => $fixture['supplier_id'],
                'supplier_feed_id' => $other['feed_id'],
                'import_job_id' => $other['job_id'],
                'allocated_at' => '2026-08-20 08:00:00.000000',
                'execution_path' => 'legacy_xml',
            ]);
        }, 'foreign key constraint');

        $this->assertQueryRejected(function () use ($fixture): void {
            DB::table('suppliers')->where('id', $fixture['supplier_id'])->delete();
        }, 'foreign key constraint');
        $this->assertDatabaseHas('suppliers', ['id' => $fixture['supplier_id']]);

        foreach ([
            ['supplier_import_dispatch_recovery_authorizations', $fixture['authorization_id']],
            ['supplier_import_dispatch_recovery_results', $fixture['result_id']],
            ['supplier_import_cohort_authorization_members', $fixture['cohort_member_id']],
            ['supplier_offer_snapshot_generations', $fixture['generation_id']],
            ['supplier_offer_snapshot_enrollments', $fixture['enrollment_id']],
            ['supplier_offer_snapshot_observations', $fixture['observation_id']],
        ] as [$table, $id]) {
            $this->assertQueryRejected(function () use ($table, $id): void {
                DB::table($table)->where('id', $id)->update(['id' => $id]);
            }, 'immutable');
            $this->assertQueryRejected(function () use ($table, $id): void {
                DB::table($table)->where('id', $id)->delete();
            }, 'immutable');
            $this->assertDatabaseHas($table, ['id' => $id]);
        }

        $this->assertQueryRejected(function () use ($fixture): void {
            DB::table('supplier_import_dispatch_recovery_results')->insert([
                'supplier_import_dispatch_recovery_authorization_id' => $fixture['authorization_id'],
                'authorization_action' => 'republish_same_key',
                'authorized_operator_id' => $fixture['user_id'],
                'supplier_import_execution_claim_id' => $fixture['claim_id'],
                'supplier_import_dispatch_outbox_id' => $fixture['outbox_id'],
                'logical_execution_key' => str_repeat('a', 64),
                'target_parent_type' => 'supplier_feed',
                'target_parent_id' => $fixture['feed_id'],
                'event_sequence' => 1,
                'event_kind' => 'started',
                'canonical_result_code' => 'authorization_attempt_started',
                'resume_state_fingerprint' => str_repeat('9', 64),
                'occurred_at' => '2026-08-20 08:02:00.000000',
                'result_fingerprint' => str_repeat('8', 64),
            ]);
        }, 'duplicate');

    }

    public function test_mysql_restrictive_fk_races_use_distinct_transactions_and_exact_errors(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);

        config([
            'database.connections.snapshot_schema_race_a' => config('database.connections.mysql'),
            'database.connections.snapshot_schema_race_b' => config('database.connections.mysql'),
        ]);
        DB::purge('snapshot_schema_race_a');
        DB::purge('snapshot_schema_race_b');

        $connectionA = DB::connection('snapshot_schema_race_a');
        $connectionB = DB::connection('snapshot_schema_race_b');
        $pdoA = $connectionA->getPdo();
        $pdoB = $connectionB->getPdo();
        $timeoutA = (int) $connectionA->scalar('SELECT @@SESSION.innodb_lock_wait_timeout');
        $timeoutB = (int) $connectionB->scalar('SELECT @@SESSION.innodb_lock_wait_timeout');

        try {
            $this->assertNotSame(
                (int) $connectionA->scalar('SELECT CONNECTION_ID()'),
                (int) $connectionB->scalar('SELECT CONNECTION_ID()'),
            );
            $connectionA->statement('SET SESSION innodb_lock_wait_timeout = 1');
            $connectionB->statement('SET SESSION innodb_lock_wait_timeout = 1');

            $this->assertParentDeleteWinsRace($pdoA, $pdoB);
            $this->assertChildInsertWinsRace($pdoA, $pdoB);
        } finally {
            $this->rollbackPdo($pdoA);
            $this->rollbackPdo($pdoB);
            $connectionA->statement('SET SESSION innodb_lock_wait_timeout = '.$timeoutA);
            $connectionB->statement('SET SESSION innodb_lock_wait_timeout = '.$timeoutB);
            DB::disconnect('snapshot_schema_race_a');
            DB::disconnect('snapshot_schema_race_b');
            DB::purge('snapshot_schema_race_a');
            DB::purge('snapshot_schema_race_b');
        }
    }

    public function test_guarded_down_fails_closed_and_empty_schema_round_trips(): void
    {
        $database = 'phase_i_schema_'.getmypid().'_'.strtolower(bin2hex(random_bytes(4)));
        $historicalPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database.'_migrations';
        $originalEnvironment = app()->environment();
        $originalConfirmation = getenv('SUPPLIER_SNAPSHOT_EMPTY_SCHEMA_DOWN_CONFIRMED');
        $originalCapability = getenv(self::DOWN_CAPABILITY_ENV);
        $originalDefaultConnection = DB::getDefaultConnection();

        try {
            $this->createTemporaryDatabase($database);
            $this->configureTemporaryConnection($database);
            $this->copyHistoricalMigrations($historicalPath);
            $this->migrateHistoricalThenPhase($historicalPath);
            DB::setDefaultConnection('snapshot_schema_phase_i');

            $this->clearDownCapabilityEnvironment();
            $this->assertGuardRejectedContaining('one-use invocation capability is missing or malformed');

            $this->issueDownCapability();
            app()->detectEnvironment(static fn (): string => 'production');
            $this->assertGuardRejectedContaining('environment must be exactly local or testing');
            app()->detectEnvironment(static fn () => $originalEnvironment);

            $this->issueDownCapability();
            config(['supplier_snapshot_capture.monitor_schedule_enabled' => true]);
            $this->assertGuardRejectedContaining('forward gate supplier_snapshot_capture.monitor_schedule_enabled is not disabled');
            config(['supplier_snapshot_capture.monitor_schedule_enabled' => false]);

            CanonicalSupplierSnapshotSchema::dropTriggers([
                'trg_snapshot_observation_no_update',
                'trg_snapshot_observation_no_delete',
            ]);
            Schema::drop('supplier_offer_snapshot_observations');
            $this->issueDownCapability();
            $this->assertGuardRejectedContaining('expected table supplier_offer_snapshot_observations is missing');
            foreach (array_diff(self::CANONICAL_TABLES, ['supplier_offer_snapshot_observations']) as $table) {
                $this->assertTrue(Schema::hasTable($table));
            }

            DB::setDefaultConnection($originalDefaultConnection);
            $this->recreateTemporaryDatabase($database);
            $this->migrateHistoricalThenPhase($historicalPath);
            DB::setDefaultConnection('snapshot_schema_phase_i');
            $this->seedProtectedGraph('snapshot_schema_phase_i');
            $this->issueDownCapability();
            $message = $this->guardRejectionMessage();
            foreach (array_diff(self::CANONICAL_TABLES, ['supplier_import_dispatch_monitor_health']) as $table) {
                $this->assertStringContainsString($table, $message);
                $this->assertTrue(Schema::hasTable($table));
                $this->assertGreaterThan(0, DB::table($table)->count());
            }

            DB::setDefaultConnection($originalDefaultConnection);
            $this->recreateTemporaryDatabase($database);
            $this->migrateHistoricalThenPhase($historicalPath);
            DB::setDefaultConnection('snapshot_schema_phase_i');
            DB::table('supplier_import_dispatch_monitor_health')->where('id', 1)->update([
                'integrity_state' => 'stale',
            ]);
            $this->issueDownCapability();
            $this->assertGuardRejectedContaining('monitor singleton column integrity_state is not pristine');
            $this->assertSame(10, collect(self::CANONICAL_TABLES)->filter(
                static fn (string $table): bool => Schema::hasTable($table),
            )->count());

            DB::setDefaultConnection($originalDefaultConnection);
            $this->recreateTemporaryDatabase($database);
            $this->migrateHistoricalThenPhase($historicalPath);
            DB::setDefaultConnection('snapshot_schema_phase_i');
            putenv('SUPPLIER_SNAPSHOT_EMPTY_SCHEMA_DOWN_CONFIRMED=true');
            config(['supplier_snapshot_capture.destructive_down_confirmed' => true]);
            CanonicalSupplierSnapshotSchema::revokeDestructiveDownCapability(str_repeat('a', 64));
            putenv(self::DOWN_CAPABILITY_ENV.'='.str_repeat('a', 64));
            $this->assertGuardRejectedContaining('capability artifact is missing or already consumed');
            $this->assertSame(10, collect(self::CANONICAL_TABLES)->filter(
                static fn (string $table): bool => Schema::hasTable($table),
            )->count());
            config(['supplier_snapshot_capture.destructive_down_confirmed' => null]);

            DB::setDefaultConnection($originalDefaultConnection);
            $this->recreateTemporaryDatabase($database);
            $this->migrateHistoricalThenPhase($historicalPath);
            DB::setDefaultConnection('snapshot_schema_phase_i');
            $before = $this->canonicalCreateStatements();
            $consumedCapability = $this->issueDownCapability();
            DB::setDefaultConnection($originalDefaultConnection);

            $this->assertSame(0, Artisan::call('migrate:rollback', [
                '--database' => 'snapshot_schema_phase_i',
                '--force' => true,
            ]), Artisan::output());

            DB::setDefaultConnection('snapshot_schema_phase_i');
            foreach (self::CANONICAL_TABLES as $table) {
                $this->assertFalse(Schema::hasTable($table));
            }
            $this->assertTrue(Schema::hasTable('suppliers'));
            $this->assertTrue(Schema::hasTable('import_jobs'));
            $this->assertFalse($this->indexExists('import_jobs', 'uq_import_job_id_supplier_feed'));
            $this->assertFalse($this->indexExists('import_histories', 'ix_import_history_supplier_id'));

            DB::setDefaultConnection($originalDefaultConnection);
            $this->assertSame(0, Artisan::call('migrate', [
                '--database' => 'snapshot_schema_phase_i',
                '--path' => database_path('migrations'),
                '--realpath' => true,
                '--force' => true,
            ]), Artisan::output());
            DB::setDefaultConnection('snapshot_schema_phase_i');
            $this->assertSame($before, $this->canonicalCreateStatements());
            $this->assertPristineMonitor();

            DB::table('supplier_import_dispatch_monitor_health')->where('id', 1)->update([
                'integrity_state' => 'stale',
            ]);
            config(['supplier_snapshot_capture.monitor_schedule_enabled' => true]);
            putenv('SUPPLIER_SNAPSHOT_EMPTY_SCHEMA_DOWN_CONFIRMED=true');
            $this->clearDownCapabilityEnvironment();
            $this->assertGuardRejectedContaining('one-use invocation capability is missing or malformed');
            $this->assertFalse(getenv(self::DOWN_CAPABILITY_ENV));
            $this->assertTrue((bool) config('supplier_snapshot_capture.monitor_schedule_enabled'));
            $this->assertSame('stale', DB::table('supplier_import_dispatch_monitor_health')->value('integrity_state'));
            $this->assertTrue($this->indexExists('import_jobs', 'uq_import_job_id_supplier_feed'));
            $this->assertTrue($this->indexExists('import_histories', 'ix_import_history_supplier_id'));
            $this->assertSame(10, collect(self::CANONICAL_TABLES)->filter(
                static fn (string $table): bool => Schema::hasTable($table),
            )->count());
            CanonicalSupplierSnapshotSchema::revokeDestructiveDownCapability($consumedCapability);
        } finally {
            app()->detectEnvironment(static fn () => $originalEnvironment);
            config(['supplier_snapshot_capture.monitor_schedule_enabled' => false]);
            config(['supplier_snapshot_capture.destructive_down_confirmed' => null]);
            $this->resetDownGuard();
            if ($originalConfirmation === false) {
                putenv('SUPPLIER_SNAPSHOT_EMPTY_SCHEMA_DOWN_CONFIRMED');
            } else {
                putenv('SUPPLIER_SNAPSHOT_EMPTY_SCHEMA_DOWN_CONFIRMED='.$originalConfirmation);
            }
            if ($originalCapability === false) {
                $this->clearDownCapabilityEnvironment();
            } else {
                putenv(self::DOWN_CAPABILITY_ENV.'='.$originalCapability);
            }
            DB::setDefaultConnection($originalDefaultConnection);
            DB::disconnect('snapshot_schema_phase_i');
            DB::purge('snapshot_schema_phase_i');
            $this->dropTemporaryDatabase($database);
            File::deleteDirectory($historicalPath);
        }
    }

    public function test_failed_scope_and_out_of_sequence_steps_cannot_reuse_authorization(): void
    {
        $database = 'phase_i_scope_'.getmypid().'_'.strtolower(bin2hex(random_bytes(4)));
        $historicalPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database.'_migrations';
        $originalDefaultConnection = DB::getDefaultConnection();
        $blocker = null;
        $originalLockTimeout = null;

        try {
            $this->createTemporaryDatabase($database);
            $this->configureTemporaryConnection($database);
            $this->copyHistoricalMigrations($historicalPath);
            $this->migrateHistoricalThenPhase($historicalPath);
            DB::setDefaultConnection('snapshot_schema_phase_i');

            config(['database.connections.snapshot_schema_scope_blocker' => array_merge(
                config('database.connections.mysql'),
                ['database' => $database],
            )]);
            DB::purge('snapshot_schema_scope_blocker');
            $blocker = DB::connection('snapshot_schema_scope_blocker');
            $originalLockTimeout = (int) DB::scalar('SELECT @@SESSION.lock_wait_timeout');
            DB::statement('SET SESSION lock_wait_timeout = 1');
            $blocker->beginTransaction();
            $blocker->select('SELECT id FROM import_histories LIMIT 1 FOR UPDATE');
            $this->issueDownCapability();

            $this->assertStringContainsStringIgnoringCase(
                'lock wait timeout',
                $this->guardRejectionMessage(),
            );
            $blocker->rollBack();
            $this->clearDownCapabilityEnvironment();
            $this->assertTrue($this->indexExists('import_histories', 'ix_import_history_supplier_id'));
            $this->assertGuardRejectedContaining('one-use invocation capability is missing or malformed');
            $this->assertSame(10, collect(self::CANONICAL_TABLES)->filter(
                static fn (string $table): bool => Schema::hasTable($table),
            )->count());
            DB::statement('SET SESSION lock_wait_timeout = '.$originalLockTimeout);
            DB::disconnect('snapshot_schema_scope_blocker');
            DB::purge('snapshot_schema_scope_blocker');

            DB::setDefaultConnection($originalDefaultConnection);
            $this->recreateTemporaryDatabase($database);
            $this->migrateHistoricalThenPhase($historicalPath);
            DB::setDefaultConnection('snapshot_schema_phase_i');
            $migration = DB::table('migrations')->where('migration', self::FIRST_DOWN_MIGRATION)->first();
            $this->assertNotNull($migration);
            DB::table('migrations')->where('migration', self::FIRST_DOWN_MIGRATION)->delete();
            $this->issueDownCapability();

            $this->assertGuardRejectedContaining('rollback sequence must begin with '.self::FIRST_DOWN_MIGRATION);
            $this->assertTrue(Schema::hasTable('supplier_offer_snapshot_observations'));
            $this->assertTrue($this->indexExists('import_histories', 'ix_import_history_supplier_id'));
            DB::table('migrations')->insert((array) $migration);
        } finally {
            if ($blocker !== null && $blocker->transactionLevel() > 0) {
                $blocker->rollBack();
            }
            if ($originalLockTimeout !== null) {
                DB::setDefaultConnection('snapshot_schema_phase_i');
                DB::statement('SET SESSION lock_wait_timeout = '.$originalLockTimeout);
            }
            DB::disconnect('snapshot_schema_scope_blocker');
            DB::purge('snapshot_schema_scope_blocker');
            $this->resetDownGuard();
            $this->clearDownCapabilityEnvironment();
            DB::setDefaultConnection($originalDefaultConnection);
            DB::disconnect('snapshot_schema_phase_i');
            DB::purge('snapshot_schema_phase_i');
            $this->dropTemporaryDatabase($database);
            File::deleteDirectory($historicalPath);
        }
    }

    private function assertIndexInventory(): void
    {
        $expected = [
            'supplier_import_execution_claims' => [
                'PRIMARY', 'ix_import_execution_claim_feed',
                'ix_import_execution_claim_job_owner_fk',
                'ix_import_execution_claim_scope_state',
                'ix_import_execution_claim_supplier',
                'uq_import_execution_claim_history', 'uq_import_execution_claim_id_key',
                'uq_import_execution_claim_job', 'uq_import_execution_claim_logical_key',
                'uq_import_execution_claim_run',
            ],
            'supplier_import_dispatch_outbox' => [
                'PRIMARY', 'ix_import_dispatch_outbox_due',
                'ix_import_dispatch_outbox_lease',
                'ix_import_dispatch_outbox_state_watchdog_id',
                'uq_import_dispatch_outbox_claim_event',
                'uq_import_dispatch_outbox_claim_key',
                'uq_import_dispatch_outbox_id_claim',
            ],
            'supplier_import_dispatch_monitor_health' => [
                'PRIMARY', 'uq_import_dispatch_monitor_identity',
                'uq_import_dispatch_observer_identity',
            ],
            'supplier_import_dispatch_alert_intents' => [
                'PRIMARY', 'ix_import_dispatch_alert_due',
                'ix_import_dispatch_alert_lease', 'ix_import_dispatch_alert_outbox',
                'uq_import_dispatch_alert_identity',
            ],
            'supplier_import_dispatch_recovery_authorizations' => [
                'PRIMARY', 'ix_import_recovery_auth_claim',
                'ix_import_recovery_auth_operator', 'ix_import_recovery_auth_outbox_claim',
                'uq_import_recovery_auth_complete_tuple', 'uq_import_recovery_auth_nonce',
            ],
            'supplier_import_dispatch_recovery_results' => [
                'PRIMARY', 'ix_import_recovery_result_claim',
                'ix_import_recovery_result_complete_auth_tuple',
                'ix_import_recovery_result_operator',
                'ix_import_recovery_result_outbox_claim',
                'uq_import_recovery_result_auth_sequence',
                'uq_import_recovery_result_auth_started',
                'uq_import_recovery_result_auth_terminal',
            ],
            'supplier_import_cohort_authorization_members' => [
                'PRIMARY', 'uq_import_cohort_auth_claim_offer',
            ],
            'supplier_offer_snapshot_generations' => [
                'PRIMARY', 'ix_snapshot_generation_feed',
                'ix_snapshot_generation_feed_import',
                'ix_snapshot_generation_predecessor',
                'ix_snapshot_generation_qualified_range',
                'ix_snapshot_generation_retention',
                'ix_snapshot_generation_scope_order',
                'uq_snapshot_generation_execution_claim',
                'uq_snapshot_generation_import_history',
            ],
            'supplier_offer_snapshot_enrollments' => [
                'PRIMARY', 'ix_snapshot_enrollment_effective',
                'ix_snapshot_enrollment_effective_history',
                'ix_snapshot_enrollment_feed', 'uq_snapshot_enrollment_scope_offer',
            ],
            'supplier_offer_snapshot_observations' => [
                'PRIMARY', 'ix_snapshot_observation_enrollment_history',
                'ix_snapshot_observation_offer_history',
                'uq_snapshot_observation_generation_enrollment',
                'uq_snapshot_observation_generation_offer',
            ],
        ];

        foreach ($expected as $table => $names) {
            sort($names);
            $actual = collect(DB::select(<<<'SQL'
                SELECT DISTINCT INDEX_NAME AS index_name
                FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                ORDER BY INDEX_NAME
                SQL, [$table]))->pluck('index_name')->all();
            sort($actual);
            $this->assertSame($names, $actual, "Unexpected index inventory for {$table}.");
        }

        $this->assertTrue($this->indexExists('import_jobs', 'uq_import_job_id_supplier_feed'));
        $this->assertTrue($this->indexExists('import_histories', 'ix_import_history_supplier_id'));
    }

    private function assertForeignKeyInventory(): void
    {
        $expected = [
            'fk_import_cohort_auth_claim',
            'fk_import_dispatch_alert_outbox',
            'fk_import_dispatch_outbox_claim',
            'fk_import_dispatch_outbox_claim_key',
            'fk_import_execution_claim_feed',
            'fk_import_execution_claim_history',
            'fk_import_execution_claim_job_scope',
            'fk_import_execution_claim_run',
            'fk_import_execution_claim_supplier',
            'fk_import_recovery_auth_claim',
            'fk_import_recovery_auth_operator',
            'fk_import_recovery_auth_outbox_claim',
            'fk_import_recovery_result_auth',
            'fk_import_recovery_result_complete_auth_tuple',
            'fk_snapshot_enrollment_effective_history',
            'fk_snapshot_enrollment_feed',
            'fk_snapshot_enrollment_supplier',
            'fk_snapshot_generation_execution_claim',
            'fk_snapshot_generation_feed',
            'fk_snapshot_generation_import_history',
            'fk_snapshot_generation_predecessor',
            'fk_snapshot_generation_supplier',
            'fk_snapshot_observation_enrollment',
            'fk_snapshot_observation_generation',
        ];
        sort($expected);

        $rows = collect(DB::select(<<<'SQL'
            SELECT CONSTRAINT_NAME AS constraint_name,
                   UPDATE_RULE AS update_rule,
                   DELETE_RULE AS delete_rule
            FROM information_schema.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE()
                AND TABLE_NAME IN (
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
            ORDER BY CONSTRAINT_NAME
            SQL));

        $this->assertSame($expected, $rows->pluck('constraint_name')->all());
        $this->assertSame(['RESTRICT'], $rows->pluck('update_rule')->unique()->values()->all());
        $this->assertSame(['RESTRICT'], $rows->pluck('delete_rule')->unique()->values()->all());

        $composite = collect(DB::select(<<<'SQL'
            SELECT CONSTRAINT_NAME AS constraint_name,
                   GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION SEPARATOR ',') AS child_columns,
                   GROUP_CONCAT(REFERENCED_COLUMN_NAME ORDER BY ORDINAL_POSITION SEPARATOR ',') AS parent_columns
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE CONSTRAINT_SCHEMA = DATABASE()
                AND CONSTRAINT_NAME IN (
                    'fk_import_execution_claim_job_scope',
                    'fk_import_dispatch_outbox_claim_key',
                    'fk_import_recovery_auth_outbox_claim',
                    'fk_import_recovery_result_complete_auth_tuple'
                )
            GROUP BY CONSTRAINT_NAME
            ORDER BY CONSTRAINT_NAME
            SQL))->keyBy('constraint_name');

        $this->assertSame(
            'import_job_id,supplier_id,supplier_feed_id',
            $composite['fk_import_execution_claim_job_scope']->child_columns,
        );
        $this->assertSame(
            'supplier_import_execution_claim_id,logical_execution_key',
            $composite['fk_import_dispatch_outbox_claim_key']->child_columns,
        );
        $this->assertSame(
            'supplier_import_dispatch_outbox_id,supplier_import_execution_claim_id',
            $composite['fk_import_recovery_auth_outbox_claim']->child_columns,
        );
        $this->assertSame(
            'supplier_import_dispatch_recovery_authorization_id,authorization_action,authorized_operator_id,supplier_import_execution_claim_id,supplier_import_dispatch_outbox_id,logical_execution_key,target_parent_type,target_parent_id',
            $composite['fk_import_recovery_result_complete_auth_tuple']->child_columns,
        );
        $this->assertSame(
            'id,supplier_id,supplier_feed_id',
            $composite['fk_import_execution_claim_job_scope']->parent_columns,
        );
        $this->assertSame(
            'id,logical_execution_key',
            $composite['fk_import_dispatch_outbox_claim_key']->parent_columns,
        );
        $this->assertSame(
            'id,supplier_import_execution_claim_id',
            $composite['fk_import_recovery_auth_outbox_claim']->parent_columns,
        );
        $this->assertSame(
            'id,authorization_action,authorized_operator_id,supplier_import_execution_claim_id,supplier_import_dispatch_outbox_id,logical_execution_key,target_parent_type,target_parent_id',
            $composite['fk_import_recovery_result_complete_auth_tuple']->parent_columns,
        );
    }

    private function assertCheckInventory(): void
    {
        $actual = collect(DB::select(<<<'SQL'
            SELECT tc.TABLE_NAME AS table_name,
                   tc.CONSTRAINT_NAME AS constraint_name,
                   cc.CHECK_CLAUSE AS expression
            FROM information_schema.TABLE_CONSTRAINTS tc
            INNER JOIN information_schema.CHECK_CONSTRAINTS cc
                ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
                AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
            WHERE tc.CONSTRAINT_SCHEMA = DATABASE()
                AND tc.CONSTRAINT_TYPE = 'CHECK'
                AND tc.TABLE_NAME IN (
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
            ORDER BY tc.TABLE_NAME, tc.CONSTRAINT_NAME
            SQL))->map(static fn (object $row): array => [
            'table_name' => (string) $row->table_name,
            'constraint_name' => (string) $row->constraint_name,
            'expression' => self::normalizeSql((string) $row->expression),
        ])->all();

        $this->assertCount(94, $actual);
        $this->assertSame(self::CHECK_INVENTORY_SHA256, self::inventoryHash($actual));
    }

    private function assertTriggerInventory(): void
    {
        $expected = [
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

        $actual = collect(DB::select(<<<'SQL'
            SELECT TRIGGER_NAME AS trigger_name,
                   EVENT_OBJECT_TABLE AS table_name,
                   ACTION_TIMING AS timing,
                   EVENT_MANIPULATION AS event,
                   ACTION_STATEMENT AS statement
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
            SQL))->map(static fn (object $row): array => [
            'trigger_name' => (string) $row->trigger_name,
            'table_name' => (string) $row->table_name,
            'timing' => (string) $row->timing,
            'event' => (string) $row->event,
            'statement' => self::normalizeSql((string) $row->statement),
        ])->all();

        $this->assertCount(13, $actual);
        $this->assertSame($expected, array_column($actual, 'trigger_name'));
        $this->assertSame(self::TRIGGER_INVENTORY_SHA256, self::inventoryHash($actual));
    }

    private function assertGeneratedGuardInventory(): void
    {
        $rows = collect(DB::select(<<<'SQL'
            SELECT c.TABLE_NAME AS table_name,
                   c.COLUMN_NAME AS column_name,
                   c.COLUMN_TYPE AS column_type,
                   c.IS_NULLABLE AS is_nullable,
                   c.EXTRA AS extra,
                   c.GENERATION_EXPRESSION AS expression,
                   s.INDEX_NAME AS index_name,
                   s.NON_UNIQUE AS non_unique,
                   s.SEQ_IN_INDEX AS seq_in_index
            FROM information_schema.COLUMNS c
            INNER JOIN information_schema.STATISTICS s
                ON s.TABLE_SCHEMA = c.TABLE_SCHEMA
                AND s.TABLE_NAME = c.TABLE_NAME
                AND s.COLUMN_NAME = c.COLUMN_NAME
            WHERE c.TABLE_SCHEMA = DATABASE()
                AND c.TABLE_NAME = 'supplier_import_dispatch_recovery_results'
                AND c.COLUMN_NAME IN ('started_once_guard', 'terminal_once_guard')
            ORDER BY c.COLUMN_NAME, s.INDEX_NAME, s.SEQ_IN_INDEX
            SQL))->map(static fn (object $row): array => [
            'table_name' => (string) $row->table_name,
            'column_name' => (string) $row->column_name,
            'column_type' => (string) $row->column_type,
            'is_nullable' => (string) $row->is_nullable,
            'extra' => (string) $row->extra,
            'expression' => self::normalizeSql((string) $row->expression),
            'index_name' => (string) $row->index_name,
            'non_unique' => (int) $row->non_unique,
            'seq_in_index' => (int) $row->seq_in_index,
        ])->all();

        $this->assertCount(2, $rows);
        $this->assertSame(['started_once_guard', 'terminal_once_guard'], array_column($rows, 'column_name'));
        $this->assertSame(self::GENERATED_GUARD_INVENTORY_SHA256, self::inventoryHash($rows));
    }

    private function assertHexColumnInventory(): void
    {
        $rows = collect(DB::select(<<<'SQL'
            SELECT TABLE_NAME AS table_name,
                   COLUMN_NAME AS column_name,
                   COLUMN_TYPE AS column_type,
                   CHARACTER_MAXIMUM_LENGTH AS length,
                   CHARACTER_SET_NAME AS charset,
                   COLLATION_NAME AS collation,
                   IS_NULLABLE AS is_nullable
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
                AND COLUMN_TYPE = 'char(64)'
                AND CHARACTER_SET_NAME = 'ascii'
                AND COLLATION_NAME = 'ascii_bin'
                AND TABLE_NAME IN (
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
            ORDER BY TABLE_NAME, COLUMN_NAME
            SQL))->map(static fn (object $row): array => [
            'table_name' => (string) $row->table_name,
            'column_name' => (string) $row->column_name,
            'column_type' => (string) $row->column_type,
            'length' => (int) $row->length,
            'charset' => (string) $row->charset,
            'collation' => (string) $row->collation,
            'is_nullable' => (string) $row->is_nullable,
        ])->all();

        $this->assertCount(27, $rows);
        $this->assertSame(self::SECURITY_COLUMNS, collect($rows)->mapWithKeys(
            static fn (array $row): array => [
                $row['table_name'].'.'.$row['column_name'] => $row['is_nullable'],
            ],
        )->all());
        $this->assertSame(self::SECURITY_COLUMN_INVENTORY_SHA256, self::inventoryHash($rows));

        foreach ($rows as $row) {
            if ($row['table_name'] === 'supplier_import_dispatch_recovery_results'
                && $row['column_name'] === 'logical_execution_key') {
                $this->assertSame(1, (int) DB::scalar(<<<'SQL'
                    SELECT COUNT(*)
                    FROM information_schema.REFERENTIAL_CONSTRAINTS
                    WHERE CONSTRAINT_SCHEMA = DATABASE()
                        AND TABLE_NAME = 'supplier_import_dispatch_recovery_results'
                        AND CONSTRAINT_NAME = 'fk_import_recovery_result_complete_auth_tuple'
                        AND REFERENCED_TABLE_NAME = 'supplier_import_dispatch_recovery_authorizations'
                        AND UPDATE_RULE = 'RESTRICT'
                        AND DELETE_RULE = 'RESTRICT'
                    SQL));

                continue;
            }

            $checks = collect(DB::select(<<<'SQL'
                SELECT cc.CHECK_CLAUSE AS expression
                FROM information_schema.TABLE_CONSTRAINTS tc
                INNER JOIN information_schema.CHECK_CONSTRAINTS cc
                    ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
                    AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
                WHERE tc.CONSTRAINT_SCHEMA = DATABASE() AND tc.TABLE_NAME = ?
                SQL, [$row['table_name']]))->pluck('expression');
            $this->assertTrue($checks->contains(
                static fn (string $expression): bool => str_contains($expression, '`'.$row['column_name'].'`')
                    && str_contains(strtolower($expression), 'regexp_like'),
            ), 'Missing exact hexadecimal CHECK coverage for '.$row['table_name'].'.'.$row['column_name']);
        }
    }

    private function assertPristineMonitor(): void
    {
        $row = (array) DB::table('supplier_import_dispatch_monitor_health')->sole();
        $this->assertSame(1, (int) $row['id']);
        $this->assertSame('supplier-import-dispatch-watchdog-v1', $row['monitor_identity']);
        $this->assertSame('supplier-import-dispatch-observer-v1', $row['observer_identity']);
        $this->assertSame('unknown', $row['integrity_state']);
        foreach ([
            'monitor_generation', 'last_successful_monitor_generation',
            'cycle_sequence', 'observer_sequence', 'observed_monitor_generation',
            'observed_cycle_sequence',
        ] as $column) {
            $this->assertSame(0, (int) $row[$column]);
        }
        foreach ([
            'monitor_owner_token_hash', 'monitor_lease_acquired_at',
            'monitor_lease_expires_at', 'last_successful_cycle_at',
            'last_successful_sink_health_at', 'last_successful_sink_contract_key',
            'last_successful_observer_at', 'last_failure_code',
        ] as $column) {
            $this->assertNull($row[$column]);
        }
    }

    /** @return array<string, int> */
    private function seedParentFixture(string $suffix = 'primary', string $connection = 'mysql'): array
    {
        $db = DB::connection($connection);
        $now = Carbon::parse('2026-08-20 08:00:00', 'UTC');
        $supplierId = $db->table('suppliers')->insertGetId([
            'company_name' => 'Schema Supplier '.$suffix,
            'slug' => 'schema-supplier-'.$suffix.'-'.strtolower(bin2hex(random_bytes(3))),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $feedId = $db->table('supplier_feeds')->insertGetId([
            'supplier_id' => $supplierId,
            'feed_name' => 'Schema Feed '.$suffix,
            'feed_url' => 'https://example.test/'.$suffix.'.xml',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $jobId = $db->table('import_jobs')->insertGetId([
            'supplier_id' => $supplierId,
            'supplier_feed_id' => $feedId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $runId = $db->table('supplier_import_runs')->insertGetId([
            'supplier_id' => $supplierId,
            'supplier_feed_id' => $feedId,
            'import_job_id' => $jobId,
            'trigger_type' => 'manual',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $historyId = $db->table('import_histories')->insertGetId([
            'import_job_id' => $jobId,
            'supplier_id' => $supplierId,
            'supplier_feed_id' => $feedId,
            'event' => 'started',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return compact('supplierId', 'feedId', 'jobId', 'runId', 'historyId') + [
            'supplier_id' => $supplierId,
            'feed_id' => $feedId,
            'job_id' => $jobId,
            'run_id' => $runId,
            'history_id' => $historyId,
        ];
    }

    /** @return array<string, int> */
    private function seedProtectedGraph(string $connection = 'mysql'): array
    {
        $db = DB::connection($connection);
        $parents = $this->seedParentFixture('graph', $connection);
        $userId = $db->table('users')->insertGetId([
            'name' => 'Schema Operator',
            'email' => 'schema-operator-'.strtolower(bin2hex(random_bytes(4))).'@example.test',
            'password' => 'not-used',
            'created_at' => '2026-08-20 08:00:00',
            'updated_at' => '2026-08-20 08:00:00',
        ]);
        $logicalKey = str_repeat('a', 64);
        $claimId = $db->table('supplier_import_execution_claims')->insertGetId([
            'logical_execution_key' => $logicalKey,
            'supplier_id' => $parents['supplier_id'],
            'supplier_feed_id' => $parents['feed_id'],
            'import_job_id' => $parents['job_id'],
            'allocated_at' => '2026-08-20 08:00:00.000000',
            'import_history_id' => $parents['history_id'],
            'execution_path' => 'legacy_xml',
        ]);
        $outboxId = $db->table('supplier_import_dispatch_outbox')->insertGetId([
            'supplier_import_execution_claim_id' => $claimId,
            'logical_execution_key' => $logicalKey,
            'event_type' => 'initial_dispatch',
            'job_type' => 'process_xml_supplier_feed',
            'dispatch_payload' => '{}',
            'dispatch_payload_hash' => str_repeat('b', 64),
            'transport_deadline_at' => '2026-08-21 08:00:00.000000',
            'created_at' => '2026-08-20 08:00:00.000000',
            'updated_at' => '2026-08-20 08:00:00.000000',
        ]);
        $alertId = $db->table('supplier_import_dispatch_alert_intents')->insertGetId([
            'alert_identity' => str_repeat('c', 64),
            'schema_version' => 'supplier-import-dispatch-alert-v1',
            'alert_type' => 'dispatch_watchdog_overdue',
            'dispatch_outbox_id' => $outboxId,
            'delivery_watchdog_at' => '2026-08-20 09:00:00.000000',
            'severity' => 'warning',
            'payload' => '{}',
            'next_attempt_at' => '2026-08-20 09:00:00.000000',
        ]);
        $authorizationId = $db->table('supplier_import_dispatch_recovery_authorizations')->insertGetId([
            'supplier_import_execution_claim_id' => $claimId,
            'supplier_import_dispatch_outbox_id' => $outboxId,
            'logical_execution_key' => $logicalKey,
            'target_parent_type' => 'supplier_feed',
            'target_parent_id' => $parents['feed_id'],
            'authorization_action' => 'republish_same_key',
            'expected_state_fingerprint' => str_repeat('d', 64),
            'canonical_reason_code' => 'dispatch_durable_progress_stalled',
            'authorized_operator_id' => $userId,
            'authorized_at' => '2026-08-20 08:00:00.000000',
            'expires_at' => '2026-08-20 08:15:00.000000',
            'authorization_nonce_hash' => str_repeat('e', 64),
        ]);
        $resultId = $db->table('supplier_import_dispatch_recovery_results')->insertGetId([
            'supplier_import_dispatch_recovery_authorization_id' => $authorizationId,
            'authorization_action' => 'republish_same_key',
            'authorized_operator_id' => $userId,
            'supplier_import_execution_claim_id' => $claimId,
            'supplier_import_dispatch_outbox_id' => $outboxId,
            'logical_execution_key' => $logicalKey,
            'target_parent_type' => 'supplier_feed',
            'target_parent_id' => $parents['feed_id'],
            'event_sequence' => 1,
            'event_kind' => 'started',
            'canonical_result_code' => 'authorization_attempt_started',
            'resume_state_fingerprint' => str_repeat('f', 64),
            'occurred_at' => '2026-08-20 08:01:00.000000',
            'result_fingerprint' => str_repeat('1', 64),
        ]);
        $cohortMemberId = $db->table('supplier_import_cohort_authorization_members')->insertGetId([
            'supplier_import_execution_claim_id' => $claimId,
            'supplier_sku_hash' => str_repeat('2', 64),
        ]);
        $generationId = $db->table('supplier_offer_snapshot_generations')->insertGetId([
            'supplier_id' => $parents['supplier_id'],
            'supplier_key' => 'schema-supplier-v1',
            'supplier_feed_id' => $parents['feed_id'],
            'supplier_import_execution_claim_id' => $claimId,
            'import_history_id' => $parents['history_id'],
            'schema_version' => 'supplier-offer-snapshot-v1',
            'producer_version' => 'schema-test-v1',
            'qualification_policy_key' => 'qualification-v1',
            'capture_integrity_policy_key' => 'capture-integrity-v1',
            'policy_versions' => '{}',
            'source_identity' => 'snapshot-source-v1:synthetic:fixture-a',
            'source_fingerprint' => str_repeat('3', 64),
            'captured_at' => '2026-08-20T08:05:00+00:00',
            'capture_started_at' => '2026-08-20T08:00:00+00:00',
            'capture_completed_at' => '2026-08-20T08:05:00+00:00',
            'capture_outcome' => 'failed',
            'capture_failure_reason_code' => 'synthetic_failure',
            'qualification_state' => 'frozen',
            'qualification_reason_codes' => '["synthetic_failure"]',
            'minimum_product_count' => 1,
            'maximum_product_drop_percent' => 40,
            'generation_fingerprint' => str_repeat('4', 64),
        ]);
        $enrollmentId = $db->table('supplier_offer_snapshot_enrollments')->insertGetId([
            'supplier_id' => $parents['supplier_id'],
            'supplier_feed_id' => $parents['feed_id'],
            'source_identity' => 'snapshot-source-v1:synthetic:fixture-a',
            'supplier_sku_hash' => str_repeat('2', 64),
            'effective_import_history_id' => $parents['history_id'],
            'enrollment_source' => 'capture_start_seed',
            'enrollment_fingerprint' => str_repeat('5', 64),
            'enrolled_at' => '2026-08-20T08:05:00+00:00',
        ]);
        $observationId = $db->table('supplier_offer_snapshot_observations')->insertGetId([
            'snapshot_generation_id' => $generationId,
            'snapshot_enrollment_id' => $enrollmentId,
            'supplier_sku_hash' => str_repeat('2', 64),
            'present' => false,
            'observation_fingerprint' => str_repeat('6', 64),
        ]);

        return $parents + [
            'user_id' => $userId,
            'claim_id' => $claimId,
            'outbox_id' => $outboxId,
            'alert_id' => $alertId,
            'authorization_id' => $authorizationId,
            'result_id' => $resultId,
            'cohort_member_id' => $cohortMemberId,
            'generation_id' => $generationId,
            'enrollment_id' => $enrollmentId,
            'observation_id' => $observationId,
        ];
    }

    private function assertParentDeleteWinsRace(PDO $pdoA, PDO $pdoB): void
    {
        $fixture = $this->seedRaceJobFixture('parent-delete-wins');
        $claim = $this->raceClaimValues($fixture, 'parent-delete-wins');

        try {
            $pdoA->beginTransaction();
            $delete = $pdoA->prepare('DELETE FROM import_jobs WHERE id = ?');
            $delete->execute([$fixture['job_id']]);
            $this->assertSame(1, $delete->rowCount());

            $pdoB->beginTransaction();
            $this->assertPdoMysqlError(
                fn () => $this->insertRaceClaim($pdoB, $claim),
                1205,
                'HY000',
            );
            $pdoB->rollBack();
            $pdoA->commit();

            $this->assertPdoMysqlError(
                fn () => $this->insertRaceClaim($pdoB, $claim),
                1452,
                '23000',
            );
            $this->assertSame(0, DB::table('import_jobs')->where('id', $fixture['job_id'])->count());
            $this->assertSame(0, DB::table('supplier_import_execution_claims')
                ->where('logical_execution_key', $claim['logical_execution_key'])->count());
        } finally {
            $this->rollbackPdo($pdoA);
            $this->rollbackPdo($pdoB);
        }
    }

    private function assertChildInsertWinsRace(PDO $pdoA, PDO $pdoB): void
    {
        $fixture = $this->seedRaceJobFixture('child-insert-wins');
        $claim = $this->raceClaimValues($fixture, 'child-insert-wins');

        try {
            $pdoA->beginTransaction();
            $this->insertRaceClaim($pdoA, $claim);

            $pdoB->beginTransaction();
            $this->assertPdoMysqlError(
                function () use ($pdoB, $fixture): void {
                    $statement = $pdoB->prepare('DELETE FROM import_jobs WHERE id = ?');
                    $statement->execute([$fixture['job_id']]);
                },
                1205,
                'HY000',
            );
            $pdoB->rollBack();
            $pdoA->commit();

            $this->assertPdoMysqlError(
                function () use ($pdoB, $fixture): void {
                    $statement = $pdoB->prepare('DELETE FROM import_jobs WHERE id = ?');
                    $statement->execute([$fixture['job_id']]);
                },
                1451,
                '23000',
            );
            $this->assertSame(1, DB::table('import_jobs')->where('id', $fixture['job_id'])->count());
            $this->assertSame(1, DB::table('supplier_import_execution_claims')
                ->where('logical_execution_key', $claim['logical_execution_key'])->count());
        } finally {
            $this->rollbackPdo($pdoA);
            $this->rollbackPdo($pdoB);
        }
    }

    /** @return array{supplier_id: int, feed_id: int, job_id: int} */
    private function seedRaceJobFixture(string $suffix): array
    {
        $now = '2026-08-20 08:00:00';
        $supplierId = DB::table('suppliers')->insertGetId([
            'company_name' => 'Race Supplier '.$suffix,
            'slug' => 'race-supplier-'.$suffix.'-'.strtolower(bin2hex(random_bytes(3))),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $feedId = DB::table('supplier_feeds')->insertGetId([
            'supplier_id' => $supplierId,
            'feed_name' => 'Race Feed '.$suffix,
            'feed_url' => 'https://example.test/race-'.$suffix.'.xml',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $jobId = DB::table('import_jobs')->insertGetId([
            'supplier_id' => $supplierId,
            'supplier_feed_id' => $feedId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'supplier_id' => $supplierId,
            'feed_id' => $feedId,
            'job_id' => $jobId,
        ];
    }

    /**
     * @param  array{supplier_id: int, feed_id: int, job_id: int}  $fixture
     * @return array{logical_execution_key: string, supplier_id: int, feed_id: int, job_id: int}
     */
    private function raceClaimValues(array $fixture, string $suffix): array
    {
        return $fixture + [
            'logical_execution_key' => hash('sha256', 'canonical-schema-race-'.$suffix),
        ];
    }

    /** @param array{logical_execution_key: string, supplier_id: int, feed_id: int, job_id: int} $claim */
    private function insertRaceClaim(PDO $pdo, array $claim): void
    {
        $statement = $pdo->prepare(<<<'SQL'
            INSERT INTO supplier_import_execution_claims (
                logical_execution_key, supplier_id, supplier_feed_id,
                import_job_id, allocated_at, execution_path
            ) VALUES (?, ?, ?, ?, '2026-08-20 08:00:00.000000', 'legacy_xml')
            SQL);
        $statement->execute([
            $claim['logical_execution_key'],
            $claim['supplier_id'],
            $claim['feed_id'],
            $claim['job_id'],
        ]);
    }

    private function assertPdoMysqlError(callable $operation, int $driverCode, string $sqlState): void
    {
        try {
            $operation();
        } catch (PDOException $exception) {
            $this->assertSame($sqlState, (string) ($exception->errorInfo[0] ?? ''));
            $this->assertSame($driverCode, (int) ($exception->errorInfo[1] ?? 0));

            return;
        }

        $this->fail(sprintf('Expected MySQL error %d (%s).', $driverCode, $sqlState));
    }

    private function rollbackPdo(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    private function assertQueryRejected(callable $operation, string $messageFragment): void
    {
        try {
            $operation();
            $this->fail('Expected MySQL to reject the operation.');
        } catch (QueryException $exception) {
            $this->assertStringContainsStringIgnoringCase($messageFragment, $exception->getMessage());
        }
    }

    private function createTemporaryDatabase(string $database): void
    {
        DB::statement(sprintf(
            'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $database,
        ));
    }

    private function dropTemporaryDatabase(string $database): void
    {
        try {
            DB::statement(sprintf('DROP DATABASE IF EXISTS `%s`', $database));
        } catch (Throwable) {
            // Preserve the primary test failure when cleanup itself cannot connect.
        }
    }

    private function recreateTemporaryDatabase(string $database): void
    {
        DB::disconnect('snapshot_schema_phase_i');
        DB::purge('snapshot_schema_phase_i');
        $this->dropTemporaryDatabase($database);
        $this->createTemporaryDatabase($database);
        $this->configureTemporaryConnection($database);
        $this->resetDownGuard();
    }

    private function configureTemporaryConnection(string $database): void
    {
        config(['database.connections.snapshot_schema_phase_i' => array_merge(
            config('database.connections.mysql'),
            ['database' => $database],
        )]);
        DB::purge('snapshot_schema_phase_i');
    }

    private function copyHistoricalMigrations(string $path): void
    {
        File::deleteDirectory($path);
        File::ensureDirectoryExists($path, 0700);

        foreach (File::files(database_path('migrations')) as $migration) {
            if (str_starts_with($migration->getFilename(), '2026_08_20_12')) {
                continue;
            }
            File::copy($migration->getPathname(), $path.DIRECTORY_SEPARATOR.$migration->getFilename());
        }
    }

    private function migrateHistoricalThenPhase(string $historicalPath): void
    {
        $this->assertSame(0, Artisan::call('migrate', [
            '--database' => 'snapshot_schema_phase_i',
            '--path' => $historicalPath,
            '--realpath' => true,
            '--force' => true,
        ]), Artisan::output());
        $this->assertSame(0, Artisan::call('migrate', [
            '--database' => 'snapshot_schema_phase_i',
            '--path' => database_path('migrations'),
            '--realpath' => true,
            '--force' => true,
        ]), Artisan::output());
    }

    private function assertGuardRejectedContaining(string $fragment): void
    {
        $this->assertStringContainsString($fragment, $this->guardRejectionMessage());
    }

    private function guardRejectionMessage(): string
    {
        try {
            $exitCode = Artisan::call('migrate:rollback', [
                '--database' => 'snapshot_schema_phase_i',
                '--force' => true,
            ]);
        } catch (Throwable $exception) {
            return $exception->getMessage();
        }

        $this->fail(sprintf(
            'Expected destructive down guard rejection, got exit code %d: %s',
            $exitCode,
            Artisan::output(),
        ));
    }

    private function resetDownGuard(): void
    {
        $reflection = new ReflectionClass(CanonicalSupplierSnapshotSchema::class);
        $property = $reflection->getProperty('destructiveDownScope');
        $property->setValue(null, null);
    }

    private function issueDownCapability(): string
    {
        $capability = CanonicalSupplierSnapshotSchema::issueDestructiveDownCapability();
        $this->issuedDownCapabilities[] = $capability;
        putenv(self::DOWN_CAPABILITY_ENV.'='.$capability);
        $_ENV[self::DOWN_CAPABILITY_ENV] = $capability;
        $_SERVER[self::DOWN_CAPABILITY_ENV] = $capability;

        return $capability;
    }

    private function clearDownCapabilityEnvironment(): void
    {
        putenv(self::DOWN_CAPABILITY_ENV);
        unset($_ENV[self::DOWN_CAPABILITY_ENV], $_SERVER[self::DOWN_CAPABILITY_ENV]);
    }

    /** @param list<array<string, int|string>> $inventory */
    private static function inventoryHash(array $inventory): string
    {
        return hash('sha256', json_encode($inventory, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private static function normalizeSql(string $sql): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }

    /** @return array<string, string> */
    private function canonicalCreateStatements(): array
    {
        $statements = [];
        foreach (self::CANONICAL_TABLES as $table) {
            $row = (array) DB::selectOne(sprintf('SHOW CREATE TABLE `%s`', $table));
            $statements[$table] = (string) array_values($row)[1];
        }

        return $statements;
    }

    private function indexExists(string $table, string $index): bool
    {
        return (int) DB::scalar(<<<'SQL'
            SELECT COUNT(*)
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
            SQL, [$table, $index]) > 0;
    }
}

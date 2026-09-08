<?php

namespace Tests\Feature;

use App\Data\Suppliers\Imports\CanonicalSupplierImportSourceExecution;
use App\Data\Suppliers\Imports\CanonicalSupplierImportSourcePayloadReceipt as Receipt;
use App\Data\Suppliers\Imports\ImportJobIdentity;
use App\Data\Suppliers\Imports\ResolvedSupplierImportSourceContext;
use App\Data\Suppliers\SourceProfiles\CanonicalSupplierImportMapping;
use App\Data\Suppliers\SourceProfiles\CanonicalSupplierSourceLocator;
use App\Data\Suppliers\SourceProfiles\CanonicalSupplierSourceProfileDescriptor;
use App\Models\ImportHistory;
use App\Models\ImportJob;
use App\Models\Product;
use App\Models\SupplierFeed;
use App\Models\SupplierImportSourceExecution;
use App\Models\SupplierImportSourcePayloadReceipt;
use App\Models\SupplierImportSourceProfile;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Repositories\Suppliers\SupplierImportSourceExecutionRepository;
use App\Repositories\Suppliers\SupplierImportSourcePayloadReceiptRepository;
use Carbon\CarbonImmutable;
use Database\Migrations\Support\CanonicalSupplierPhaseThreeP0Schema as P0;
use Database\Migrations\Support\CanonicalSupplierPhaseThreeP0SchemaException;
use Database\Migrations\Support\CanonicalSupplierPhaseThreeP0SchemaInspector;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Throwable;

require_once __DIR__.'/../../database/migrations/support/CanonicalSupplierPhaseThreeP0Schema.php';

final class PhaseThreeP0SliceThreeMysqlTest extends TestCase
{
    private const TABLE = 'supplier_import_source_payload_receipts';

    private const GRANT = 'SUPPLIER_PHASE_THREE_P0_EMPTY_SCHEMA_DOWN_CONFIRMED';

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Phase III-P0 Slice 3 requires disposable MySQL 8.4.');
        }
        $this->assertStringStartsWith('8.4.', DB::scalar('SELECT VERSION()'));
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->assertP4();
    }

    protected function tearDown(): void
    {
        putenv(self::GRANT);
        unset($_ENV[self::GRANT], $_SERVER[self::GRANT]);
        if (DB::getDriverName() === 'mysql') {
            foreach (array_keys(DB::getConnections()) as $name) {
                DB::purge($name);
            }
            $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        }
        parent::tearDown();
    }

    public function test_fresh_p4_and_existing_data_p3_to_p4_preserve_every_parent_and_super_admin(): void
    {
        $migration = $this->migration();
        $this->authorizeDown();
        $migration->down();
        $this->assertGrantConsumed();
        $this->assertSame('P3', P0::classify(DB::connection()->getPdo())['state']);
        $execution = $this->execution();
        Product::factory()->create();
        SupplierProduct::query()->create([
            'supplier_id' => $execution->supplier_id,
            'supplier_feed_id' => $execution->supplier_feed_id,
            'supplier_sku' => 'synthetic-slice-three',
            'raw_data' => ['synthetic' => true],
            'payload_hash' => hash('sha256', 'synthetic-slice-three'),
            'received_at' => now('UTC'),
        ]);
        $role = Role::findOrCreate(User::ROLE_SUPER_ADMIN, 'web');
        $user = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'email' => 'slice-three-super-admin@example.test',
            'is_active' => true,
            'deleted_at' => null,
        ]);
        $user->assignRole($role);
        $userId = $user->getKey();
        $this->assertSuperAdminAccess($userId);
        $before = $this->protectedRows();
        $migration->up();
        $this->assertP4();
        $this->assertSame($before, $this->protectedRows());
        $this->assertSuperAdminAccess($userId);
        $this->authorizeDown();
        $migration->down();
        $this->assertGrantConsumed();
        $this->assertSame($before, $this->protectedRows());
        $this->assertSuperAdminAccess($userId);
        $this->assertNotNull(DB::table('supplier_import_source_executions')->find($execution->id));
        $migration->up();
        $this->assertP4();
        $this->assertSame($before, $this->protectedRows());
        $this->assertSuperAdminAccess($userId);
    }

    private function assertSuperAdminAccess(int $userId): void
    {
        $user = User::withTrashed()->with(['roles.permissions', 'permissions'])->findOrFail($userId);

        $this->assertSame($userId, $user->getKey());
        $this->assertSame(User::ROLE_SUPER_ADMIN, $user->role);
        $this->assertSame(User::ROLE_SUPER_ADMIN, $user->primaryRole());
        $this->assertSame([User::ROLE_SUPER_ADMIN], $user->getRoleNames()->all());
        $this->assertTrue($user->is_active);
        $this->assertNull($user->deleted_at);
        $this->assertFalse($user->trashed());
        $this->assertTrue($user->isSuperAdmin());
        $this->assertTrue($user->canAccessPanel(filament()->getPanel('admin')));
        $this->assertTrue($user->canManageUsers());
    }

    public function test_mysql_constraints_and_composite_execution_binding_fail_without_parent_mutation(): void
    {
        $execution = $this->execution();
        $other = $this->execution();
        $valid = $this->receipt($execution)->persistenceAttributes();
        $before = $this->protectedRows();
        $invalid = [
            [...$valid, 'supplier_import_source_execution_id' => $other->id],
            [...$valid, 'supplier_import_source_execution_id' => 9999999],
            [...$valid, 'receipt_version' => 'unknown'],
        ];
        foreach ([0, -1, null, '18446744073709551616', 'invalid'] as $size) {
            $invalid[] = [...$valid, 'accepted_payload_bytes' => $size];
        }
        foreach (['source_execution_fingerprint', 'accepted_payload_sha256', 'payload_receipt_fingerprint'] as $field) {
            foreach ([null, '', str_repeat('A', 64), str_repeat('g', 64), str_repeat('a', 63), str_repeat('a', 65)] as $value) {
                $invalid[] = [...$valid, $field => $value];
            }
        }
        foreach ($invalid as $attributes) {
            try {
                DB::transaction(fn () => DB::table(self::TABLE)->insert($attributes));
                $this->fail('Invalid receipt SQL accepted.');
            } catch (QueryException) {
                $this->assertSame(0, DB::table(self::TABLE)->count());
                $this->assertSame($before, $this->protectedRows());
            }
        }
    }

    public function test_exact_insert_reuse_conflicts_and_append_only_sql_and_model_paths(): void
    {
        $execution = $this->execution();
        $value = $this->receipt($execution);
        $product = Product::factory()->create();
        SupplierProduct::query()->create([
            'supplier_id' => $execution->supplier_id,
            'supplier_feed_id' => $execution->supplier_feed_id,
            'product_id' => $product->id,
            'supplier_sku' => 'synthetic-receipt-preservation',
            'raw_data' => ['synthetic' => true],
            'payload_hash' => hash('sha256', 'synthetic-receipt-preservation'),
            'received_at' => now('UTC'),
        ]);
        $before = $this->protectedRows();
        $first = $this->persist($execution, $value);
        $stored = (array) DB::table(self::TABLE)->first();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $second = $this->persist($execution, $value);
        $this->assertSame($first->getRawOriginal(), $second->getRawOriginal());
        $this->assertSame($first->created_at->format('Y-m-d H:i:s.u'), $second->created_at->format('Y-m-d H:i:s.u'));
        $this->assertSame(DB::getDefaultConnection(), $first->getConnectionName());
        $this->assertSame($execution->id, $first->sourceExecution->id);
        $this->assertInstanceOf(CarbonImmutable::class, $first->created_at);
        $locks = array_values(array_filter($queries, fn ($sql) => str_contains($sql, 'for update')));
        $this->assertStringContainsString('supplier_import_source_executions', $locks[0]);
        $this->assertStringContainsString(self::TABLE, $locks[1]);
        foreach (['supplier_feeds', 'xml_mapping_templates', 'import_jobs'] as $selector) {
            $this->assertFalse((bool) array_filter($queries, fn ($sql) => str_contains($sql, $selector)));
        }
        foreach ([['accepted_payload_bytes' => 5], ['accepted_payload_sha256' => str_repeat('b', 64)]] as $change) {
            $this->assertFailure('payload_receipt_identity_conflict', fn () => $this->persist($execution, Receipt::fromArray([...$value->toCanonicalArray(), ...$change])));
        }
        foreach ([
            fn () => DB::table(self::TABLE)->where('id', $first->id)->update(['accepted_payload_bytes' => 5]),
            fn () => DB::table(self::TABLE)->where('id', $first->id)->delete(),
            fn () => DB::statement('REPLACE INTO '.self::TABLE.' (supplier_import_source_execution_id, source_execution_fingerprint, receipt_version, accepted_payload_bytes, accepted_payload_sha256, payload_receipt_fingerprint) VALUES (?, ?, ?, ?, ?, ?)', array_values($value->persistenceAttributes())),
        ] as $mutation) {
            try {
                DB::transaction($mutation);
                $this->fail('Append-only SQL accepted.');
            } catch (QueryException $exception) {
                $this->assertSame('45000', $exception->errorInfo[0]);
            }
            $this->assertSame($stored, (array) DB::table(self::TABLE)->first());
        }
        foreach (['save', 'saveQuietly', 'saveOrFail', 'touch', 'touchQuietly', 'delete', 'deleteQuietly', 'deleteOrFail', 'forceDelete', 'update', 'updateQuietly', 'updateOrFail'] as $method) {
            try {
                $first->{$method}();
                $this->fail('Append-only model mutation accepted.');
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(1, DB::table(self::TABLE)->count());
        $this->assertSame($stored, (array) DB::table(self::TABLE)->first());
        $this->assertSame($before, $this->protectedRows());
    }

    public function test_repository_requires_explicit_transaction_persisted_binding_and_canonical_parent(): void
    {
        $execution = $this->execution();
        $value = $this->receipt($execution);
        $repository = new SupplierImportSourcePayloadReceiptRepository;
        $this->assertFailure('payload_receipt_mysql_transaction_required', fn () => $repository->resolveOrInsertWithinTransaction(DB::connection(), $execution, $value));
        $otherConnection = $this->independentConnection();
        $this->assertFailure('payload_receipt_execution_binding_mismatch', fn () => $otherConnection->transaction(fn () => $repository->resolveOrInsertWithinTransaction($otherConnection, $execution, $value)));
        foreach (['new', 'unbound', 'dirty', 'missing', 'fingerprint', 'canonical'] as $case) {
            $candidate = clone $execution;
            $candidateValue = $value;
            if ($case === 'new') {
                $candidate->exists = false;
            } elseif ($case === 'unbound') {
                $candidate->setConnection(null);
            } elseif ($case === 'dirty') {
                $candidate->setAttribute('importer_key', 'changed');
            } elseif ($case === 'missing') {
                $candidate->setRawAttributes([...$candidate->getRawOriginal(), 'id' => 9999999], true);
                $candidateValue = Receipt::fromArray([...$value->toCanonicalArray(), 'supplier_import_source_execution_id' => 9999999]);
            } elseif ($case === 'fingerprint') {
                $candidateValue = Receipt::fromArray([...$value->toCanonicalArray(), 'source_execution_fingerprint' => str_repeat('b', 64)]);
            } else {
                $candidate->setRawAttributes([...$candidate->getRawOriginal(), 'importer_key' => 'changed'], true);
            }
            $this->assertFailure($case === 'canonical' ? 'source_execution_fingerprint_collision' : 'payload_receipt_execution_binding_mismatch', fn () => $this->persist($candidate, $candidateValue));
            $this->assertSame(0, DB::table(self::TABLE)->count());
        }
        // A SQL-valid forged parent is not canonical authority merely because its hash has 64 hex bytes.
        $forged = $this->execution(forgeFingerprint: true);
        $this->assertFailure('source_execution_fingerprint_collision', fn () => $this->persist($forged, $this->receipt($forged)));
        $this->assertSame(0, DB::table(self::TABLE)->count());
    }

    public function test_corrupt_receipt_fingerprint_is_not_accepted_as_an_exact_duplicate(): void
    {
        $execution = $this->execution();
        $value = $this->receipt($execution);
        DB::table(self::TABLE)->insert([...$value->persistenceAttributes(), 'payload_receipt_fingerprint' => str_repeat('b', 64)]);
        $before = (array) DB::table(self::TABLE)->first();
        $this->assertFailure('payload_receipt_identity_conflict', fn () => $this->persist($execution, $value));
        $this->assertSame($before, (array) DB::table(self::TABLE)->first());
    }

    public function test_caller_rollback_visibility_retry_and_lost_response_reconciliation_ignore_live_selectors(): void
    {
        $execution = $this->execution();
        $value = $this->receipt($execution);
        $observer = $this->independentConnection();
        $before = $this->protectedRows();
        try {
            DB::transaction(function () use ($execution, $value, $observer): void {
                (new SupplierImportSourcePayloadReceiptRepository)->resolveOrInsertWithinTransaction(DB::connection(), $execution, $value);
                $this->assertSame(1, DB::table(self::TABLE)->count());
                $this->assertSame(0, $observer->table(self::TABLE)->count());
                throw new RuntimeException('synthetic_caller_failure');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic_caller_failure', $exception->getMessage());
        }
        $this->assertSame(0, DB::table(self::TABLE)->count());
        $this->assertSame($before, $this->protectedRows());
        DB::table('supplier_feeds')->where('id', $execution->supplier_feed_id)->update(['status' => 'inactive', 'mapping' => '{"changed":true}']);
        DB::table('import_jobs')->where('id', $execution->import_job_id)->update(['type' => 'xml']);
        $afterSelectorChange = $this->protectedRows();
        $first = $this->persist($execution, $value);
        // Commit succeeded, but the caller receives no result and reconciles using the same identity.
        $second = $this->persist($execution, $value);
        $this->assertSame($first->getRawOriginal(), $second->getRawOriginal());
        $this->assertSame(1, $observer->table(self::TABLE)->count());
        $this->assertSame($afterSelectorChange, $this->protectedRows());
        $this->assertSame(0, DB::connection()->transactionLevel());
    }

    public function test_two_independent_mysql_processes_reuse_exact_receipt(): void
    {
        $this->race(conflicting: false);
    }

    public function test_unreadable_reconciliation_is_sanitized_and_never_becomes_duplicate_success(): void
    {
        $execution = $this->execution();
        $value = $this->receipt($execution);
        $first = $this->persist($execution, $value);
        $before = $this->protectedRows();
        $connection = $this->independentConnection();
        $bound = (new SupplierImportSourceExecution)->setConnection($connection->getName())->newFromBuilder($execution->getRawOriginal());
        $connection->beforeExecuting(static function (): void {
            $error = new PDOException('synthetic transport failure');
            $error->errorInfo = ['08S01', 2013, 'synthetic transport failure'];
            throw $error;
        });
        $this->assertFailure('payload_receipt_persistence_failed', fn () => $connection->transaction(
            fn () => (new SupplierImportSourcePayloadReceiptRepository)->resolveOrInsertWithinTransaction($connection, $bound, $value),
        ));
        DB::purge($connection->getName());
        $this->assertSame($before, $this->protectedRows());
        $this->assertSame($first->getRawOriginal(), $this->persist($execution, $value)->getRawOriginal());
        $this->assertSame(1, DB::table(self::TABLE)->count());
    }

    public function test_uncertain_downgrade_after_real_ddl_preserves_parent_evidence_and_consumes_grant(): void
    {
        $this->execution();
        $before = $this->protectedRows();
        Log::spy();
        // Inject a lost acknowledgement after real test-only DROP, not a successful downgrade.
        DB::extend('phase_three_p0_schema_ddl', static function (array $config, string $name): MySqlConnection {
            $pdo = new class($config) extends PDO
            {
                private bool $lost = false;

                public function __construct(array $config)
                {
                    parent::__construct(
                        'mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'].';charset=utf8mb4',
                        $config['username'], $config['password'],
                        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => false],
                    );
                }

                public function exec(string $statement): int|false
                {
                    $result = parent::exec($statement);
                    if (str_starts_with($statement, 'DROP TABLE')) {
                        $this->lost = true;
                        $this->failTransport();
                    }

                    return $result;
                }

                public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
                {
                    if ($this->lost) {
                        $this->failTransport();
                    }

                    return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
                }

                private function failTransport(): never
                {
                    $error = new PDOException('synthetic lost DDL acknowledgement');
                    $error->errorInfo = ['08S01', 2013, 'synthetic lost DDL acknowledgement'];
                    throw $error;
                }
            };

            return new MySqlConnection($pdo, $config['database'], '', [...$config, 'name' => $name]);
        });
        try {
            $this->authorizeDown();
            $this->assertFailure('phase_three_p0_connection_outcome_unknown', fn () => $this->migration()->down());
            $this->assertGrantConsumed();
        } finally {
            DB::forgetExtension('phase_three_p0_schema_ddl');
            DB::purge('phase_three_p0_schema_ddl');
        }
        Log::assertLogged('info', fn ($message, $context) => $message === 'phase_three_p0_downgrade_evidence_v1'
            && $context['primary_outcome'] === 'phase_three_p0_connection_outcome_unknown'
            && $context['connection_status'] === 'UNCERTAIN'
            && $context['completed_ddl_ids'] === []);
        $this->assertSame('P3', P0::classify(DB::connection()->getPdo())['state']);
        $this->assertSame($before, $this->protectedRows());
        $this->migration()->up();
        $this->assertP4();
        $this->assertFailure('phase_three_p0_downgrade_not_authorized', fn () => $this->migration()->down());
        $this->assertP4();
        $this->authorizeDown();
        $this->migration()->down();
        $this->assertGrantConsumed();
        $this->assertSame($before, $this->protectedRows());
    }

    public function test_two_independent_mysql_processes_have_one_winner_and_one_rejected_conflict(): void
    {
        $this->race(conflicting: true);
    }

    public function test_terminal_downgrade_consumes_grant_on_success_rejection_and_changed_gates(): void
    {
        $migration = $this->migration();
        Log::spy();
        $this->assertFailure('phase_three_p0_downgrade_not_authorized', fn () => $migration->down());
        $this->authorizeDown();
        $migration->down();
        $this->assertGrantConsumed();
        $migration->up();
        $execution = $this->execution();
        $this->persist($execution, $this->receipt($execution));
        $before = $this->protectedRows();
        $receiptBefore = (array) DB::table(self::TABLE)->first();
        $this->assertFailure('phase_three_p0_downgrade_not_authorized', fn () => $migration->down());
        $this->authorizeDown();
        $this->assertFailure('phase_three_p0_append_only_table_not_pristine', fn () => $migration->down());
        $this->assertGrantConsumed();
        $this->assertFailure('phase_three_p0_downgrade_not_authorized', fn () => $migration->down());
        foreach (['capture_enabled', 'protected_generation_admission_enabled', 'recovery_issuance_enabled', 'recovery_execution_enabled', 'monitor_schedule_enabled', 'observer_schedule_enabled', 'alert_delivery_enabled'] as $gate) {
            config()->set('supplier_snapshot_capture.'.$gate, true);
            $this->authorizeDown();
            $this->assertFailure('phase_three_p0_protected_gate_enabled', fn () => $migration->down());
            $this->assertGrantConsumed();
            config()->set('supplier_snapshot_capture.'.$gate, false);
        }
        $this->assertP4();
        $this->assertSame($before, $this->protectedRows());
        $this->assertSame($receiptBefore, (array) DB::table(self::TABLE)->first());
        Log::assertLogged('info', fn ($message, $context) => $message === 'phase_three_p0_downgrade_evidence_v1' && $context['primary_outcome'] === 'phase_three_p0_append_only_table_not_pristine' && $context['completed_ddl_ids'] === []);
    }

    public function test_malformed_nonterminal_and_session_drift_are_rejected_before_ddl(): void
    {
        $migration = $this->migration();
        $this->assertFailure('phase_three_p0_forward_precondition_failed', fn () => $migration->up());
        $p03 = require database_path('migrations/2026_08_28_090002_create_supplier_import_source_executions_table.php');
        $this->authorizeDown();
        $this->assertFailure('phase_three_p0_step_not_terminal', fn () => $p03->down());
        $this->assertGrantConsumed();
        DB::unprepared('ALTER TABLE '.self::TABLE.' ADD COLUMN unexpected int NULL');
        $this->authorizeDown();
        $this->assertFailure('UNCLASSIFIED_P0_SCHEMA_STATE', fn () => $migration->down());
        $this->assertGrantConsumed();
        $this->assertTrue(Schema::hasColumn(self::TABLE, 'unexpected'));
        DB::unprepared('ALTER TABLE '.self::TABLE.' DROP COLUMN unexpected');
        $this->assertP4();
        $original = DB::selectOne('SHOW CREATE TRIGGER trg_import_source_payload_receipt_no_delete');
        $create = (array) $original;
        $sql = $create['SQL Original Statement'];
        DB::unprepared('DROP TRIGGER trg_import_source_payload_receipt_no_delete');
        $this->authorizeDown();
        $this->assertFailure('UNCLASSIFIED_P0_SCHEMA_STATE', fn () => $migration->down());
        $this->assertGrantConsumed();
        // Recreate the same body in a deliberately noncanonical trigger creation session.
        DB::unprepared($sql);
        $this->authorizeDown();
        $this->assertFailure('UNCLASSIFIED_P0_SCHEMA_STATE', fn () => $migration->down());
        $this->assertGrantConsumed();
        $this->assertTrue(Schema::hasTable(self::TABLE));
    }

    private function assertP4(): void
    {
        $state = P0::classify(DB::connection()->getPdo());
        $this->assertSame('P4', $state['state']);
        $this->assertSame('NORMAL_PREFIX', $state['classification']);
        $this->assertSame(294, $state['object_count']);
        $this->assertSame('1fc28641da815cd4737b50b5b8ed065918b3f416f970ba2cccef82e5342e8d57', $state['sha256']);
        $types = array_count_values(array_column((new CanonicalSupplierPhaseThreeP0SchemaInspector(DB::connection()->getPdo()))->enumerate(), 'type'));
        ksort($types);
        $this->assertSame(['check' => 46, 'column' => 151, 'foreign_key' => 21, 'index' => 60, 'table' => 7, 'trigger' => 9], $types);
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_08_28_090003_create_supplier_import_source_payload_receipts_table.php');
    }

    private function authorizeDown(): void
    {
        putenv(self::GRANT.'=true');
        $_ENV[self::GRANT] = $_SERVER[self::GRANT] = 'true';
    }

    private function assertGrantConsumed(): void
    {
        $this->assertFalse(getenv(self::GRANT));
        $this->assertArrayNotHasKey(self::GRANT, $_ENV);
        $this->assertArrayNotHasKey(self::GRANT, $_SERVER);
    }

    private function assertFailure(string $code, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Required rejection did not occur: '.$code);
        } catch (RuntimeException $exception) {
            $this->assertSame($code, $exception instanceof CanonicalSupplierPhaseThreeP0SchemaException ? $exception->primaryCode : $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    private function independentConnection(): Connection
    {
        config()->set('database.connections.slice_three_observer', config('database.connections.'.DB::getDefaultConnection()));

        return DB::connection('slice_three_observer');
    }

    /** @return array<string, array> */
    private function protectedRows(): array
    {
        $rows = [];
        foreach (['supplier_import_source_executions', 'supplier_import_source_profiles', 'supplier_feeds', 'suppliers', 'import_jobs', 'import_histories', 'supplier_import_execution_claims', 'products', 'supplier_products', 'product_supplier_offers', 'users', 'roles', 'permissions', 'model_has_roles', 'catalog_sync_batches', 'catalog_sync_logs', 'jobs', 'failed_jobs'] as $table) {
            $rows[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        }

        return $rows;
    }

    private function receipt(SupplierImportSourceExecution $execution, string $payload = "abc\n"): Receipt
    {
        return Receipt::fromArray([
            'schema' => Receipt::VERSION,
            'supplier_import_source_execution_id' => $execution->id,
            'source_execution_fingerprint' => $execution->getRawOriginal('source_execution_fingerprint'),
            'accepted_payload_bytes' => strlen($payload),
            'accepted_payload_sha256' => hash('sha256', $payload),
        ]);
    }

    private function persist(SupplierImportSourceExecution $execution, Receipt $receipt): SupplierImportSourcePayloadReceipt
    {
        return DB::transaction(fn () => (new SupplierImportSourcePayloadReceiptRepository)->resolveOrInsertWithinTransaction(DB::connection(), $execution, $receipt));
    }

    private function execution(bool $forgeFingerprint = false): SupplierImportSourceExecution
    {
        $feed = SupplierFeed::factory()->create(['feed_type' => 'csv', 'status' => 'active', 'mapping' => ['sku' => 'code']]);
        $job = ImportJob::query()->create(['supplier_id' => $feed->supplier_id, 'supplier_feed_id' => $feed->id, 'type' => 'csv', 'mode' => 'queued', 'status' => 'pending']);
        $history = ImportHistory::startForImport($job, 'Synthetic Slice 3 fixture; no import.');
        $locator = CanonicalSupplierSourceLocator::fromArray([
            'schema' => CanonicalSupplierSourceLocator::CONTRACT,
            'source_locator_contract_key' => 'test-source-locator', 'source_locator_contract_version' => '1',
            'scheme' => 'https', 'ascii_host' => 'feed.example.test', 'port' => null,
            'path_components' => [['position' => 0, 'classification' => 'source', 'value' => 'feed-'.$feed->id]],
            'query_components' => [],
        ]);
        $descriptor = CanonicalSupplierSourceProfileDescriptor::fromContracts(
            supplierId: $feed->supplier_id, supplierFeedId: $feed->id, locator: $locator,
            sourceAccessScopeKey: 'source-access-v1:test.feed.'.$feed->id, feedType: 'csv',
            importerKey: 'csv-importer', importerVersion: '1',
            mapping: CanonicalSupplierImportMapping::fromArray(['schema' => CanonicalSupplierImportMapping::VERSION, 'feed_type' => 'csv', 'effective_mapping' => $feed->mapping]),
        );
        $profileId = DB::table('supplier_import_source_profiles')->insertGetId([...$descriptor->persistenceAttributes(), 'source_identity' => 'snapshot-source-v1:profile:'.sprintf('%032x', $feed->id)]);
        $profile = (new SupplierImportSourceProfile)->setConnection(DB::getDefaultConnection())->newFromBuilder((array) DB::table('supplier_import_source_profiles')->find($profileId));
        $canonical = CanonicalSupplierImportSourceExecution::fromContracts(
            ImportJobIdentity::fromArray(['schema' => ImportJobIdentity::VERSION, 'import_job_id' => $job->id, 'supplier_id' => $feed->supplier_id, 'supplier_feed_id' => $feed->id, 'xml_mapping_template_id' => null, 'import_type' => 'csv']),
            ResolvedSupplierImportSourceContext::fromProfile($profile), $history->id, '2026-09-07T10:00:00.123456Z',
        );
        if ($forgeFingerprint) {
            $id = DB::table('supplier_import_source_executions')->insertGetId([...$canonical->persistenceAttributes(), 'source_execution_fingerprint' => str_repeat('f', 64)]);

            return (new SupplierImportSourceExecution)->setConnection(DB::getDefaultConnection())->newFromBuilder((array) DB::table('supplier_import_source_executions')->find($id));
        }

        return DB::transaction(fn () => (new SupplierImportSourceExecutionRepository)->resolveOrInsertWithinTransaction(DB::connection(), $canonical));
    }

    private function race(bool $conflicting): void
    {
        $this->assertTrue(function_exists('pcntl_fork'), 'pcntl_fork is mandatory for real MySQL process evidence.');
        $execution = $this->execution();
        $before = $this->protectedRows();
        $directory = sys_get_temp_dir().'/slice-three-'.bin2hex(random_bytes(12));
        $this->assertTrue(mkdir($directory, 0700));
        $children = [];
        $waited = [];
        foreach (array_keys(DB::getConnections()) as $name) {
            DB::purge($name);
        }
        try {
            foreach ([0, 1] as $index) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('Unable to fork isolated receipt process.');
                }
                if ($pid === 0) {
                    try {
                        DB::statement('SET SESSION innodb_lock_wait_timeout = 20');
                        file_put_contents($directory.'/ready-'.$index, (string) DB::scalar('SELECT CONNECTION_ID()'));
                        $deadline = microtime(true) + 30;
                        while (! file_exists($directory.'/start') && microtime(true) < $deadline) {
                            usleep(1000);
                        }
                        if (! file_exists($directory.'/start')) {
                            exit(2);
                        }
                        $payload = $conflicting && $index === 1 ? "abc\r\n" : "abc\n";
                        $receipt = $this->persist($execution, $this->receipt($execution, $payload));
                        $result = ['status' => 'success', 'row' => $receipt->getRawOriginal()];
                    } catch (RuntimeException $exception) {
                        if ($exception->getMessage() !== 'payload_receipt_identity_conflict') {
                            exit(3);
                        }
                        $result = ['status' => 'conflict'];
                    } catch (Throwable) {
                        exit(4);
                    }
                    file_put_contents($directory.'/result-'.$index, json_encode($result, JSON_THROW_ON_ERROR));
                    exit(0);
                }
                $children[] = $pid;
            }
            $deadline = microtime(true) + 30;
            while ((! file_exists($directory.'/ready-0') || ! file_exists($directory.'/ready-1')) && microtime(true) < $deadline) {
                usleep(1000);
            }
            $this->assertFileExists($directory.'/ready-0');
            $this->assertFileExists($directory.'/ready-1');
            $this->assertNotSame(file_get_contents($directory.'/ready-0'), file_get_contents($directory.'/ready-1'));
            DB::beginTransaction();
            DB::table('supplier_import_source_executions')->where('id', $execution->id)->lockForUpdate()->first();
            touch($directory.'/start');
            $blocked = 0;
            $deadline = microtime(true) + 10;
            do {
                $blocked = (int) DB::scalar('SELECT COUNT(*) FROM performance_schema.data_lock_waits WHERE BLOCKING_THREAD_ID = (SELECT THREAD_ID FROM performance_schema.threads WHERE PROCESSLIST_ID = CONNECTION_ID())');
                if ($blocked < 2) {
                    usleep(1000);
                }
            } while ($blocked < 2 && microtime(true) < $deadline);
            DB::commit();
            $this->assertSame(2, $blocked, 'Both independent processes must contend on the persisted execution lock.');
            foreach ($children as $pid) {
                $deadline = microtime(true) + 30;
                do {
                    $done = pcntl_waitpid($pid, $status, WNOHANG);
                    if ($done === 0) {
                        usleep(1000);
                    }
                } while ($done === 0 && microtime(true) < $deadline);
                $this->assertSame($pid, $done, 'Receipt child timed out.');
                $waited[] = $pid;
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
            $results = [json_decode(file_get_contents($directory.'/result-0'), true, 512, JSON_THROW_ON_ERROR), json_decode(file_get_contents($directory.'/result-1'), true, 512, JSON_THROW_ON_ERROR)];
            $statuses = array_column($results, 'status');
            sort($statuses);
            $this->assertSame($conflicting ? ['conflict', 'success'] : ['success', 'success'], $statuses);
            $stored = (array) DB::table(self::TABLE)->first();
            foreach ($results as $result) {
                if ($result['status'] === 'success') {
                    $this->assertSame($stored, $result['row']);
                }
            }
            $this->assertSame(1, DB::table(self::TABLE)->count());
            $this->assertSame($before, $this->protectedRows());
        } finally {
            while (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach (array_diff($children, $waited) as $pid) {
                posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $status);
            }
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}

<?php

namespace Tests\Feature;

use App\Data\Suppliers\Imports\CanonicalSupplierImportSourceExecution;
use App\Data\Suppliers\Imports\CanonicalSupplierImportSourcePayloadReceipt;
use App\Data\Suppliers\Imports\ImportJobIdentity;
use App\Data\Suppliers\Imports\ResolvedSupplierImportSourceContext;
use App\Data\Suppliers\Imports\SupplierProductLogicalKey;
use App\Data\Suppliers\SourceProfiles\CanonicalSupplierImportMapping;
use App\Data\Suppliers\SourceProfiles\CanonicalSupplierSourceLocator;
use App\Data\Suppliers\SourceProfiles\CanonicalSupplierSourceProfileDescriptor;
use App\Models\ImportHistory;
use App\Models\ImportJob;
use App\Models\Product;
use App\Models\SupplierFeed;
use App\Models\SupplierImportSourceProfile;
use App\Models\SupplierProduct;
use App\Models\SupplierProductSourceRevision as Revision;
use App\Models\User;
use App\Repositories\Suppliers\SupplierImportSourceExecutionRepository;
use App\Repositories\Suppliers\SupplierImportSourcePayloadReceiptRepository;
use Carbon\CarbonImmutable;
use Database\Migrations\Support\CanonicalSupplierPhaseThreeP0Oracle as Oracle;
use Database\Migrations\Support\CanonicalSupplierPhaseThreeP0Schema;
use Database\Migrations\Support\CanonicalSupplierPhaseThreeP0Schema as P0;
use Database\Migrations\Support\CanonicalSupplierPhaseThreeP0SchemaException;
use Database\Migrations\Support\CanonicalSupplierPhaseThreeP0SchemaInspector as Inspector;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use PDO;
use PDOStatement;
use ReflectionClass;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

require_once __DIR__.'/../../database/migrations/support/CanonicalSupplierPhaseThreeP0Schema.php';

final class PhaseThreeP0SourceRevisionFoundationMysqlTest extends TestCase
{
    private const TABLE = 'supplier_product_source_revisions';

    private const GRANT = 'SUPPLIER_PHASE_THREE_P0_EMPTY_SCHEMA_DOWN_CONFIRMED';

    private int $fixtureSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('P0-06 acceptance requires disposable MySQL 8.4.');
        }
        $this->assertStringStartsWith('8.4.', DB::scalar('SELECT VERSION()'));
        $databaseCollation = $this->schemaDefault();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        // Normal discovery must first prove the complete new P7 endpoint.
        $p7 = CanonicalSupplierPhaseThreeP0Schema::classify(DB::connection()->getPdo());
        $this->assertSame(['P7', 'NORMAL_PREFIX', 350, '765692fc06ba30ee87f27a7357edcb793927a52aef2375846a79257e5e8fcc83'],
            [$p7['state'], $p7['classification'], $p7['object_count'], $p7['sha256']]);
        $p07Grant = 'SUPPLIER_PHASE_THREE_P0_EMPTY_SCHEMA_DOWN_CONFIRMED';
        putenv($p07Grant.'=true');
        $_ENV[$p07Grant] = $_SERVER[$p07Grant] = 'true';
        try {
            $p07 = require database_path('migrations/2026_08_28_090006_add_source_revision_pointers_to_supplier_products_table.php');
            $p07->down();
        } finally {
            $this->assertFalse(getenv($p07Grant));
            $this->assertArrayNotHasKey($p07Grant, $_ENV);
            $this->assertArrayNotHasKey($p07Grant, $_SERVER);
        }

        $this->assertSame($databaseCollation, $this->schemaDefault());
        $this->assertPrefix('P6');
    }

    protected function tearDown(): void
    {
        putenv(self::GRANT);
        unset($_ENV[self::GRANT], $_SERVER[self::GRANT]);
        if (DB::getDriverName() === 'mysql') {
            DB::forgetExtension('phase_three_p0_schema_ddl');
            foreach (array_keys(DB::getConnections()) as $name) {
                DB::purge($name);
            }
            $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        }
        parent::tearDown();
    }

    public function test_fresh_and_populated_p5_transitions_preserve_exact_authority_and_protected_data(): void
    {
        $this->assertRawCollation();
        $p6 = (new Inspector(DB::connection()->getPdo()))->enumerate();
        $this->down();
        $this->assertPrefix('P5');
        $p5 = (new Inspector(DB::connection()->getPdo()))->enumerate();
        foreach ($p5 as $signature) {
            $this->assertContains($signature, $p6);
        }
        $row = $this->revisionFixture(" e\u{0301}-SKU\u{00E9} ");
        $user = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN, 'is_active' => true, 'deleted_at' => null]);
        $user->assignRole(Role::findOrCreate(User::ROLE_SUPER_ADMIN, 'web'));
        $this->assertAdmin($user->id);
        $before = $this->protectedRows();
        $schemaDefault = $this->schemaDefault();
        $trace = $this->observe(fn () => $this->migration()->up());
        $this->assertCount(3, $trace->completed);
        $this->assertCount(2, $trace->triggerSessions);
        foreach ($trace->triggerSessions as $session) {
            $this->assertSame('cp866', $session['character_set_client']);
            $this->assertSame('cp866_general_ci', $session['collation_connection']);
        }
        $this->assertSame($trace->sessionBefore, $trace->sessionAfter);
        $this->assertPrefix('P6');
        $this->assertSame($schemaDefault, $this->schemaDefault());
        $this->assertRawCollation();
        $this->assertSame($before, $this->protectedRows());
        $this->assertAdmin($user->id);
        $this->assertSame(0, DB::table(self::TABLE)->count());
        Log::spy();
        $trace = $this->observe(fn () => $this->down());
        $this->assertSame(['DROP TABLE `supplier_product_source_revisions`'], $trace->completed);
        $this->assertPrefix('P5');
        $this->assertSame($before, $this->protectedRows());
        $this->assertAdmin($user->id);
        Log::shouldHaveReceived('info')->withArgs(fn ($event, $evidence) => $event === 'phase_three_p0_downgrade_evidence_v1'
            && $evidence['completed_ddl_ids'] === ['P0-06-DOWN-01'] && $evidence['last_verified_state'] === 'P5'
            && $evidence['primary_outcome'] === 'phase_three_p0_downgrade_succeeded' && $evidence['mutex_release'] === 'RELEASED');
        $this->record('preservation', ['schemaDefault' => $schemaDefault, 'protectedRows' => $before, 'superAdminId' => $user->id, 'headId' => $row['supplier_product_identity_head_id'], 'ddl' => $trace->completed]);
    }

    public function test_read_hydration_preserves_bytes_and_every_model_and_sql_mutation_is_rejected(): void
    {
        $row = $this->revisionFixture("SKU-\u{00E9}-e\u{0301}");
        $id = DB::table(self::TABLE)->insertGetId($row);
        $before = (array) DB::table(self::TABLE)->find($id);
        $model = Revision::findOrFail($id);
        $this->assertSame($before, $model->getRawOriginal());
        $this->assertSame($row['supplier_sku_bytes'], $model->getRawOriginal('supplier_sku_bytes'));
        $this->assertInstanceOf(CarbonImmutable::class, $model->created_at);
        $this->assertInstanceOf(CarbonImmutable::class, $model->observed_at);
        $this->assertSame('123456', $model->observed_at->format('u'));
        $this->assertNull(Revision::UPDATED_AT);
        $this->assertTrue((new ReflectionClass(Revision::class))->isFinal());
        foreach ([
            fn () => (new Revision)->save(), fn () => Revision::create([]),
            fn () => Revision::create($row), fn () => $model->fill($row), fn () => $model->forceFill($row),
            fn () => $model->save(), fn () => $model->saveQuietly(), fn () => $model->saveOrFail(),
            fn () => $model->update([]), fn () => $model->updateQuietly([]), fn () => $model->updateOrFail([]),
            fn () => $model->touch(), fn () => $model->increment('supplier_id'), fn () => $model->decrement('supplier_id'),
            fn () => $model->delete(), fn () => $model->deleteQuietly(), fn () => $model->forceDelete(),
            fn () => Revision::destroy($id),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Immutable model mutation was accepted.');
            } catch (LogicException|MassAssignmentException) {
                $this->assertSame($before, (array) DB::table(self::TABLE)->find($id));
                $this->assertSame(0, DB::connection()->transactionLevel());
            }
        }
        foreach ([
            'UPDATE `'.self::TABLE.'` SET revision_fingerprint = ? WHERE id = ?',
            'UPDATE `'.self::TABLE.'` SET revision_fingerprint = revision_fingerprint WHERE id = ?',
            'DELETE FROM `'.self::TABLE.'` WHERE id = ?',
        ] as $i => $sql) {
            $this->assertSqlFailure(fn () => DB::statement($sql, $i === 0 ? [str_repeat('f', 64), $id] : [$id]), 1644, '45000');
            $this->assertSame($before, (array) DB::table(self::TABLE)->find($id));
        }
    }

    public function test_execution_head_ownership_and_staging_existence_do_not_claim_p7_composite_ownership(): void
    {
        $first = $this->revisionFixture('first');
        $other = $this->revisionFixture('other');
        $protected = $this->protectedRows();
        foreach (['supplier_import_source_execution_id', 'supplier_product_identity_head_id', 'supplier_id', 'supplier_feed_id', 'supplier_sku_bytes', 'source_identity', 'source_descriptor_fingerprint', 'source_execution_fingerprint'] as $field) {
            $this->assertSqlFailure(fn () => DB::table(self::TABLE)->insert([...$first, $field => $other[$field]]), 1452, '23000');
            $this->assertSame(0, DB::table(self::TABLE)->count());
        }
        $this->assertSqlFailure(fn () => DB::table(self::TABLE)->insert([...$first, 'supplier_product_id' => 999999999]), 1452, '23000');
        // P6 intentionally has only an ID FK to staging; this is not a P0-07 ownership proof.
        $crossScope = [...$first, 'supplier_product_id' => $other['supplier_product_id']];
        $id = DB::table(self::TABLE)->insertGetId($crossScope);
        $this->assertSame($other['supplier_product_id'], DB::table(self::TABLE)->where('id', $id)->value('supplier_product_id'));
        $this->assertNotSame($first['supplier_id'], DB::table('supplier_products')->where('id', $crossScope['supplier_product_id'])->value('supplier_id'));
        $this->assertSame($protected, $this->protectedRows());
        $this->record('p6-staging-gap', ['revisionId' => $id, 'revisionSupplierId' => $first['supplier_id'], 'stagingSupplierId' => $other['supplier_id'], 'p7OwnershipEnforced' => false]);
    }

    public function test_byte_boundaries_grammar_hash_formats_and_execution_head_uniqueness(): void
    {
        foreach (['A', 'a', "\u{00E9}", "e\u{0301}", str_repeat("\u{1F600}", 255)] as $sku) {
            $row = $this->revisionFixture($sku);
            $id = DB::table(self::TABLE)->insertGetId($row);
            $this->assertSame($sku, Revision::findOrFail($id)->getRawOriginal('supplier_sku_bytes'));
            $this->assertSame(strlen($sku), DB::scalar('SELECT OCTET_LENGTH(supplier_sku_bytes) FROM '.self::TABLE.' WHERE id=?', [$id]));
            $this->assertSqlFailure(fn () => DB::table(self::TABLE)->insert([...$row, 'revision_fingerprint' => str_repeat('f', 64)]), 1062, '23000');
        }
        $row = $this->revisionFixture('format-control');
        $before = DB::table(self::TABLE)->count();
        foreach (['source_descriptor_fingerprint', 'source_execution_fingerprint', 'source_member_identity_hash', 'source_row_fingerprint', 'staging_projection_fingerprint', 'revision_fingerprint'] as $field) {
            foreach (['', str_repeat('a', 63), str_repeat('A', 64), str_repeat('g', 64)] as $value) {
                $this->assertSqlFailure(fn () => DB::table(self::TABLE)->insert([...$row, $field => $value]), 3819, 'HY000');
            }
        }
        foreach (['', 'Snapshot-source-v1:invalid', 'snapshot-source-v1:invalid space', 'snapshot-source-v1:bad--part'] as $source) {
            $this->assertSqlFailure(fn () => DB::table(self::TABLE)->insert([...$row, 'source_identity' => $source]), 3819, 'HY000');
        }
        $this->assertSqlFailure(fn () => DB::table(self::TABLE)->insert([...$row, 'supplier_sku_bytes' => '']), 3819, 'HY000');
        foreach (['supplier_sku_bytes' => str_repeat('x', 1021), 'source_identity' => str_repeat('a', 129), 'revision_fingerprint' => str_repeat('a', 65)] as $field => $value) {
            $this->assertSqlFailure(fn () => DB::table(self::TABLE)->insert([...$row, $field => $value]), 1406, '22001');
        }
        $this->assertSame($before, DB::table(self::TABLE)->count());
        $this->record('format-only', ['semanticFingerprintImplementation' => 'DEFERRED', 'validSkuLengths' => [1, 1, 2, 3, 1020], 'failedInsertsPersisted' => 0]);
    }

    public function test_synthetic_insert_obeys_the_caller_transaction_and_constraint_failure_is_not_success(): void
    {
        $row = $this->revisionFixture('rollback');
        $protected = $this->protectedRows();
        DB::beginTransaction();
        try {
            $id = DB::table(self::TABLE)->insertGetId($row);
            $this->assertSame(1, DB::connection()->transactionLevel());
            $this->assertSame(1, DB::table(self::TABLE)->where('id', $id)->count());
            $this->assertSqlFailure(fn () => DB::table(self::TABLE)->insert($row), 1062, '23000');
            $this->assertSame(1, DB::connection()->transactionLevel());
        } finally {
            DB::rollBack();
        }
        $this->assertSame(0, DB::connection()->transactionLevel());
        $this->assertSame(0, DB::table(self::TABLE)->count());
        $this->assertSame($protected, $this->protectedRows());
    }

    public function test_forward_rejects_wrong_prefix_malformed_missing_extra_gates_and_guard_session_failures_before_ddl(): void
    {
        $this->assertZeroDdl('phase_three_p0_forward_precondition_failed', fn () => $this->migration()->up());
        $this->down();
        foreach ([
            ["ALTER TABLE supplier_product_identity_heads COMMENT='synthetic malformed'", "ALTER TABLE supplier_product_identity_heads COMMENT='mycomputer:phase-iii-p0:v1:owner=P0-05'"],
            ['ALTER TABLE supplier_product_identity_heads DROP CHECK chk_supplier_product_identity_head_sku_bytes', 'ALTER TABLE supplier_product_identity_heads ADD CONSTRAINT chk_supplier_product_identity_head_sku_bytes CHECK ((length(supplier_sku_bytes) between 1 and 1020))'],
            ['ALTER TABLE supplier_product_identity_heads ADD COLUMN synthetic_extra int NULL', 'ALTER TABLE supplier_product_identity_heads DROP COLUMN synthetic_extra'],
        ] as [$corrupt, $restore]) {
            DB::statement($corrupt);
            try {
                $this->assertZeroDdl('phase_three_p0_forward_precondition_failed', fn () => $this->migration()->up());
            } finally {
                DB::statement($restore);
            }
            $this->assertPrefix('P5');
        }
        $originalDefault = $this->schemaDefault();
        $database = DB::getDatabaseName();
        $driftedDefault = $originalDefault === 'utf8mb4_unicode_ci' ? 'utf8mb4_0900_ai_ci' : 'utf8mb4_unicode_ci';
        DB::statement('ALTER DATABASE `'.str_replace('`', '``', $database).'` COLLATE '.$driftedDefault);
        try {
            $this->assertZeroDdl('phase_three_p0_forward_precondition_failed', fn () => $this->migration()->up());
        } finally {
            DB::statement('ALTER DATABASE `'.str_replace('`', '``', $database).'` COLLATE '.$originalDefault);
        }
        $this->assertSame($originalDefault, $this->schemaDefault());
        $this->assertPrefix('P5');
        foreach (['capture_enabled', 'protected_generation_admission_enabled', 'recovery_issuance_enabled', 'recovery_execution_enabled', 'monitor_schedule_enabled', 'observer_schedule_enabled', 'alert_delivery_enabled'] as $gate) {
            $key = 'supplier_snapshot_capture.'.$gate;
            $before = config($key);
            config()->set($key, true);
            try {
                $this->assertZeroDdl('phase_three_p0_protected_gate_enabled', fn () => $this->migration()->up());
            } finally {
                config()->set($key, $before);
            }
        }
        $this->assertZeroDdl('phase_three_p0_schema_guard_unavailable', fn () => $this->migration()->up(), 'guard');
        $this->assertZeroDdl('synthetic_session_unavailable', fn () => $this->migration()->up(), 'session');
        $mutex = 'mycomputer:phase-iii-p0-schema-ddl-v1';
        $this->assertSame(1, DB::scalar('SELECT GET_LOCK(?, 0)', [$mutex]));
        try {
            $this->assertZeroDdl('phase_three_p0_schema_guard_unavailable', fn () => $this->migration()->up());
        } finally {
            $this->assertSame(1, DB::scalar('SELECT RELEASE_LOCK(?)', [$mutex]));
        }
        $this->assertPrefix('P5');
        $this->migration()->up();
        $this->assertPrefix('P6');
    }

    public function test_down_rejects_missing_authority_wrong_environment_nonterminal_and_inbound_dependencies_without_ddl(): void
    {
        $protected = $this->protectedRows();
        foreach ([null, 'false', '1'] as $grant) {
            putenv($grant === null ? self::GRANT : self::GRANT.'='.$grant);
            $_ENV[self::GRANT] = $_SERVER[self::GRANT] = 'true';
            $this->assertZeroDdl('phase_three_p0_downgrade_not_authorized', fn () => $this->migration()->down());
            $this->assertGrantConsumed();
        }
        $environment = app('env');
        app()->instance('env', 'production');
        try {
            $this->assertZeroDdl('phase_three_p0_downgrade_not_authorized', fn () => $this->down());
        } finally {
            app()->instance('env', $environment);
        }
        DB::statement('CREATE TABLE synthetic_revision_child (id bigint unsigned PRIMARY KEY, revision_id bigint unsigned NULL, CONSTRAINT synthetic_revision_child_fk FOREIGN KEY (revision_id) REFERENCES supplier_product_source_revisions(id)) ENGINE=InnoDB');
        try {
            $withDependency = $this->protectedRows();
            $this->assertZeroDdl('phase_three_p0_source_revision_has_inbound_dependencies', fn () => $this->down());
            $this->assertSame($withDependency, $this->protectedRows());
        } finally {
            DB::statement('DROP TABLE synthetic_revision_child');
        }
        foreach (['dependency', 'dependency-invalid'] as $fault) {
            $this->assertZeroDdl('phase_three_p0_schema_inspection_unavailable', fn () => $this->down(), $fault);
        }
        foreach ([
            ["ALTER TABLE supplier_product_source_revisions COMMENT='synthetic malformed'", "ALTER TABLE supplier_product_source_revisions COMMENT='mycomputer:phase-iii-p0:v1:owner=P0-06'", 'P5', 'phase_three_p0_step_not_terminal'],
            ["ALTER TABLE supplier_product_source_revisions COMMENT='mycomputer:phase-iii-p0:v1:owner=synthetic'", "ALTER TABLE supplier_product_source_revisions COMMENT='mycomputer:phase-iii-p0:v1:owner=P0-06'", 'UNCLASSIFIED_P0_SCHEMA_STATE', 'UNCLASSIFIED_P0_SCHEMA_STATE'],
            ['ALTER TABLE supplier_product_source_revisions ADD COLUMN synthetic_extra int NULL', 'ALTER TABLE supplier_product_source_revisions DROP COLUMN synthetic_extra', 'UNCLASSIFIED_P0_SCHEMA_STATE', 'UNCLASSIFIED_P0_SCHEMA_STATE'],
            ['ALTER TABLE supplier_products ADD COLUMN current_source_revision_id bigint unsigned NULL', 'ALTER TABLE supplier_products DROP COLUMN current_source_revision_id', 'UNCLASSIFIED_P0_SCHEMA_STATE', 'UNCLASSIFIED_P0_SCHEMA_STATE'],
        ] as [$corrupt, $restore, $state, $error]) {
            DB::statement($corrupt);
            try {
                $classification = P0::classify(DB::connection()->getPdo());
                $this->assertSame($state, $classification['state']);
                $this->record('negative-down-state', ['mutation' => $corrupt, 'classification' => $classification, 'expectedError' => $error]);
                $this->assertZeroDdl($error, fn () => $this->down());
            } finally {
                DB::statement($restore);
            }
            $this->assertPrefix('P6');
        }
        $this->assertSame($protected, $this->protectedRows());
        $this->down();
        $this->assertPrefix('P5');
        $this->assertZeroDdl('phase_three_p0_step_not_terminal', fn () => $this->down());
        $this->assertPrefix('P5');
    }

    public function test_populated_revision_down_is_rejected_without_ddl_or_data_changes(): void
    {
        $row = $this->revisionFixture('not-pristine');
        DB::table(self::TABLE)->insert($row);
        $before = DB::table(self::TABLE)->get()->all();
        $protected = $this->protectedRows();
        $this->assertZeroDdl('phase_three_p0_append_only_table_not_pristine', fn () => $this->down());
        $this->assertEquals($before, DB::table(self::TABLE)->get()->all());
        $this->assertSame($protected, $this->protectedRows());
        $this->assertPrefix('P6');
    }

    private function assertPrefix(string $expected): void
    {
        Oracle::assertIntegrity();
        $state = P0::classify(DB::connection()->getPdo());
        $this->assertSame($expected, $state['state']);
        $this->assertSame('NORMAL_PREFIX', $state['classification']);
        $this->assertSame($expected === 'P6' ? 339 : 309, $state['object_count']);
        $this->assertSame($expected === 'P6' ? 'ea265e4717c2f61abba6731f08be576ff6fcd01fe6b673d61d41011a888056aa' : '8cc5afed3a68c4152bf1456a10741c5e23bd077bf4dae39cae639208872ca56f', $state['sha256']);
    }

    private function schemaDefault(): string
    {
        return DB::scalar('SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=DATABASE()');
    }

    private function assertRawCollation(): void
    {
        $default = $this->schemaDefault();
        $inspection = (new Inspector(DB::connection()->getPdo()))->inspect();
        $this->assertSame($default, $inspection['schema_default_collation']);
        $signatures = array_values(array_filter($inspection['raw_signatures'], fn ($s) => ($s['table'] ?? $s['child_table'] ?? null) === self::TABLE));
        $this->assertCount(30, $signatures);
        $types = array_count_values(array_column($signatures, 'type'));
        ksort($types);
        $this->assertSame(['check' => 2, 'column' => 16, 'foreign_key' => 3, 'index' => 6, 'table' => 1, 'trigger' => 2], $types);
        foreach ($signatures as $signature) {
            if ($signature['type'] === 'table') {
                $this->assertSame('utf8mb4_unicode_ci', $signature['table_collation']);
            }
            if ($signature['type'] === 'trigger') {
                $this->assertSame($default, $signature['database_collation']);
                $this->assertSame('cp866', $signature['character_set_client']);
                $this->assertSame('cp866_general_ci', $signature['collation_connection']);
            }
        }
        $this->record('raw-metadata', ['schemaDefault' => $default, 'signatures' => $signatures]);
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_08_28_090005_create_supplier_product_source_revisions_table.php');
    }

    private function down(): void
    {
        putenv(self::GRANT.'=true');
        $_ENV[self::GRANT] = $_SERVER[self::GRANT] = 'true';
        try {
            $this->migration()->down();
        } finally {
            $this->assertGrantConsumed();
        }
    }

    private function assertGrantConsumed(): void
    {
        $this->assertFalse(getenv(self::GRANT));
        $this->assertArrayNotHasKey(self::GRANT, $_ENV);
        $this->assertArrayNotHasKey(self::GRANT, $_SERVER);
    }

    private function assertSqlFailure(callable $operation, int $errno, string $sqlstate): void
    {
        try {
            $operation();
            $this->fail('Expected SQL constraint rejection.');
        } catch (QueryException $error) {
            $this->assertSame($sqlstate, $error->errorInfo[0]);
            $this->assertSame($errno, $error->errorInfo[1]);
        }
    }

    private function assertZeroDdl(string $expected, callable $operation, ?string $fault = null): void
    {
        $trace = $this->observe(function () use ($expected, $operation): void {
            try {
                $operation();
                $this->fail('Expected rejection: '.$expected);
            } catch (RuntimeException $error) {
                $this->assertSame($expected, $error instanceof CanonicalSupplierPhaseThreeP0SchemaException ? $error->primaryCode : $error->getMessage());
            }
        }, $fault);
        $this->assertSame([], $trace->attempted);
        $this->assertSame([], $trace->completed);
        $this->record('zero-ddl', ['expected' => $expected, 'fault' => $fault, 'ddl' => $trace->attempted]);
    }

    private function observe(callable $operation, ?string $fault = null): object
    {
        $trace = (object) ['attempted' => [], 'completed' => [], 'triggerSessions' => [], 'fault' => $fault];
        DB::extend('phase_three_p0_schema_ddl', static function (array $config, string $name) use ($trace): MySqlConnection {
            $pdo = new class($config, $trace) extends PDO
            {
                public function __construct(array $config, private object $trace)
                {
                    parent::__construct('mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'].';charset=utf8mb4', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => false]);
                    $this->trace->sessionBefore = $this->session();
                }

                public function exec(string $statement): int|false
                {
                    $ddl = preg_match('/\A(?:CREATE|ALTER|DROP) /i', ltrim($statement)) === 1;
                    if ($ddl) {
                        $this->trace->attempted[] = $statement;
                    }
                    if (str_starts_with(ltrim($statement), 'CREATE TRIGGER')) {
                        $this->trace->triggerSessions[] = $this->session();
                    }
                    $result = parent::exec($statement);
                    if ($ddl) {
                        $this->trace->completed[] = $statement;
                    }

                    return $result;
                }

                public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
                {
                    if ($this->trace->fault === 'guard' && str_starts_with($query, 'SELECT GET_LOCK(')) {
                        return parent::query('SELECT NULL');
                    }
                    if ($this->trace->fault === 'session' && str_contains($query, '@@SESSION.sql_mode')) {
                        throw new RuntimeException('synthetic_session_unavailable');
                    }
                    if (str_starts_with(ltrim($query), 'SELECT EXISTS(') && str_contains($query, 'information_schema.KEY_COLUMN_USAGE')) {
                        if ($this->trace->fault === 'dependency') {
                            throw new RuntimeException('synthetic_dependency_inspection_unavailable');
                        }
                        if ($this->trace->fault === 'dependency-invalid') {
                            return parent::query("SELECT '0'");
                        }
                    }
                    if (str_starts_with($query, 'SELECT RELEASE_LOCK(')) {
                        $this->trace->sessionAfter = $this->session();
                    }

                    return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
                }

                private function session(): array
                {
                    return parent::query('SELECT @@session.sql_mode AS sql_mode, @@session.character_set_client AS character_set_client, @@session.character_set_results AS character_set_results, @@session.collation_connection AS collation_connection')->fetch(PDO::FETCH_ASSOC);
                }
            };

            return new MySqlConnection($pdo, $config['database'], '', [...$config, 'name' => $name]);
        });
        try {
            $operation();
        } finally {
            DB::forgetExtension('phase_three_p0_schema_ddl');
            DB::purge('phase_three_p0_schema_ddl');
        }

        return $trace;
    }

    private function protectedRows(): array
    {
        $result = [];
        foreach (DB::select("SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME") as $table) {
            if ($table->name === self::TABLE) {
                continue;
            }
            $rows = DB::table($table->name)->get()->map(function ($row): string {
                $values = (array) $row;
                ksort($values);

                return hash('sha256', serialize($values));
            })->all();
            sort($rows, SORT_STRING);
            $result[$table->name] = $rows;
        }

        return $result;
    }

    private function assertAdmin(int $id): void
    {
        $user = User::withTrashed()->with(['roles.permissions', 'permissions'])->findOrFail($id);
        $this->assertSame($id, $user->id);
        $this->assertTrue($user->is_active);
        $this->assertNull($user->deleted_at);
        $this->assertSame(User::ROLE_SUPER_ADMIN, $user->role);
        $this->assertSame([User::ROLE_SUPER_ADMIN], $user->getRoleNames()->all());
        $this->assertTrue($user->canAccessPanel(filament()->getPanel('admin')));
        $this->assertTrue($user->canManageUsers());
    }

    private function revisionFixture(string $sku): array
    {
        $source = 'revision-'.++$this->fixtureSequence;
        $feed = SupplierFeed::factory()->create(['feed_type' => 'csv', 'mapping' => ['sku' => 'code']]);
        $job = ImportJob::query()->create(['supplier_id' => $feed->supplier_id, 'supplier_feed_id' => $feed->id, 'type' => 'csv', 'mode' => 'queued', 'status' => 'pending']);
        $history = ImportHistory::startForImport($job, 'Synthetic P0-06 fixture; no import.');
        $locator = CanonicalSupplierSourceLocator::fromArray(['schema' => CanonicalSupplierSourceLocator::CONTRACT,
            'source_locator_contract_key' => 'test-source-locator', 'source_locator_contract_version' => '1',
            'scheme' => 'https', 'ascii_host' => 'feed.example.test', 'port' => null,
            'path_components' => [['position' => 0, 'classification' => 'source', 'value' => $source]], 'query_components' => []]);
        $descriptor = CanonicalSupplierSourceProfileDescriptor::fromContracts(
            supplierId: $feed->supplier_id, supplierFeedId: $feed->id, locator: $locator,
            sourceAccessScopeKey: 'source-access-v1:test.feed.'.$feed->id, feedType: 'csv', importerKey: 'csv-importer', importerVersion: '1',
            mapping: CanonicalSupplierImportMapping::fromArray(['schema' => CanonicalSupplierImportMapping::VERSION, 'feed_type' => 'csv', 'effective_mapping' => $feed->mapping]),
        );
        $id = DB::table('supplier_import_source_profiles')->insertGetId([...$descriptor->persistenceAttributes(), 'source_identity' => 'snapshot-source-v1:profile:'.md5($feed->id.':'.$source)]);
        $profile = (new SupplierImportSourceProfile)->setConnection(DB::getDefaultConnection())->newFromBuilder((array) DB::table('supplier_import_source_profiles')->find($id));
        $executionValue = CanonicalSupplierImportSourceExecution::fromContracts(
            ImportJobIdentity::fromArray(['schema' => ImportJobIdentity::VERSION, 'import_job_id' => $job->id, 'supplier_id' => $feed->supplier_id, 'supplier_feed_id' => $feed->id, 'xml_mapping_template_id' => null, 'import_type' => 'csv']),
            ResolvedSupplierImportSourceContext::fromProfile($profile), $history->id, '2026-10-18T00:00:00.123456Z',
        );
        $execution = DB::transaction(function () use ($executionValue) {
            $execution = (new SupplierImportSourceExecutionRepository)->resolveOrInsertWithinTransaction(DB::connection(), $executionValue);
            $receipt = CanonicalSupplierImportSourcePayloadReceipt::fromArray(['schema' => CanonicalSupplierImportSourcePayloadReceipt::VERSION, 'supplier_import_source_execution_id' => $execution->id,
                'source_execution_fingerprint' => $execution->getRawOriginal('source_execution_fingerprint'), 'accepted_payload_bytes' => 3, 'accepted_payload_sha256' => hash('sha256', 'abc')]);
            (new SupplierImportSourcePayloadReceiptRepository)->resolveOrInsertWithinTransaction(DB::connection(), $execution, $receipt);

            return $execution;
        });
        $key = SupplierProductLogicalKey::fromArray(['supplier_id' => $feed->supplier_id, 'supplier_feed_id' => $feed->id, 'supplier_sku_bytes' => $sku]);
        $headId = DB::table('supplier_product_identity_heads')->insertGetId($key->persistenceAttributes());
        $product = Product::factory()->create(['name' => 'Protected synthetic product '.$source, 'meta_title' => 'Manual SEO '.$source]);
        $staging = SupplierProduct::query()->create(['supplier_id' => $feed->supplier_id, 'supplier_feed_id' => $feed->id, 'product_id' => $product->id,
            'supplier_sku' => $source, 'raw_data' => ['synthetic' => true, 'manual' => 'preserve'], 'payload_hash' => hash('sha256', $source), 'received_at' => now('UTC')]);

        // The four synthetic hashes below prove SQL format only, not deferred fingerprint semantics.
        return ['supplier_product_identity_head_id' => $headId, 'supplier_product_id' => $staging->id,
            'supplier_import_source_execution_id' => $execution->id, 'supplier_id' => $feed->supplier_id, 'supplier_feed_id' => $feed->id,
            'supplier_sku_bytes' => $key->persistenceAttributes()['supplier_sku_bytes'], 'source_identity' => $execution->getRawOriginal('source_identity'),
            'source_descriptor_fingerprint' => $execution->getRawOriginal('source_descriptor_fingerprint'), 'source_execution_fingerprint' => $execution->getRawOriginal('source_execution_fingerprint'),
            'source_member_identity_hash' => hash('sha256', 'synthetic-member-'.$source), 'source_row_fingerprint' => hash('sha256', 'synthetic-row-'.$source),
            'staging_projection_fingerprint' => hash('sha256', 'synthetic-projection-'.$source), 'revision_fingerprint' => hash('sha256', 'synthetic-revision-'.$source),
            'observed_at' => '2026-10-18 00:00:00.123456'];
    }

    private function record(string $name, array $record): void
    {
        $directory = getenv('P006_EVIDENCE_DIR');
        if (! is_string($directory) || $directory === '') {
            return;
        }
        if (! is_dir($directory)) {
            $this->assertTrue(mkdir($directory, 0700, true));
        }
        $file = tempnam($directory, $name.'-');
        $this->assertNotFalse($file);
        $this->assertNotFalse(file_put_contents($file, json_encode(['utc' => gmdate('c'), ...$record], JSON_THROW_ON_ERROR)));
    }
}

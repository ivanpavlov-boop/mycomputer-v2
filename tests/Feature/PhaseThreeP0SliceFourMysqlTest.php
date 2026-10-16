<?php

namespace Tests\Feature;

use App\Data\Suppliers\Imports\CanonicalSupplierImportSourceExecution;
use App\Data\Suppliers\Imports\CanonicalSupplierImportSourcePayloadReceipt;
use App\Data\Suppliers\Imports\ImportJobIdentity;
use App\Data\Suppliers\Imports\ResolvedSupplierImportSourceContext;
use App\Data\Suppliers\Imports\SupplierProductLogicalKey as Key;
use App\Data\Suppliers\SourceProfiles\CanonicalSupplierImportMapping;
use App\Data\Suppliers\SourceProfiles\CanonicalSupplierSourceLocator;
use App\Data\Suppliers\SourceProfiles\CanonicalSupplierSourceProfileDescriptor;
use App\Models\Category;
use App\Models\ImportHistory;
use App\Models\ImportJob;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\SupplierFeed;
use App\Models\SupplierImportSourceProfile;
use App\Models\SupplierProduct;
use App\Models\SupplierProductIdentityHead as Head;
use App\Models\User;
use App\Repositories\Suppliers\SupplierImportSourceExecutionRepository;
use App\Repositories\Suppliers\SupplierImportSourcePayloadReceiptRepository;
use App\Repositories\Suppliers\SupplierProductIdentityHeadRepository as Repository;
use Database\Migrations\Support\CanonicalSupplierPhaseThreeP0Oracle as Oracle;
use Database\Migrations\Support\CanonicalSupplierPhaseThreeP0Schema as P0;
use Database\Migrations\Support\CanonicalSupplierPhaseThreeP0SchemaException;
use Database\Migrations\Support\CanonicalSupplierPhaseThreeP0SchemaInspector;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use PDO;
use PDOException;
use PDOStatement;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Throwable;

require_once __DIR__.'/../../database/migrations/support/CanonicalSupplierPhaseThreeP0Schema.php';

final class PhaseThreeP0SliceFourMysqlTest extends TestCase
{
    private const TABLE = 'supplier_product_identity_heads';

    private const GRANT = 'SUPPLIER_PHASE_THREE_P0_EMPTY_SCHEMA_DOWN_CONFIRMED';

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Phase III-P0 Slice 4 requires disposable MySQL 8.4.');
        }
        $this->assertStringStartsWith('8.4.', DB::scalar('SELECT VERSION()'));
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->assertPrefix('P5');
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

    public function test_fresh_p5_matches_frozen_oracle_and_creation_session(): void
    {
        $databaseCollation = $this->schemaDefaultCollation();
        $inspection = (new CanonicalSupplierPhaseThreeP0SchemaInspector(DB::connection()->getPdo()))->inspect();
        $this->assertSame($databaseCollation, $inspection['schema_default_collation']);
        $head = array_values(array_filter($inspection['raw_signatures'], fn ($s) => ($s['table'] ?? $s['child_table'] ?? null) === self::TABLE));
        $this->assertCount(15, $head);
        foreach ($head as $signature) {
            if ($signature['type'] === 'trigger') {
                $this->assertSame('cp866', $signature['character_set_client']);
                $this->assertSame('cp866_general_ci', $signature['collation_connection']);
                $this->assertSame($databaseCollation, $signature['database_collation']);
            }
        }
        $before = $this->creationSession();
        $this->authorizeDown();
        $this->migration()->down();
        $this->assertGrantConsumed();
        $p4 = (new CanonicalSupplierPhaseThreeP0SchemaInspector(DB::connection()->getPdo()))->enumerate();
        $this->assertCount(294, $p4);
        $trace = (object) ['ddl' => [], 'queries' => [], 'fault' => null, 'captureSession' => true];
        $this->observeDdl($trace);
        try {
            $this->migration()->up();
            $this->assertSame($trace->sessionBefore, $trace->sessionAfter);
            $this->assertNotSame(DB::scalar('SELECT CONNECTION_ID()'), $trace->sessionId);
            $this->assertCount(2, $trace->creationSessions);
            foreach ($trace->creationSessions as $session) {
                $this->assertSame('ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION', $session['sql_mode']);
                $this->assertSame('cp866', $session['character_set_client']);
                $this->assertSame('cp866', $session['character_set_results']);
                $this->assertSame('cp866_general_ci', $session['collation_connection']);
                $this->assertNotSame($trace->sessionBefore, $session);
            }
            $this->recordCase('ddl-session-restoration', ['connectionId' => $trace->sessionId, 'before' => $trace->sessionBefore, 'creation' => $trace->creationSessions, 'after' => $trace->sessionAfter]);
        } finally {
            $this->stopObservingDdl();
        }
        $this->assertSame($before, $this->creationSession());
        $p5 = (new CanonicalSupplierPhaseThreeP0SchemaInspector(DB::connection()->getPdo()))->enumerate();
        $this->assertCount(309, $p5);
        foreach ($p4 as $signature) {
            $this->assertContains($signature, $p5);
        }
        $this->assertPrefix('P5');
        $this->assertSame(0, DB::table(self::TABLE)->count());
    }

    public function test_populated_p4_to_p5_preserves_parent_and_admin_evidence(): void
    {
        $this->authorizeDown();
        $this->migration()->down();
        $this->assertGrantConsumed();
        $feed = SupplierFeed::factory()->create(['feed_type' => 'csv', 'mapping' => ['sku' => 'code']]);
        $this->sourceEvidence($feed, 'a');
        $product = Product::factory()->create();
        SupplierProduct::query()->create(['supplier_id' => $feed->supplier_id, 'supplier_feed_id' => $feed->id,
            'product_id' => $product->id, 'supplier_sku' => 'synthetic-head-preservation',
            'raw_data' => ['synthetic' => true], 'payload_hash' => hash('sha256', 'synthetic'), 'received_at' => now('UTC')]);
        $user = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN, 'is_active' => true, 'deleted_at' => null]);
        $user->assignRole(Role::findOrCreate(User::ROLE_SUPER_ADMIN, 'web'));
        $this->assertAdmin($user->id);
        $before = $this->protectedRows();
        $this->migration()->up();
        $this->assertPrefix('P5');
        $this->assertSame($before, $this->protectedRows());
        $this->assertAdmin($user->id);
        $this->authorizeDown();
        $this->migration()->down();
        $this->assertGrantConsumed();
        $this->assertPrefix('P4');
        $this->assertSame($before, $this->protectedRows());
        $this->assertAdmin($user->id);
        $this->migration()->up();
        $this->assertPrefix('P5');
        $this->assertSame($before, $this->protectedRows());
        $this->assertAdmin($user->id);
    }

    public function test_p5_exact_columns_indexes_checks_and_feed_ownership_constraints(): void
    {
        $feed = SupplierFeed::factory()->create();
        $other = SupplierFeed::factory()->create();
        $attributes = $this->key($feed)->persistenceAttributes();
        $before = $this->protectedRows();
        $columns = DB::select('SELECT COLUMN_NAME AS name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? ORDER BY ORDINAL_POSITION', [self::TABLE]);
        $this->assertSame(['id', 'supplier_id', 'supplier_feed_id', 'supplier_sku_bytes', 'created_at'], array_column($columns, 'name'));
        $invalid = [
            [array_replace($attributes, ['supplier_id' => null]), 1048],
            [array_replace($attributes, ['supplier_feed_id' => null]), 1048],
            [array_replace($attributes, ['supplier_sku_bytes' => null]), 1048],
            [array_replace($attributes, ['created_at' => null]), 1048],
            [array_replace($attributes, ['supplier_sku_bytes' => '']), 3819],
            [array_replace($attributes, ['supplier_sku_bytes' => str_repeat('A', 1021)]), 1406],
            [array_replace($attributes, ['supplier_id' => $other->supplier_id]), 1452],
            [array_replace($attributes, ['supplier_feed_id' => 9999999]), 1452],
            [array_replace($attributes, ['supplier_id' => 0]), 1452],
        ];
        foreach ($invalid as [$row, $errno]) {
            try {
                DB::table(self::TABLE)->insert($row);
                $this->fail('Invalid head SQL accepted.');
            } catch (QueryException $error) {
                $this->assertSame($errno, $error->errorInfo[1]);
                if ($errno === 1452) {
                    $this->assertStringContainsString('fk_supplier_product_identity_head_feed_owner', $error->errorInfo[2]);
                } elseif ($errno === 3819) {
                    $this->assertStringContainsString('chk_supplier_product_identity_head_sku_bytes', $error->errorInfo[2]);
                }
                $this->assertSame(0, DB::table(self::TABLE)->count());
                $this->assertSame($before, $this->protectedRows());
            }
        }
        foreach (['A', str_repeat("\u{1F600}", 255)] as $sku) {
            DB::table(self::TABLE)->insert([...$attributes, 'supplier_sku_bytes' => $sku]);
            $this->assertSame($sku, DB::table(self::TABLE)->where('supplier_sku_bytes', $sku)->value('supplier_sku_bytes'));
        }
        $this->assertSame($before, $this->protectedRows());
        $this->assertPrefix('P5');
    }

    public function test_head_mutations_are_rejected_through_raw_and_model_paths(): void
    {
        $feed = SupplierFeed::factory()->create();
        $head = DB::transaction(fn () => $this->participate(DB::connection(), $this->key($feed)));
        $row = (array) DB::table(self::TABLE)->first();
        $protected = $this->protectedRows();
        foreach ([
            fn () => DB::table(self::TABLE)->where('id', $head->id)->update(['supplier_sku_bytes' => 'changed']),
            fn () => DB::table(self::TABLE)->where('id', $head->id)->update(['supplier_sku_bytes' => $row['supplier_sku_bytes']]),
            fn () => DB::table(self::TABLE)->where('id', $head->id)->delete(),
            fn () => DB::statement('REPLACE INTO '.self::TABLE.' (supplier_id,supplier_feed_id,supplier_sku_bytes) VALUES (?,?,?)', [$feed->supplier_id, $feed->id, 'SKU']),
            fn () => DB::table(self::TABLE)->upsert([$this->key($feed)->persistenceAttributes()], ['supplier_id', 'supplier_feed_id', 'supplier_sku_bytes'], ['supplier_sku_bytes']),
            fn () => Head::query()->whereKey($head->id)->update(['supplier_sku_bytes' => 'changed']),
            fn () => Head::query()->whereKey($head->id)->delete(),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Immutable SQL mutation accepted.');
            } catch (QueryException $error) {
                $this->assertSame('45000', $error->errorInfo[0]);
                $this->assertStringContainsString('Immutable supplier product identity head cannot be', $error->errorInfo[2]);
            }
            $this->assertSame($row, (array) DB::table(self::TABLE)->first());
            $this->assertSame(1, DB::table(self::TABLE)->count());
        }
        foreach ([fn () => $head->save(), fn () => $head->saveQuietly(), fn () => $head->saveOrFail(), fn () => $head->update([]),
            fn () => $head->updateQuietly([]), fn () => $head->updateOrFail([]), fn () => $head->touch(), fn () => $head->touchQuietly(),
            fn () => $head->increment('supplier_id'), fn () => $head->decrement('supplier_id'),
            fn () => $head->delete(), fn () => $head->deleteQuietly(), fn () => $head->deleteOrFail(), fn () => $head->forceDelete(),
            fn () => (new Head)->save(), fn () => (new Head)->saveQuietly(), fn () => (new Head)->saveOrFail(),
            fn () => Head::create($this->key($feed)->persistenceAttributes()),
            fn () => Head::query()->forceCreate($this->key($feed)->persistenceAttributes()),
            fn () => (new Head)->forceFill($this->key($feed)->persistenceAttributes()),
            fn () => Head::unguarded(fn () => Head::create($this->key($feed)->persistenceAttributes())),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Immutable model mutation accepted.');
            } catch (LogicException|MassAssignmentException) {
                $this->assertSame($row, (array) DB::table(self::TABLE)->first());
            }
        }
        // Raw INSERT privileges are deliberately not presented as a private API guarantee.
        DB::table(self::TABLE)->insert($this->key($feed, 'raw-new')->persistenceAttributes());
        $this->assertSame(2, DB::table(self::TABLE)->count());
        $this->assertSame($protected, $this->protectedRows());
    }

    public function test_private_participant_inserts_then_locks_or_reuses_exact_tuple(): void
    {
        $feed = SupplierFeed::factory()->create();
        $key = $this->key($feed);
        $connection = DB::connection();
        $queries = [];
        $connection->beforeExecuting(function ($sql) use (&$queries): void {
            $queries[] = $sql;
        });
        $connection->beginTransaction();
        $first = $this->participate($connection, $key);
        $this->assertSame(1, $connection->transactionLevel());
        $this->assertTrue($connection->getPdo()->inTransaction());
        $again = $this->participate($connection, $key);
        $this->assertSame($first->getRawOriginal(), $again->getRawOriginal());
        $this->assertSame($connection, $again->getConnection());
        $headQueries = array_values(array_filter($queries, fn ($sql) => str_contains($sql, self::TABLE)));
        $this->assertCount(4, $headQueries);
        $this->assertStringStartsWith('insert into', $headQueries[0]);
        $this->assertStringEndsWith('for update', $headQueries[1]);
        $this->assertStringStartsWith('insert into', $headQueries[2]);
        $this->assertStringEndsWith('for update', $headQueries[3]);
        $connection->commit();
        $this->assertSame(1, DB::table(self::TABLE)->count());
        $this->recordCase('insert-first', ['connectionId' => $connection->scalar('SELECT CONNECTION_ID()'), 'queries' => $headQueries, 'row' => $first->getRawOriginal(), 'callerCommit' => true]);
    }

    public function test_private_participant_rejects_byte_conflict_and_unrelated_sql_failures(): void
    {
        $key = Key::fromArray(['supplier_id' => 1, 'supplier_feed_id' => 2, 'supplier_sku_bytes' => 'SKU']);
        $pdo = new class extends PDO
        {
            public function __construct() {}

            public function inTransaction(): bool
            {
                return true;
            }
        };
        $connection = new class($pdo, '', '', ['driver' => 'mysql', 'name' => 'synthetic']) extends MySqlConnection
        {
            public ?QueryException $failure = null;

            public array $rows = [];

            public int $inserts = 0;

            public int $selects = 0;

            public function transactionLevel()
            {
                return 1;
            }

            public function insert($query, $bindings = [], $sequence = null)
            {
                $this->inserts++;
                if ($this->failure !== null) {
                    throw $this->failure;
                }

                return true;
            }

            public function select($query, $bindings = [], $useReadPdo = true)
            {
                $this->selects++;

                return $this->rows;
            }
        };
        $valid = (object) ['id' => 3, ...$key->persistenceAttributes(), 'created_at' => '2026-10-12 00:00:00.123456'];
        foreach ([[], [(object) [...(array) $valid, 'supplier_sku_bytes' => 'sku']], [(object) [...(array) $valid, 'supplier_id' => 9]], [(object) [...(array) $valid, 'supplier_feed_id' => 9]]] as $rows) {
            $connection->rows = $rows;
            $this->assertFailure('supplier_product_logical_identity_conflict', fn () => $this->participate($connection, $key));
        }
        $connection->rows = [$valid];
        foreach ([['23000', 1062, "Duplicate entry 'x' for key 'PRIMARY'"],
            ['23000', '1062', "Duplicate entry 'x' for key 'uq_supplier_product_identity_head'"],
            ['HY000', 1062, "Duplicate entry 'x' for key 'uq_supplier_product_identity_head'"],
            ['23000', 1062, "Duplicate entry 'x' for key 'another.uq_supplier_product_identity_head'"],
            ['23000', 1062, 'malformed'], ['23000', 1452, 'Foreign key violation'],
            ['HY000', 3819, 'Check violation'], ['40001', 1213, 'Deadlock'],
            ['HY000', 1205, 'Lock wait timeout'], ['HY000', 2006, 'Connection lost']] as $info) {
            $error = new PDOException($info[2]);
            $error->errorInfo = $info;
            $connection->failure = new QueryException('synthetic', 'insert synthetic', [], $error);
            $selects = $connection->selects;
            $inserts = $connection->inserts;
            try {
                $this->participate($connection, $key);
                $this->fail('Unrelated SQL failure swallowed.');
            } catch (QueryException $caught) {
                $this->assertSame($connection->failure, $caught);
                $this->assertSame($selects, $connection->selects);
                $this->assertSame($inserts + 1, $connection->inserts);
            }
        }
        foreach (['uq_supplier_product_identity_head', 'supplier_product_identity_heads.uq_supplier_product_identity_head'] as $name) {
            $error = new PDOException("Duplicate entry 'x' for key '{$name}'");
            $error->errorInfo = ['23000', 1062, $error->getMessage()];
            $connection->failure = new QueryException('synthetic', 'insert synthetic', [], $error);
            $connection->rows = [];
            $this->assertFailure('supplier_product_logical_identity_conflict', fn () => $this->participate($connection, $key));
        }
    }

    public function test_participant_rejects_outside_or_wrong_connection_transaction(): void
    {
        $feed = SupplierFeed::factory()->create();
        $key = $this->key($feed);
        $a = DB::connection();
        $b = $this->independent('slice_four_b');
        $this->assertNotSame($a->scalar('SELECT CONNECTION_ID()'), $b->scalar('SELECT CONNECTION_ID()'));
        $this->assertFailure('supplier_product_identity_head_mysql_transaction_required', fn () => $this->participate($a, $key));
        $a->beginTransaction();
        $this->assertFailure('supplier_product_identity_head_mysql_transaction_required', fn () => $this->participate($b, $key));
        $this->assertSame(1, $a->transactionLevel());
        $a->rollBack();
        $a->beginTransaction();
        $a->getPdo()->rollBack();
        $this->assertFailure('supplier_product_identity_head_mysql_transaction_required', fn () => $this->participate($a, $key));
        DB::purge();
        $this->assertSame(0, $b->table(self::TABLE)->count());
    }

    public function test_post_insert_exception_leaves_caller_rollback_authoritative(): void
    {
        $key = $this->key(SupplierFeed::factory()->create());
        $before = $this->protectedRows();
        $observer = $this->independent('slice_four_observer');
        try {
            DB::transaction(function () use ($key, $observer): void {
                $this->participate(DB::connection(), $key);
                $this->assertSame(0, $observer->table(self::TABLE)->count());
                $this->assertSame(1, DB::connection()->transactionLevel());
                throw new RuntimeException('synthetic_caller_failure');
            });
        } catch (RuntimeException $error) {
            $this->assertSame('synthetic_caller_failure', $error->getMessage());
        }
        $this->assertSame(0, $observer->table(self::TABLE)->count());
        $this->assertSame($before, $this->protectedRows());
    }

    public function test_a_to_b_and_changed_selectors_reuse_only_the_same_logical_head(): void
    {
        $feed = SupplierFeed::factory()->create(['feed_type' => 'csv', 'mapping' => ['sku' => 'code']]);
        $sourceA = $this->sourceEvidence($feed, 'a');
        $immutableA = [
            (array) DB::table('supplier_import_source_profiles')->find($sourceA['profileId']),
            (array) DB::table('supplier_import_source_executions')->find($sourceA['executionId']),
        ];
        $setupBefore = $this->protectedRows();
        $key = $this->key($feed);
        $first = DB::transaction(fn () => $this->participate(DB::connection(), $key));
        $job = ImportJob::query()->findOrFail($sourceA['jobId']);
        $this->assertSame('csv', $job->type);
        $job->update(['type' => 'xml', 'preview_limit' => 7]);
        $this->assertSame('xml', $job->fresh()->type);
        $feed->update(['mapping' => ['sku' => 'new_code']]);
        $this->assertSame(['sku' => 'new_code'], $feed->fresh()->mapping);
        $sourceB = $this->sourceEvidence($feed->fresh(), 'b');
        $this->assertNotSame($sourceA['profileId'], $sourceB['profileId']);
        $this->assertNotSame($sourceA['executionId'], $sourceB['executionId']);
        $this->assertSame($immutableA, [
            (array) DB::table('supplier_import_source_profiles')->find($sourceA['profileId']),
            (array) DB::table('supplier_import_source_executions')->find($sourceA['executionId']),
        ]);
        $before = $this->protectedRows();
        $this->assertNotSame($setupBefore, $before);
        $queries = [];
        DB::connection()->beforeExecuting(function ($sql) use (&$queries): void {
            $queries[] = $sql;
        });
        $again = DB::transaction(fn () => $this->participate(DB::connection(), $key));
        $operationQueries = $queries;
        $this->assertSame($first->getRawOriginal(), $again->getRawOriginal());
        $this->assertCount(2, $operationQueries);
        foreach ($operationQueries as $sql) {
            $this->assertStringContainsString(self::TABLE, $sql);
        }
        $this->assertSame($before, $this->protectedRows());
        $this->recordCase('source-selectors', ['setupBefore' => $setupBefore, 'setupAfter' => $before, 'immutableA' => hash('sha256', serialize($immutableA)), 'operationQueries' => $operationQueries]);
        foreach (['sku', "\u{00E9}", "e\u{0301}"] as $sku) {
            $distinct = DB::transaction(fn () => $this->participate(DB::connection(), $this->key($feed, $sku)));
            $this->assertNotSame($first->id, $distinct->id);
        }
    }

    public function test_no_public_participant_entry_point_or_production_caller_exists(): void
    {
        $class = new ReflectionClass(Repository::class);
        $this->assertTrue($class->isFinal());
        $this->assertTrue($class->getMethod('insertOrLockInTransaction')->isPrivate());
        $this->assertSame([], $class->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED));
        $before = DB::table(self::TABLE)->count();
        try {
            (new Repository)->insertOrLockInTransaction(DB::connection(), $this->key(SupplierFeed::factory()->create()));
            $this->fail('Private participant is publicly callable.');
        } catch (\Error $error) {
            $this->assertStringContainsString('private method', $error->getMessage());
        }
        $this->assertSame($before, DB::table(self::TABLE)->count());
    }

    public function test_invalid_raw_key_fails_before_connection_or_transaction(): void
    {
        $queries = [];
        DB::connection()->beforeExecuting(function ($sql) use (&$queries): void {
            $queries[] = $sql;
        });
        $transactions = 0;
        foreach ([null, false, 123, '', '   ', "\tA", "A\n", "\xC0\xAF", str_repeat('A', 256)] as $sku) {
            try {
                $key = Key::fromArray(['supplier_id' => 1, 'supplier_feed_id' => 1, 'supplier_sku_bytes' => $sku]);
                $transactions++;
                DB::transaction(fn () => $this->participate(DB::connection(), $key));
                $this->fail('Invalid raw key reached the transaction boundary.');
            } catch (\InvalidArgumentException) {
                $this->assertSame(0, $transactions);
                $this->assertSame([], $queries);
                $this->assertSame(0, DB::connection()->transactionLevel());
            }
        }
    }

    public function test_same_key_process_race_converges_after_observed_unique_key_wait(): void
    {
        $this->headRace(false);
    }

    public function test_rolled_back_first_inserter_allows_waiting_peer_and_full_retry(): void
    {
        $this->headRace(true);
    }

    public function test_distinct_keys_remain_distinct_without_false_conflict(): void
    {
        $a = SupplierFeed::factory()->create();
        $b = SupplierFeed::factory()->create(['supplier_id' => $a->supplier_id]);
        $c = SupplierFeed::factory()->create();
        $before = $this->protectedRows();
        $rows = [];
        foreach ([[$a, 'SKU'], [$a, 'sku'], [$a, "\u{00E9}"], [$a, "e\u{0301}"], [$b, 'SKU'], [$c, 'SKU']] as [$feed, $sku]) {
            $key = $this->key($feed, $sku);
            $rows[] = DB::transaction(fn () => $this->participate(DB::connection(), $key))->getRawOriginal();
        }
        $this->assertCount(6, array_unique(array_column($rows, 'id')));
        $this->assertSame(6, DB::table(self::TABLE)->count());
        $this->assertSame($before, $this->protectedRows());
    }

    public function test_distinct_key_processes_complete_without_shared_serialization(): void
    {
        $this->headRace(false, distinct: true);
    }

    public function test_deadlock_and_timeout_escape_without_local_retry(): void
    {
        $this->headRace(false, true);
        $key = $this->key(SupplierFeed::factory()->create(), 'timeout');
        $a = DB::connection();
        $b = $this->independent('slice_four_timeout');
        $b->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $a->beginTransaction();
        $this->participate($a, $key);
        $queries = [];
        $b->beforeExecuting(function ($sql) use (&$queries): void {
            $queries[] = $sql;
        });
        $b->beginTransaction();
        try {
            $this->participate($b, $key);
            $this->fail('Actual lock timeout was not raised.');
        } catch (QueryException $error) {
            $this->assertSame(['HY000', 1205], array_slice($error->errorInfo, 0, 2));
            $this->assertCount(1, $queries);
            $this->assertStringStartsWith('insert into', $queries[0]);
            $this->assertSame(1, $b->transactionLevel());
            $this->assertTrue($b->getPdo()->inTransaction());
            $this->recordCase('timeout', ['sqlstate' => $error->errorInfo[0], 'errno' => $error->errorInfo[1], 'queries' => $queries, 'localRetries' => 0]);
        } finally {
            $b->rollBack();
            $a->rollBack();
        }
        $this->assertSame(0, $b->table(self::TABLE)->where($key->persistenceAttributes())->count());
        $retry = $b->transaction(fn () => $this->participate($b, $key));
        $this->assertSame($key->persistenceAttributes()['supplier_sku_bytes'], $retry->getRawOriginal('supplier_sku_bytes'));
    }

    public function test_unknown_commit_requires_external_reconciliation_or_blocked_outcome(): void
    {
        $feed = SupplierFeed::factory()->create();
        $before = $this->protectedRows();
        foreach (['known-commit-fresh-read', 'unreadable-reconciliation'] as $index => $scenario) {
            $key = $this->key($feed, $scenario);
            $name = 'slice_four_commit_'.$index;
            $observerName = 'slice_four_reconcile_'.$index;
            $trace = (object) ['commitCalls' => 0, 'delegatedCommit' => false];
            $caller = $this->commitFaultConnection($name, $trace);
            try {
                $callerId = $caller->scalar('SELECT CONNECTION_ID()');
                $caller->beginTransaction();
                $head = $this->participate($caller, $key);
                try {
                    $caller->commit();
                    $this->fail('The caller received a COMMIT acknowledgement.');
                } catch (PDOException $error) {
                    $this->assertSame($trace->exception, $error);
                    $this->assertSame(['08007', 2013], array_slice($error->errorInfo, 0, 2));
                }
                $this->assertSame(1, $trace->commitCalls);
                $this->assertTrue($trace->delegatedCommit);
                $this->assertSame(1, $caller->transactionLevel());
                $this->assertFalse($caller->getPdo()->inTransaction());
                DB::purge($name);

                $observer = $this->independent($observerName);
                $observerId = $observer->scalar('SELECT CONNECTION_ID()');
                $this->assertNotSame($callerId, $observerId);
                $classification = 'UNKNOWN';
                if ($scenario === 'unreadable-reconciliation') {
                    $observer->beforeExecuting(static function (): void {
                        $error = new PDOException('synthetic reconciliation unavailable');
                        $error->errorInfo = ['08007', 2013, 'synthetic reconciliation unavailable'];
                        throw $error;
                    });
                    try {
                        $observer->table(self::TABLE)->where($key->persistenceAttributes())->sole();
                        $this->fail('Unreadable observer returned a result.');
                    } catch (PDOException $error) {
                        $this->assertSame(['08007', 2013], array_slice($error->errorInfo, 0, 2));
                        $classification = 'BLOCKED';
                    }
                    $this->assertSame('BLOCKED', $classification);
                } else {
                    $this->assertSame($head->getRawOriginal(), (array) $observer->table(self::TABLE)->where($key->persistenceAttributes())->sole());
                    $classification = 'EXACT_TUPLE';
                }
                $this->recordCase('commit-ack-'.$scenario, ['callerId' => $callerId, 'observerId' => $observerId, 'commitCalls' => $trace->commitCalls, 'fixtureKnownCommit' => $trace->delegatedCommit, 'callerClassification' => $classification, 'productionRecovery' => 'NOT IMPLEMENTED']);
            } finally {
                DB::forgetExtension($name);
                DB::purge($name);
                DB::purge($observerName);
            }
            // Fixture verification is separate from the caller's unavailable reconciliation.
            $this->assertSame($index + 1, DB::table(self::TABLE)->count());
            $this->assertSame($before, $this->protectedRows());
        }
    }

    public function test_forward_rejects_malformed_unknown_and_wrong_session_states(): void
    {
        $trace = (object) ['ddl' => [], 'queries' => [], 'fault' => null];
        $this->observeDdl($trace);
        try {
            $migration = $this->migration();
            $this->assertFailure('phase_three_p0_forward_precondition_failed', fn () => $migration->up());
            $this->assertSame([], $trace->ddl);
            $this->authorizeDown();
            $migration->down();
            $this->assertGrantConsumed();
            $trace->ddl = [];
            foreach ([
                ['ALTER TABLE supplier_import_source_payload_receipts ADD COLUMN unexpected int NULL', 'ALTER TABLE supplier_import_source_payload_receipts DROP COLUMN unexpected'],
                ["ALTER TABLE supplier_import_source_payload_receipts COMMENT='unexpected'", "ALTER TABLE supplier_import_source_payload_receipts COMMENT='mycomputer:phase-iii-p0:v1:owner=P0-04'"],
                ['ALTER TABLE supplier_import_source_payload_receipts ADD INDEX ix_unexpected (id)', 'ALTER TABLE supplier_import_source_payload_receipts DROP INDEX ix_unexpected'],
            ] as [$corrupt, $restore]) {
                DB::unprepared($corrupt);
                try {
                    $this->assertFailure('phase_three_p0_forward_precondition_failed', fn () => $migration->up());
                    $this->assertSame([], $trace->ddl);
                } finally {
                    DB::unprepared($restore);
                }
                $this->assertPrefix('P4');
            }
            $trigger = (array) DB::selectOne('SHOW CREATE TRIGGER trg_import_source_payload_receipt_no_delete');
            DB::unprepared('DROP TRIGGER trg_import_source_payload_receipt_no_delete');
            $this->assertFailure('phase_three_p0_forward_precondition_failed', fn () => $migration->up());
            $this->assertSame([], $trace->ddl);
            DB::unprepared($trigger['SQL Original Statement']);
            $this->assertFailure('phase_three_p0_forward_precondition_failed', fn () => $migration->up());
            $this->assertSame([], $trace->ddl);
            DB::unprepared('DROP TRIGGER trg_import_source_payload_receipt_no_delete');
            $this->restoreTrigger($trigger);
            $this->assertPrefix('P4');
            config()->set('supplier_snapshot_capture.capture_enabled', true);
            $this->assertFailure('phase_three_p0_protected_gate_enabled', fn () => $migration->up());
            config()->set('supplier_snapshot_capture.capture_enabled', false);
            $holder = $this->independent('slice_four_guard')->getPdo();
            $lock = 'mycomputer:phase-iii-p0-schema-ddl-v1';
            $this->assertSame(1, $holder->query("SELECT GET_LOCK('{$lock}',0)")->fetchColumn());
            try {
                $this->assertFailure('phase_three_p0_schema_guard_unavailable', fn () => $migration->up());
            } finally {
                $this->assertSame(1, $holder->query("SELECT RELEASE_LOCK('{$lock}')")->fetchColumn());
            }
            $trace->fault = 'inspection';
            $this->assertFailure('phase_three_p0_schema_inspection_unavailable', fn () => $migration->up());
            $this->assertSame([], $trace->ddl);
            $trace->fault = null;
            $p4 = require database_path('migrations/2026_08_28_090003_create_supplier_import_source_payload_receipts_table.php');
            $this->authorizeDown();
            $p4->down();
            $this->assertGrantConsumed();
            $beforeWrongPrefix = $trace->ddl;
            $this->assertFailure('phase_three_p0_forward_precondition_failed', fn () => $migration->up());
            $this->assertSame($beforeWrongPrefix, $trace->ddl);
            $p4->up();
            $migration->up();
            $this->assertPrefix('P5');
        } finally {
            config()->set('supplier_snapshot_capture.capture_enabled', false);
            $this->stopObservingDdl();
        }
        $this->recordCase('forward', (array) $trace);
    }

    public function test_down_rejects_nonpristine_nonterminal_malformed_and_changed_gates(): void
    {
        Log::spy();
        $migration = $this->migration();
        $trace = (object) ['ddl' => [], 'queries' => [], 'fault' => null];
        $this->observeDdl($trace);
        $before = $this->protectedRows();
        try {
            foreach ([null, 'false', 'TRUE', '1', ' true '] as $grant) {
                putenv(self::GRANT.($grant === null ? '' : '='.$grant));
                $_ENV[self::GRANT] = $_SERVER[self::GRANT] = $grant;
                $this->rejectedDown($migration, 'phase_three_p0_downgrade_not_authorized', $trace);
            }
            $environment = app()['env'];
            try {
                app()->instance('env', 'production');
                $this->authorizeDown();
                $this->rejectedDown($migration, 'phase_three_p0_downgrade_not_authorized', $trace);
            } finally {
                app()->instance('env', $environment);
            }
            foreach (['capture_enabled', 'protected_generation_admission_enabled', 'recovery_issuance_enabled', 'recovery_execution_enabled', 'monitor_schedule_enabled', 'observer_schedule_enabled', 'alert_delivery_enabled'] as $gate) {
                config()->set('supplier_snapshot_capture.'.$gate, true);
                try {
                    $this->authorizeDown();
                    $this->rejectedDown($migration, 'phase_three_p0_protected_gate_enabled', $trace);
                } finally {
                    config()->set('supplier_snapshot_capture.'.$gate, false);
                }
            }
            foreach ([
                ['ALTER TABLE '.self::TABLE.' ADD COLUMN unexpected int NULL', 'ALTER TABLE '.self::TABLE.' DROP COLUMN unexpected', 'UNCLASSIFIED_P0_SCHEMA_STATE', 'UNCLASSIFIED_P0_SCHEMA_STATE'],
                // The frozen inspector discovers this table by its owner comment, not by name.
                ['ALTER TABLE '.self::TABLE." COMMENT='unexpected'", 'ALTER TABLE '.self::TABLE." COMMENT='mycomputer:phase-iii-p0:v1:owner=P0-05'", 'P4', 'phase_three_p0_step_not_terminal'],
                ['ALTER TABLE '.self::TABLE." ALTER created_at SET DEFAULT '2000-01-01 00:00:00.000000'", 'ALTER TABLE '.self::TABLE.' MODIFY created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)', 'UNCLASSIFIED_P0_SCHEMA_STATE', 'UNCLASSIFIED_P0_SCHEMA_STATE'],
                ['ALTER TABLE '.self::TABLE.' DROP CHECK chk_supplier_product_identity_head_sku_bytes', 'ALTER TABLE '.self::TABLE.' ADD CONSTRAINT chk_supplier_product_identity_head_sku_bytes CHECK (length(supplier_sku_bytes) between 1 and 1020)', 'UNCLASSIFIED_P0_SCHEMA_STATE', 'UNCLASSIFIED_P0_SCHEMA_STATE'],
                ['ALTER TABLE '.self::TABLE.' MODIFY supplier_sku_bytes varbinary(1019) NOT NULL', 'ALTER TABLE '.self::TABLE.' MODIFY supplier_sku_bytes varbinary(1020) NOT NULL', 'UNCLASSIFIED_P0_SCHEMA_STATE', 'UNCLASSIFIED_P0_SCHEMA_STATE'],
                ['CREATE TABLE supplier_product_identity_heads_unexpected (id int) COMMENT=\'mycomputer:phase-iii-p0:v1:owner=P0-05\'', 'DROP TABLE supplier_product_identity_heads_unexpected', 'UNCLASSIFIED_P0_SCHEMA_STATE', 'UNCLASSIFIED_P0_SCHEMA_STATE'],
            ] as [$corrupt, $restore, $expectedState, $expectedRejection]) {
                DB::unprepared($corrupt);
                try {
                    $classification = P0::classify(DB::connection()->getPdo());
                    $this->assertSame($expectedState, $classification['state']);
                    $this->assertSame(0, DB::table(self::TABLE)->count());
                    $this->authorizeDown();
                    $this->rejectedDown($migration, $expectedRejection, $trace);
                    $this->assertSame(0, DB::table(self::TABLE)->count());
                    $this->recordCase('malformed-down', ['fixtureDdl' => $corrupt, 'classification' => $classification, 'expectedRejection' => $expectedRejection, 'ddl' => $trace->ddl]);
                } finally {
                    DB::unprepared($restore);
                }
                $this->assertPrefix('P5');
            }
            $database = DB::getDatabaseName();
            $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_]+\z/', $database);
            $databaseCollation = $this->schemaDefaultCollation();
            try {
                DB::unprepared("ALTER DATABASE `{$database}` COLLATE utf8mb4_bin");
                $this->authorizeDown();
                $this->rejectedDown($migration, 'UNCLASSIFIED_P0_SCHEMA_STATE', $trace);
            } finally {
                DB::unprepared("ALTER DATABASE `{$database}` COLLATE {$databaseCollation}");
            }
            $this->assertSame($databaseCollation, $this->schemaDefaultCollation());
            $inspection = (new CanonicalSupplierPhaseThreeP0SchemaInspector(DB::connection()->getPdo()))->inspect();
            $this->assertSame($databaseCollation, $inspection['schema_default_collation']);
            foreach ($inspection['raw_signatures'] as $signature) {
                if ($signature['type'] === 'trigger') {
                    $this->assertSame($databaseCollation, $signature['database_collation']);
                }
            }
            $this->assertPrefix('P5');
            $trigger = (array) DB::selectOne('SHOW CREATE TRIGGER trg_supplier_product_identity_head_no_delete');
            DB::unprepared('DROP TRIGGER trg_supplier_product_identity_head_no_delete');
            $this->authorizeDown();
            $this->rejectedDown($migration, 'UNCLASSIFIED_P0_SCHEMA_STATE', $trace);
            DB::unprepared($trigger['SQL Original Statement']);
            $this->authorizeDown();
            $this->rejectedDown($migration, 'UNCLASSIFIED_P0_SCHEMA_STATE', $trace);
            DB::unprepared('DROP TRIGGER trg_supplier_product_identity_head_no_delete');
            $this->restoreTrigger($trigger);
            $this->assertPrefix('P5');
            $this->assertSame($before, $this->protectedRows());
            $key = $this->key(SupplierFeed::factory()->create());
            DB::transaction(fn () => $this->participate(DB::connection(), $key));
            $row = (array) DB::table(self::TABLE)->sole();
            $before = $this->protectedRows();
            $this->authorizeDown();
            $this->rejectedDown($migration, 'phase_three_p0_append_only_table_not_pristine', $trace);
            $this->assertSame($row, (array) DB::table(self::TABLE)->sole());
            $this->assertSame($before, $this->protectedRows());
        } finally {
            $this->stopObservingDdl();
        }
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $message === 'phase_three_p0_downgrade_evidence_v1' && $context['primary_outcome'] === 'phase_three_p0_append_only_table_not_pristine' && $context['completed_ddl_ids'] === []);
        $this->recordCase('down-rejections', (array) $trace);
    }

    public function test_forward_partial_ddl_never_becomes_false_p5_or_automatic_repair(): void
    {
        $migration = $this->migration();
        $this->authorizeDown();
        $migration->down();
        $this->assertGrantConsumed();
        $trace = (object) ['ddl' => [], 'queries' => [], 'fault' => 'after-create'];
        $before = $this->protectedRows();
        $this->observeDdl($trace);
        try {
            $this->assertFailure('synthetic_after_create_failure', fn () => $migration->up());
            $this->assertCount(1, $trace->ddl);
            $this->assertStringContainsString('CREATE TABLE', $trace->ddl[0]['sql']);
            $state = P0::classify(DB::connection()->getPdo());
            $this->assertSame('UNCLASSIFIED_P0_SCHEMA_STATE', $state['state']);
            $this->assertSame($before, $this->protectedRows());
            $this->assertSame(0, DB::table(self::TABLE)->count());
            $trace->fault = null;
            $this->assertFailure('phase_three_p0_forward_precondition_failed', fn () => $migration->up());
            $this->assertCount(1, $trace->ddl);
            $this->recordCase('partial-forward', ['classified' => $state, 'trace' => (array) $trace, 'automaticRepair' => false]);
        } finally {
            $this->stopObservingDdl();
        }
    }

    public function test_down_dependency_inspection_failures_reject_before_ddl(): void
    {
        $migration = $this->migration();
        $trace = (object) ['ddl' => [], 'queries' => [], 'fault' => null, 'dependencyInspections' => 0];
        $before = $this->protectedRows();
        $this->observeDdl($trace);
        Log::spy();
        try {
            foreach (['dependency-inspection', 'dependency-invalid-result', 'dependency-transport'] as $index => $fault) {
                $trace->fault = $fault;
                $this->authorizeDown();
                $this->rejectedDown($migration, 'phase_three_p0_schema_inspection_unavailable', $trace);
                $this->assertSame($index + 1, $trace->dependencyInspections);
                $this->assertSame([], $trace->ddl);
                $this->assertSame($before, $this->protectedRows());
                $this->assertSame(0, DB::table(self::TABLE)->count());
                $this->assertPrefix('P5');
                $connectionStatus = $fault === 'dependency-transport' ? 'UNCERTAIN' : 'CONNECTED';
                Log::shouldHaveReceived('info')->withArgs(fn ($event, $context) => $event === 'phase_three_p0_downgrade_evidence_v1'
                    && $context['primary_outcome'] === 'phase_three_p0_schema_inspection_unavailable'
                    && $context['connection_status'] === $connectionStatus
                    && $context['last_verified_state'] === 'P5'
                    && $context['completed_ddl_ids'] === []);
                $this->recordCase($fault, ['ddl' => $trace->ddl, 'connectionStatus' => $connectionStatus, 'protectedRows' => $before, 'grantConsumed' => true]);
            }
        } finally {
            $this->stopObservingDdl();
        }
    }

    public function test_down_rejects_unmarked_inbound_dependency_before_ddl(): void
    {
        $migration = $this->migration();
        $trace = (object) ['ddl' => [], 'queries' => [], 'fault' => null];
        $before = $this->protectedRows();
        DB::unprepared('CREATE TABLE slice_four_head_dependency (id bigint unsigned NOT NULL PRIMARY KEY, head_id bigint unsigned NULL, CONSTRAINT fk_slice_four_head_dependency FOREIGN KEY (head_id) REFERENCES '.self::TABLE.' (id) ON DELETE RESTRICT ON UPDATE RESTRICT) ENGINE=InnoDB');
        $this->observeDdl($trace);
        try {
            $withDependency = $this->protectedRows();
            $this->assertPrefix('P5');
            $this->assertSame(0, DB::table(self::TABLE)->count());
            $this->authorizeDown();
            $this->rejectedDown($migration, 'phase_three_p0_identity_head_has_inbound_dependencies', $trace);
            $this->assertSame([], $trace->ddl);
            $this->assertSame($withDependency, $this->protectedRows());
            $this->assertPrefix('P5');
            $this->recordCase('inbound-dependency', ['ddl' => $trace->ddl, 'protectedRows' => $withDependency, 'grantConsumed' => true]);
        } finally {
            DB::unprepared('DROP TABLE slice_four_head_dependency');
            $this->stopObservingDdl();
        }
        $this->assertSame($before, $this->protectedRows());
        $this->authorizeDown();
        $migration->down();
        $this->assertGrantConsumed();
        $this->assertPrefix('P4');
        $this->assertSame($before, $this->protectedRows());
        $migration->up();
        $this->assertPrefix('P5');
        $this->assertSame($before, $this->protectedRows());
    }

    public function test_down_guard_contention_from_an_independent_process_rejects_without_waiting(): void
    {
        $this->assertTrue(function_exists('pcntl_fork'));
        $before = $this->protectedRows();
        $directory = $this->caseDirectory('down-guard');
        $lock = 'mycomputer:phase-iii-p0-schema-ddl-v1';
        $children = $waited = [];
        foreach (array_keys(DB::getConnections()) as $name) {
            DB::purge($name);
        }
        try {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                $result = ['pid' => getmypid(), 'startUtc' => gmdate('c')];
                try {
                    $holder = DB::connection()->getPdo();
                    $result['connectionId'] = $holder->query('SELECT CONNECTION_ID()')->fetchColumn();
                    if ($holder->query("SELECT GET_LOCK('{$lock}',0)")->fetchColumn() !== 1) {
                        throw new RuntimeException('Synthetic child did not acquire the DDL guard.');
                    }
                    file_put_contents($directory.'/ready.tmp', json_encode($result, JSON_THROW_ON_ERROR));
                    rename($directory.'/ready.tmp', $directory.'/ready');
                    $this->awaitFile($directory.'/release');
                    $result['ownerBeforeRelease'] = $holder->query("SELECT IS_USED_LOCK('{$lock}')")->fetchColumn();
                    $result['releaseResult'] = $holder->query("SELECT RELEASE_LOCK('{$lock}')")->fetchColumn();
                    $result['status'] = 'completed';
                } catch (Throwable $error) {
                    $result['status'] = 'failed';
                    $result['errorClass'] = $error::class;
                    $result['error'] = $error->getMessage();
                }
                $result['endUtc'] = gmdate('c');
                file_put_contents($directory.'/result.json', json_encode($result, JSON_THROW_ON_ERROR));
                exit($result['status'] === 'completed' ? 0 : 1);
            }
            $children[] = $pid;
            $this->awaitFile($directory.'/ready');
            $ready = json_decode(file_get_contents($directory.'/ready'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($pid, $ready['pid']);
            $this->assertNotSame(getmypid(), $ready['pid']);
            $this->assertNotSame(DB::scalar('SELECT CONNECTION_ID()'), $ready['connectionId']);
            $trace = (object) ['ddl' => [], 'queries' => [], 'fault' => null];
            $this->observeDdl($trace);
            $start = microtime(true);
            $this->authorizeDown();
            $this->rejectedDown($this->migration(), 'phase_three_p0_schema_guard_unavailable', $trace);
            $elapsed = microtime(true) - $start;
            $this->assertLessThan(5.0, $elapsed, 'Guard rejection waited for the holder.');
            $this->assertContains("SELECT GET_LOCK('{$lock}', 0)", $trace->queries);
            $this->assertSame([], $trace->ddl);
            $this->assertSame($ready['connectionId'], DB::scalar("SELECT IS_USED_LOCK('{$lock}')"));
            $this->assertSame($before, $this->protectedRows());
            $this->assertPrefix('P5');
            touch($directory.'/release');
            $this->waitForSuccessfulChild($pid, $waited);
            $result = json_decode(file_get_contents($directory.'/result.json'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('completed', $result['status']);
            $this->assertSame($ready['connectionId'], $result['ownerBeforeRelease']);
            $this->assertSame(1, $result['releaseResult']);
            $this->assertNull(DB::scalar("SELECT IS_USED_LOCK('{$lock}')"));
            file_put_contents($directory.'/parent-result.json', json_encode(['utc' => gmdate('c'), 'holder' => $result, 'rejectionSeconds' => $elapsed, 'queries' => $trace->queries, 'ddl' => $trace->ddl, 'protectedRows' => $before], JSON_THROW_ON_ERROR));
        } finally {
            try {
                $this->stopObservingDdl();
            } finally {
                $this->cleanupChildren($children, $waited, $directory);
            }
        }
    }

    public function test_owned_child_cleanup_escalates_ignored_term_with_bounded_reaping(): void
    {
        $this->assertTrue(function_exists('pcntl_fork'));
        $directory = $this->caseDirectory('cleanup-escalation');
        $children = $waited = [];
        foreach (array_keys(DB::getConnections()) as $name) {
            DB::purge($name);
        }
        try {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                pcntl_signal(SIGTERM, SIG_IGN);
                file_put_contents($directory.'/ready', (string) getmypid());
                $this->awaitFile($directory.'/never-release');
                exit(1);
            }
            $children[] = $pid;
            $this->awaitFile($directory.'/ready');
            $start = microtime(true);
            $this->cleanupChildren($children, $waited, $directory);
            $elapsed = microtime(true) - $start;
            $waited[] = $pid;
            $record = json_decode(file_get_contents($directory.'/cleanup.json'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame([], $record['unreaped']);
            $this->assertLessThan(5.0, $elapsed);
            $this->assertSame([SIGTERM, SIGKILL], array_values(array_column($record['actions'], 'signal')));
            $reaped = array_values(array_filter($record['actions'], fn ($action) => ($action['waitResult'] ?? null) === $pid));
            $this->assertCount(1, $reaped);
            $this->assertTrue(pcntl_wifsignaled($reaped[0]['status']));
            $this->assertSame(SIGKILL, pcntl_wtermsig($reaped[0]['status']));
        } finally {
            if (array_diff($children, $waited) !== []) {
                $this->cleanupChildren($children, $waited, $directory);
            }
        }
    }

    public function test_down_repeated_nested_and_uncertain_invocations_remain_fail_closed(): void
    {
        $migration = $this->migration();
        $trace = (object) ['ddl' => [], 'queries' => [], 'fault' => null];
        $this->observeDdl($trace);
        Log::spy();
        $before = $this->protectedRows();
        try {
            $holder = $this->independent('slice_four_down_guard')->getPdo();
            $lock = 'mycomputer:phase-iii-p0-schema-ddl-v1';
            $this->assertSame(1, $holder->query("SELECT GET_LOCK('{$lock}',0)")->fetchColumn());
            try {
                $this->authorizeDown();
                $this->rejectedDown($migration, 'phase_three_p0_schema_guard_unavailable', $trace);
            } finally {
                $this->assertSame(1, $holder->query("SELECT RELEASE_LOCK('{$lock}')")->fetchColumn());
            }
            $trace->fault = 'inspection';
            $this->authorizeDown();
            $this->rejectedDown($migration, 'phase_three_p0_schema_inspection_unavailable', $trace);
            $trace->fault = null;
            $trace->nested = function () use ($migration, $trace): void {
                $owner = DB::scalar("SELECT IS_USED_LOCK('mycomputer:phase-iii-p0-schema-ddl-v1')");
                $this->assertIsInt($owner);
                $this->assertGreaterThan(0, $owner);
                $this->authorizeDown();
                $this->rejectedDown($migration, 'phase_three_p0_nested_downgrade_forbidden', $trace);
                $this->assertSame($owner, DB::scalar("SELECT IS_USED_LOCK('mycomputer:phase-iii-p0-schema-ddl-v1')"));
            };
            $this->authorizeDown();
            $migration->down();
            $this->assertGrantConsumed();
            $this->assertPrefix('P4');
            $this->rejectedDown($migration, 'phase_three_p0_downgrade_not_authorized', $trace);
            $this->authorizeDown();
            $this->rejectedDown($migration, 'phase_three_p0_step_not_terminal', $trace);
            $migration->up();
            foreach (['before-drop', 'after-drop'] as $fault) {
                $trace->fault = $fault;
                $this->authorizeDown();
                $this->assertFailure('phase_three_p0_connection_outcome_unknown', fn () => $migration->down());
                $this->assertGrantConsumed();
                $this->rejectedDown($migration, 'phase_three_p0_downgrade_not_authorized', $trace);
                $this->assertPrefix($fault === 'before-drop' ? 'P5' : 'P4');
                $this->assertSame($before, $this->protectedRows());
                $trace->fault = null;
                if ($fault === 'before-drop') {
                    $this->authorizeDown();
                    $migration->down();
                    $this->assertGrantConsumed();
                }
                $migration->up();
                $this->assertPrefix('P5');
            }
        } finally {
            $this->stopObservingDdl();
        }
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $message === 'phase_three_p0_downgrade_evidence_v1' && $context['primary_outcome'] === 'phase_three_p0_connection_outcome_unknown' && $context['connection_status'] === 'UNCERTAIN' && $context['completed_ddl_ids'] === [] && in_array('phase_three_p0_schema_guard_release_unavailable', $context['secondary_codes'], true));
        unset($trace->nested);
        $this->recordCase('down-uncertain', (array) $trace);
    }

    public function test_complete_parent_catalog_staging_and_super_admin_access_preservation(): void
    {
        $this->authorizeDown();
        $migration = $this->migration();
        $migration->down();
        $this->assertGrantConsumed();
        // Reuse only the existing synthetic Phase I graph, not its commands or guard setup.
        $fixture = new CanonicalSupplierSnapshotSchemaMigrationTest('test_mysql_constraints_reject_cross_parent_and_immutable_mutations');
        $graph = (new ReflectionMethod($fixture, 'seedProtectedGraph'))->invoke($fixture, DB::getDefaultConnection());
        $feed = SupplierFeed::findOrFail($graph['feed_id']);
        $feed->update(['feed_type' => 'csv', 'mapping' => ['sku' => 'code']]);
        $this->sourceEvidence($feed->fresh(), 'preservation');
        $user = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN, 'is_active' => true, 'deleted_at' => null]);
        $role = Role::findOrCreate(User::ROLE_SUPER_ADMIN, 'web');
        $permission = Permission::findOrCreate('synthetic-p0-preservation', 'web');
        $role->givePermissionTo($permission);
        $user->assignRole($role);
        $user->givePermissionTo($permission);
        DB::table('sessions')->insert(['id' => 'synthetic-p0-preservation', 'user_id' => $user->id, 'payload' => base64_encode('synthetic'), 'last_activity' => 1]);
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id, 'price' => 125]);
        ProductAttributeValue::factory()->create(['product_id' => $product->id]);
        DB::table('product_images')->insert(['product_id' => $product->id, 'path' => 'synthetic/never-fetched.png', 'alt_text' => 'manual synthetic', 'is_primary' => true]);
        $staging = SupplierProduct::query()->create(['supplier_id' => $feed->supplier_id, 'supplier_feed_id' => $feed->id, 'supplier_sku' => 'synthetic-preservation', 'product_id' => $product->id, 'raw_data' => ['synthetic' => true], 'payload_hash' => hash('sha256', 'synthetic'), 'received_at' => now('UTC')]);
        DB::table('product_supplier_offers')->insert(['product_id' => $product->id, 'supplier_id' => $feed->supplier_id, 'supplier_product_id' => $staging->id, 'supplier_sku' => 'synthetic-preservation', 'price' => 125]);
        $before = $this->protectedRows();
        foreach (['supplier_import_execution_claims', 'supplier_import_dispatch_outbox', 'supplier_import_dispatch_alert_intents', 'supplier_import_dispatch_recovery_authorizations', 'supplier_import_dispatch_recovery_results', 'supplier_import_cohort_authorization_members', 'supplier_offer_snapshot_generations', 'supplier_offer_snapshot_enrollments', 'supplier_offer_snapshot_observations', 'supplier_import_dispatch_monitor_health', 'supplier_import_source_payload_receipts', 'sessions', 'model_has_permissions', 'role_has_permissions'] as $table) {
            $this->assertNotEmpty($before[$table], 'Protected synthetic fixture missing: '.$table);
        }
        $this->assertAdmin($user->id);
        foreach (['up', 'down', 'up'] as $operation) {
            if ($operation === 'down') {
                $this->authorizeDown();
            }
            $migration->{$operation}();
            $this->assertGrantConsumed();
            $this->assertPrefix($operation === 'down' ? 'P4' : 'P5');
            $this->assertSame($before, $this->protectedRows());
            $this->assertAdmin($user->id);
        }
        $this->recordCase('complete-preservation', ['tables' => $before, 'activeSuperAdminId' => $user->id, 'transitions' => ['P4-P5', 'P5-P4', 'P4-P5'], 'accessPredicates' => 'PASS']);
    }

    private function rejectedDown(object $migration, string $error, object $trace): void
    {
        $before = $trace->ddl;
        $this->assertFailure($error, fn () => $migration->down());
        $this->assertGrantConsumed();
        $this->assertFailure('phase_three_p0_downgrade_not_authorized', fn () => $migration->down());
        $this->assertGrantConsumed();
        $this->assertSame($before, $trace->ddl, 'Rejected invocation executed DDL.');
    }

    private function restoreTrigger(array $trigger): void
    {
        $session = $this->creationSession();
        try {
            DB::unprepared('SET SESSION sql_mode = '.DB::connection()->getPdo()->quote($trigger['sql_mode']));
            DB::unprepared('SET character_set_client=cp866, collation_connection=cp866_general_ci');
            DB::unprepared($trigger['SQL Original Statement']);
        } finally {
            DB::unprepared('SET SESSION sql_mode = '.DB::connection()->getPdo()->quote($session['sql_mode']));
            DB::unprepared('SET character_set_client='.DB::connection()->getPdo()->quote($session['charset']).', collation_connection='.DB::connection()->getPdo()->quote($session['collation_connection']));
        }
    }

    private function observeDdl(object $trace): void
    {
        DB::extend('phase_three_p0_schema_ddl', static function (array $config, string $name) use ($trace): MySqlConnection {
            $pdo = new class($config, $trace) extends PDO
            {
                private bool $lost = false;

                public function __construct(array $config, private object $trace)
                {
                    parent::__construct('mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'].';charset=utf8mb4', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => false]);
                    if ($this->trace->captureSession ?? false) {
                        parent::exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION', character_set_client='utf8mb4', character_set_results='latin1', collation_connection='utf8mb4_bin'");
                        $this->trace->sessionId = parent::query('SELECT CONNECTION_ID()')->fetchColumn();
                        $this->trace->sessionBefore = $this->sessionSettings();
                        $this->trace->creationSessions = [];
                    }
                }

                public function exec(string $statement): int|false
                {
                    $ddl = preg_match('/\A(?:CREATE|ALTER|DROP) /i', ltrim($statement)) === 1;
                    if ($ddl) {
                        $this->trace->ddl[] = ['utc' => gmdate('c'), 'sql' => $statement, 'stage' => 'ATTEMPT'];
                    }
                    if (($this->trace->captureSession ?? false) && str_starts_with(ltrim($statement), 'CREATE TRIGGER')) {
                        $this->trace->creationSessions[] = $this->sessionSettings();
                    }
                    if (str_starts_with($statement, 'DROP TABLE') && $this->trace->fault === 'before-drop') {
                        $this->lost = true;
                        $this->failTransport();
                    }
                    $result = parent::exec($statement);
                    if ($ddl) {
                        $this->trace->completed[] = ['utc' => gmdate('c'), 'sql' => $statement];
                    }
                    if ($ddl && str_starts_with(ltrim($statement), 'CREATE TABLE') && $this->trace->fault === 'after-create') {
                        throw new RuntimeException('synthetic_after_create_failure');
                    }
                    if (str_starts_with($statement, 'DROP TABLE') && $this->trace->fault === 'after-drop') {
                        $this->lost = true;
                        $this->failTransport();
                    }

                    return $result;
                }

                public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
                {
                    $this->trace->queries[] = $query;
                    if ($this->lost) {
                        $this->failTransport();
                    }
                    if (($this->trace->captureSession ?? false) && str_starts_with($query, 'SELECT RELEASE_LOCK(')) {
                        $this->trace->sessionAfter = $this->sessionSettings();
                    }
                    if (str_starts_with(ltrim($query), 'SELECT EXISTS(') && str_contains($query, 'information_schema.KEY_COLUMN_USAGE')) {
                        $this->trace->dependencyInspections = ($this->trace->dependencyInspections ?? 0) + 1;
                        if ($this->trace->fault === 'dependency-inspection') {
                            throw new RuntimeException('synthetic inbound dependency metadata unavailable');
                        }
                        if ($this->trace->fault === 'dependency-invalid-result') {
                            return parent::query("SELECT '0'");
                        }
                        if ($this->trace->fault === 'dependency-transport') {
                            $this->lost = true;
                            $this->failTransport();
                        }
                    }
                    if ($query === 'SELECT DATABASE()') {
                        if ($this->trace->fault === 'inspection') {
                            throw new RuntimeException('synthetic_metadata_unavailable');
                        }
                        if (isset($this->trace->nested)) {
                            $nested = $this->trace->nested;
                            unset($this->trace->nested);
                            $nested();
                        }
                    }

                    return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
                }

                private function sessionSettings(): array
                {
                    return parent::query('SELECT @@session.sql_mode AS sql_mode, @@session.character_set_client AS character_set_client, @@session.character_set_results AS character_set_results, @@session.collation_connection AS collation_connection')->fetch(PDO::FETCH_ASSOC);
                }

                private function failTransport(): never
                {
                    $error = new PDOException('synthetic DDL transport acknowledgement failure');
                    $error->errorInfo = ['08S01', 2013, 'synthetic DDL transport acknowledgement failure'];
                    throw $error;
                }
            };

            return new MySqlConnection($pdo, $config['database'], '', [...$config, 'name' => $name]);
        });
    }

    private function stopObservingDdl(): void
    {
        DB::forgetExtension('phase_three_p0_schema_ddl');
        DB::purge('phase_three_p0_schema_ddl');
    }

    private function commitFaultConnection(string $name, object $trace): Connection
    {
        config()->set('database.connections.'.$name, config('database.connections.'.DB::getDefaultConnection()));
        DB::extend($name, static function (array $config, string $connectionName) use ($trace): MySqlConnection {
            $pdo = new class($config, $trace) extends PDO
            {
                public function __construct(array $config, private object $trace)
                {
                    parent::__construct('mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'].';charset=utf8mb4', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => false]);
                }

                public function commit(): bool
                {
                    $this->trace->commitCalls++;
                    $this->trace->delegatedCommit = parent::commit();
                    $error = new PDOException('synthetic COMMIT acknowledgement lost');
                    $error->errorInfo = ['08007', 2013, 'synthetic COMMIT acknowledgement lost'];
                    $this->trace->exception = $error;
                    throw $error;
                }
            };

            return new MySqlConnection($pdo, $config['database'], '', [...$config, 'driver' => 'mysql', 'name' => $connectionName]);
        });

        return DB::connection($name);
    }

    private function headRace(bool $rollback, bool $deadlock = false, bool $distinct = false): void
    {
        $this->assertTrue(function_exists('pcntl_fork'));
        $key = $this->key(SupplierFeed::factory()->create(), 'race-a');
        $keyB = Key::fromArray([...$key->persistenceAttributes(), 'supplier_sku_bytes' => 'race-b']);
        $before = $this->protectedRows();
        $directory = $this->caseDirectory('race');
        $children = $waited = [];
        foreach (array_keys(DB::getConnections()) as $name) {
            DB::purge($name);
        }
        try {
            foreach ([0, 1] as $index) {
                $pid = pcntl_fork();
                $this->assertNotSame(-1, $pid);
                if ($pid === 0) {
                    $result = ['pid' => getmypid(), 'index' => $index, 'startUtc' => gmdate('c')];
                    try {
                        $connection = DB::connection();
                        $connection->statement('SET SESSION innodb_lock_wait_timeout = 20');
                        $result['connectionId'] = $connection->scalar('SELECT CONNECTION_ID()');
                        file_put_contents($directory.'/ready-'.$index, (string) $result['connectionId']);
                        $this->awaitFile($directory.'/start-'.$index);
                        $connection->beginTransaction();
                        $first = $this->participate($connection, ($deadlock || $distinct) && $index === 1 ? $keyB : $key);
                        if ($deadlock || $index === 0) {
                            if ($deadlock && $index === 0) {
                                // Give the first transaction greater rollback weight, fixing the victim.
                                for ($n = 0; $n < 20; $n++) {
                                    $this->participate($connection, Key::fromArray([...$key->persistenceAttributes(), 'supplier_sku_bytes' => 'weight-'.$n]));
                                }
                            }
                            file_put_contents($directory.'/held-'.$index, json_encode($first->getRawOriginal(), JSON_THROW_ON_ERROR));
                            $this->awaitFile($directory.'/release-'.$index);
                        }
                        if ($deadlock) {
                            $queries = [];
                            $connection->beforeExecuting(function ($sql) use (&$queries): void {
                                $queries[] = $sql;
                            });
                            try {
                                $this->participate($connection, $index === 0 ? $keyB : $key);
                            } catch (QueryException $error) {
                                $result['failure'] = array_slice($error->errorInfo, 0, 2);
                                $result['queries'] = $queries;
                                $result['transactionLevel'] = $connection->transactionLevel();
                                $connection->rollBack();
                            }
                        }
                        if ($connection->transactionLevel() > 0) {
                            if ($rollback && $index === 0) {
                                $connection->rollBack();
                            } else {
                                $connection->commit();
                            }
                        }
                        $result['row'] = $first->getRawOriginal();
                        $result['status'] = 'completed';
                    } catch (Throwable $error) {
                        $result['status'] = 'failed';
                        $result['errorClass'] = $error::class;
                        $result['error'] = $error->getMessage();
                    }
                    $result['endUtc'] = gmdate('c');
                    file_put_contents($directory.'/result-'.$index.'.json', json_encode($result, JSON_THROW_ON_ERROR));
                    exit($result['status'] === 'completed' ? 0 : 1);
                }
                $children[] = $pid;
            }
            $this->awaitFile($directory.'/ready-0');
            $this->awaitFile($directory.'/ready-1');
            $ids = [(int) file_get_contents($directory.'/ready-0'), (int) file_get_contents($directory.'/ready-1')];
            $this->assertNotSame($ids[0], $ids[1]);
            touch($directory.'/start-0');
            $this->awaitFile($directory.'/held-0');
            touch($directory.'/start-1');
            if ($deadlock) {
                $this->awaitFile($directory.'/held-1');
                touch($directory.'/release-0');
            }
            if ($distinct) {
                $this->awaitFile($directory.'/result-1.json');
                $this->waitForSuccessfulChild($children[1], $waited);
                $peer = json_decode(file_get_contents($directory.'/result-1.json'), true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame('completed', $peer['status']);
                $this->assertFileDoesNotExist($directory.'/release-0');
                $this->assertSame(0, pcntl_waitpid($children[0], $status, WNOHANG), 'First caller must still hold its transaction.');
                $this->assertSame(0, DB::table(self::TABLE)->where($key->persistenceAttributes())->count());
                $this->assertSame($peer['row'], (array) DB::table(self::TABLE)->where($keyB->persistenceAttributes())->sole());
                $this->assertSame(1, DB::table(self::TABLE)->count());
                file_put_contents($directory.'/distinct-before-release.json', json_encode(['utc' => gmdate('c'), 'holderPid' => $children[0], 'holderConnectionId' => $ids[0], 'completedPeer' => $peer, 'observerId' => DB::scalar('SELECT CONNECTION_ID()'), 'firstCallerStillHeld' => true], JSON_THROW_ON_ERROR));
            } else {
                $edge = [];
                $deadline = microtime(true) + 10;
                do {
                    $edge = DB::select('SELECT r.PROCESSLIST_ID AS requester, b.PROCESSLIST_ID AS blocker, l.OBJECT_NAME AS table_name, l.INDEX_NAME AS index_name FROM performance_schema.data_lock_waits w JOIN performance_schema.threads r ON r.THREAD_ID=w.REQUESTING_THREAD_ID JOIN performance_schema.threads b ON b.THREAD_ID=w.BLOCKING_THREAD_ID JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID AND l.ENGINE=w.ENGINE WHERE r.PROCESSLIST_ID=? AND b.PROCESSLIST_ID=? AND l.OBJECT_SCHEMA=DATABASE() AND l.OBJECT_NAME=?', [$ids[$deadlock ? 0 : 1], $ids[$deadlock ? 1 : 0], self::TABLE]);
                    if ($edge === []) {
                        usleep(1000);
                    }
                } while ($edge === [] && microtime(true) < $deadline);
                file_put_contents($directory.'/observed-wait.json', json_encode(['utc' => gmdate('c'), 'observerId' => DB::scalar('SELECT CONNECTION_ID()'), 'edges' => $edge], JSON_THROW_ON_ERROR));
                $this->assertNotEmpty($edge, 'Release requires an observed head unique-key wait, not elapsed time.');
                $this->assertContains('uq_supplier_product_identity_head', array_column($edge, 'index_name'));
            }
            touch($directory.'/release-'.($deadlock ? '1' : '0'));
            foreach (array_diff($children, $waited) as $pid) {
                $this->waitForSuccessfulChild($pid, $waited);
            }
            $results = array_map(fn ($i) => json_decode(file_get_contents($directory.'/result-'.$i.'.json'), true, 512, JSON_THROW_ON_ERROR), [0, 1]);
            if ($deadlock) {
                $this->assertArrayNotHasKey('failure', $results[0]);
                $this->assertSame(['40001', 1213], $results[1]['failure']);
                $this->assertCount(1, $results[1]['queries']);
                $this->assertSame(1, $results[1]['transactionLevel']);
                $this->assertSame(22, DB::table(self::TABLE)->count());
            } elseif ($distinct) {
                $this->assertSame(2, DB::table(self::TABLE)->count());
                $this->assertNotSame($results[0]['row']['id'], $results[1]['row']['id']);
                foreach ([$key, $keyB] as $index => $logicalKey) {
                    $this->assertSame($results[$index]['row'], (array) DB::table(self::TABLE)->where($logicalKey->persistenceAttributes())->sole());
                }
            } else {
                $stored = (array) DB::table(self::TABLE)->sole();
                $this->assertSame($stored, $results[1]['row']);
                if ($rollback) {
                    $this->assertNotSame($results[0]['row']['id'], $stored['id']);
                    $retry = DB::transaction(fn () => $this->participate(DB::connection(), $key));
                    $this->assertSame($stored, $retry->getRawOriginal());
                } else {
                    $this->assertSame($stored, $results[0]['row']);
                }
            }
            $this->assertSame($before, $this->protectedRows());
            file_put_contents($directory.'/parent-result.json', json_encode(['utc' => gmdate('c'), 'children' => $children, 'normalExitCodes' => [0, 0], 'rollback' => $rollback, 'deadlock' => $deadlock, 'distinct' => $distinct, 'protectedRows' => 'PASS'], JSON_THROW_ON_ERROR));
        } finally {
            $this->cleanupChildren($children, $waited, $directory);
        }
    }

    private function waitForSuccessfulChild(int $pid, array &$waited): void
    {
        $deadline = microtime(true) + 30;
        do {
            $done = pcntl_waitpid($pid, $status, WNOHANG);
            if ($done === 0) {
                usleep(1000);
            }
        } while ($done === 0 && microtime(true) < $deadline);
        $this->assertSame($pid, $done, 'Child did not reach a terminal result.');
        $waited[] = $pid;
        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
    }

    private function cleanupChildren(array $children, array $waited, string $directory): void
    {
        $pending = array_values(array_diff($children, $waited));
        $evidence = [];
        foreach ([SIGTERM, SIGKILL] as $signal) {
            foreach ($pending as $index => $pid) {
                $done = pcntl_waitpid($pid, $status, WNOHANG);
                if ($done !== 0) {
                    $evidence[] = ['pid' => $pid, 'waitResult' => $done, 'status' => $status];
                    unset($pending[$index]);

                    continue;
                }
                $evidence[] = ['pid' => $pid, 'signal' => $signal, 'sent' => posix_kill($pid, $signal)];
            }
            $deadline = microtime(true) + 2;
            while ($pending !== [] && microtime(true) < $deadline) {
                foreach ($pending as $index => $pid) {
                    $done = pcntl_waitpid($pid, $status, WNOHANG);
                    if ($done !== 0) {
                        $evidence[] = ['pid' => $pid, 'waitResult' => $done, 'status' => $status];
                        unset($pending[$index]);
                    }
                }
                if ($pending !== []) {
                    usleep(1000);
                }
            }
        }
        file_put_contents($directory.'/cleanup.json', json_encode(['utc' => gmdate('c'), 'ownedChildren' => $children, 'alreadyWaited' => $waited, 'actions' => $evidence, 'unreaped' => array_values($pending)], JSON_THROW_ON_ERROR));
        if ($pending !== []) {
            throw new RuntimeException('Owned child cleanup exceeded its TERM/KILL deadlines.');
        }
    }

    private function awaitFile(string $file): void
    {
        $deadline = microtime(true) + 30;
        while (! file_exists($file) && microtime(true) < $deadline) {
            usleep(1000);
        }
        if (! file_exists($file)) {
            throw new RuntimeException('Synthetic barrier timeout: '.basename($file));
        }
    }

    private function caseDirectory(string $name): string
    {
        $directory = (is_dir('/evidence') ? '/evidence' : sys_get_temp_dir()).'/slice-four-'.$name.'-'.bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($directory, 0700));

        return $directory;
    }

    private function recordCase(string $name, array $record): void
    {
        file_put_contents($this->caseDirectory($name).'/result.json', json_encode(['utc' => gmdate('c'), ...$record], JSON_THROW_ON_ERROR));
    }

    private function assertPrefix(string $prefix): void
    {
        $state = P0::classify(DB::connection()->getPdo());
        $this->assertSame($prefix, $state['state']);
        $this->assertSame('NORMAL_PREFIX', $state['classification']);
        $this->assertSame($prefix === 'P5' ? 309 : 294, $state['object_count']);
        $this->assertSame($prefix === 'P5' ? '8cc5afed3a68c4152bf1456a10741c5e23bd077bf4dae39cae639208872ca56f' : '1fc28641da815cd4737b50b5b8ed065918b3f416f970ba2cccef82e5342e8d57', $state['sha256']);
        Oracle::assertIntegrity();
    }

    private function schemaDefaultCollation(): string
    {
        $collation = DB::scalar('SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()');
        $this->assertIsString($collation);
        $this->assertMatchesRegularExpression('/\Autf8mb4_[a-z0-9_]+\z/', $collation);

        return $collation;
    }

    private function creationSession(): array
    {
        return (array) DB::selectOne('SELECT @@session.sql_mode AS sql_mode, @@session.character_set_client AS charset, @@session.character_set_results AS character_set_results, @@session.collation_connection AS collation_connection');
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_08_28_090004_create_supplier_product_identity_heads_table.php');
    }

    private function key(SupplierFeed $feed, string $sku = 'SKU'): Key
    {
        return Key::fromArray(['supplier_id' => $feed->supplier_id, 'supplier_feed_id' => $feed->id, 'supplier_sku_bytes' => $sku]);
    }

    private function participate(Connection $connection, Key $key): Head
    {
        return (new ReflectionMethod(Repository::class, 'insertOrLockInTransaction'))->invoke(new Repository, $connection, $key);
    }

    private function independent(string $name): Connection
    {
        config()->set('database.connections.'.$name, config('database.connections.'.DB::getDefaultConnection()));

        return DB::connection($name);
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

    private function assertFailure(string $expected, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Required rejection absent: '.$expected);
        } catch (RuntimeException $error) {
            $this->assertSame($expected, $error instanceof CanonicalSupplierPhaseThreeP0SchemaException ? $error->primaryCode : $error->getMessage());
        }
    }

    private function protectedRows(): array
    {
        $result = [];
        $tables = DB::select("SELECT TABLE_NAME AS name FROM information_schema.tables WHERE table_schema=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME");
        foreach ($tables as $table) {
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
        $this->assertTrue($user->isSuperAdmin());
        $this->assertTrue($user->canAccessPanel(filament()->getPanel('admin')));
        $this->assertTrue($user->canManageUsers());
    }

    private function sourceEvidence(SupplierFeed $feed, string $source): array
    {
        $job = ImportJob::query()->create(['supplier_id' => $feed->supplier_id, 'supplier_feed_id' => $feed->id, 'type' => 'csv', 'mode' => 'queued', 'status' => 'pending']);
        $history = ImportHistory::startForImport($job, 'Synthetic Slice 4 fixture; no import.');
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
            ResolvedSupplierImportSourceContext::fromProfile($profile), $history->id, '2026-10-12T00:00:00.123456Z',
        );
        $executionId = DB::transaction(function () use ($executionValue): int {
            $execution = (new SupplierImportSourceExecutionRepository)->resolveOrInsertWithinTransaction(DB::connection(), $executionValue);
            $receipt = CanonicalSupplierImportSourcePayloadReceipt::fromArray(['schema' => CanonicalSupplierImportSourcePayloadReceipt::VERSION, 'supplier_import_source_execution_id' => $execution->id,
                'source_execution_fingerprint' => $execution->getRawOriginal('source_execution_fingerprint'), 'accepted_payload_bytes' => 3, 'accepted_payload_sha256' => hash('sha256', 'abc')]);
            (new SupplierImportSourcePayloadReceiptRepository)->resolveOrInsertWithinTransaction(DB::connection(), $execution, $receipt);

            return $execution->id;
        });

        return ['jobId' => $job->id, 'profileId' => $id, 'executionId' => $executionId];
    }
}

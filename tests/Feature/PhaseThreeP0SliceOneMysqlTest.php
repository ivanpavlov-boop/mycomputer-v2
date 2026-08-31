<?php

namespace Tests\Feature;

use App\Contracts\Suppliers\SupplierSourceIdentityGenerator;
use App\Data\Suppliers\SourceProfiles\CanonicalSupplierImportMapping;
use App\Data\Suppliers\SourceProfiles\CanonicalSupplierSourceLocator;
use App\Data\Suppliers\SourceProfiles\CanonicalSupplierSourceProfileDescriptor;
use App\Exceptions\SupplierImportSourceProfileIdentityCollisionExhaustedException;
use App\Exceptions\SupplierImportSourceProfilePersistenceException;
use App\Models\SupplierFeed;
use App\Repositories\Suppliers\SupplierImportSourceProfileRepository;
use App\Services\Suppliers\SourceProfiles\SupplierSourceIdentityCollisionClassifier;
use Database\Migrations\Support\CanonicalSupplierPhaseThreeP0NamedLockResult;
use Database\Migrations\Support\CanonicalSupplierPhaseThreeP0Schema;
use Database\Migrations\Support\CanonicalSupplierPhaseThreeP0SchemaComparator;
use Database\Migrations\Support\CanonicalSupplierPhaseThreeP0SchemaException;
use Database\Migrations\Support\CanonicalSupplierPhaseThreeP0SchemaInspector;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;
use Throwable;

require_once __DIR__.'/../../database/migrations/support/CanonicalSupplierPhaseThreeP0Schema.php';

final class PhaseThreeP0SliceOneMysqlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Phase III-P0 Slice 1 requires disposable MySQL 8.4.');
        }

        $this->assertStringStartsWith('8.4.', (string) DB::scalar('SELECT VERSION()'));
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        putenv('SUPPLIER_PHASE_THREE_P0_EMPTY_SCHEMA_DOWN_CONFIRMED');

        if (DB::getDriverName() === 'mysql') {
            $this->purgeConnections();
            $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        }

        parent::tearDown();
    }

    public function test_mysql_84_schema_matches_p2_and_terminal_down_reaches_only_p1_then_p0(): void
    {
        $this->assertSame('P2', CanonicalSupplierPhaseThreeP0Schema::classify(DB::connection()->getPdo())['state']);

        putenv('SUPPLIER_PHASE_THREE_P0_EMPTY_SCHEMA_DOWN_CONFIRMED=true');
        $p02 = require database_path('migrations/2026_08_28_090001_create_supplier_import_source_profiles_table.php');
        $p01 = require database_path('migrations/2026_08_28_090000_add_supplier_ownership_key_to_supplier_feeds.php');

        $p02->down();
        $this->assertSame('P1', CanonicalSupplierPhaseThreeP0Schema::classify(DB::connection()->getPdo())['state']);

        try {
            $p02->down();
            $this->fail('P0-02 downgrade was accepted from its predecessor.');
        } catch (CanonicalSupplierPhaseThreeP0SchemaException $exception) {
            $this->assertSame('phase_three_p0_step_not_terminal', $exception->primaryCode);
        }

        $this->assertSame('P1', CanonicalSupplierPhaseThreeP0Schema::classify(DB::connection()->getPdo())['state']);
        $p01->down();
        $this->assertSame('P0', CanonicalSupplierPhaseThreeP0Schema::classify(DB::connection()->getPdo())['state']);

        $p01->up();
        $this->assertSame('P1', CanonicalSupplierPhaseThreeP0Schema::classify(DB::connection()->getPdo())['state']);
        $p02->up();
        $this->assertSame('P2', CanonicalSupplierPhaseThreeP0Schema::classify(DB::connection()->getPdo())['state']);
    }

    public function test_schema_inspection_preserves_raw_trigger_collation_before_canonical_attestation(): void
    {
        $inspection = (new CanonicalSupplierPhaseThreeP0SchemaInspector(
            DB::connection()->getPdo(),
        ))->inspect();
        $rawTriggerCollations = [];

        foreach ($inspection['raw_signatures'] as $signature) {
            if ($signature['type'] === 'trigger') {
                $rawTriggerCollations[] = $signature['database_collation'];
            }
        }

        $this->assertSame('utf8mb4', $inspection['schema_charset']);
        $this->assertIsString($inspection['schema_default_collation']);
        $this->assertNotSame('', $inspection['schema_default_collation']);
        $this->assertNotSame([], $rawTriggerCollations);
        $this->assertSame(
            [$inspection['schema_default_collation']],
            array_values(array_unique($rawTriggerCollations, SORT_REGULAR)),
        );
        $this->assertNotContains(
            CanonicalSupplierPhaseThreeP0SchemaComparator::ENVIRONMENT_DERIVED_DATABASE_COLLATION,
            $rawTriggerCollations,
        );
        $this->assertSame('P2', CanonicalSupplierPhaseThreeP0Schema::classify(DB::connection()->getPdo())['state']);
    }

    public function test_schema_default_drift_rejects_before_destructive_p0_ddl(): void
    {
        $pdo = DB::connection()->getPdo();
        $before = (new CanonicalSupplierPhaseThreeP0SchemaInspector($pdo))->inspect();
        $database = $before['database'];
        $originalCollation = $before['schema_default_collation'];

        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_]+\z/', $database);
        $this->assertIsString($originalCollation);
        $this->assertMatchesRegularExpression('/\Autf8mb4_[A-Za-z0-9_]+\z/', $originalCollation);

        $statement = $pdo->prepare(<<<'SQL'
            SELECT COLLATION_NAME
            FROM information_schema.COLLATIONS
            WHERE CHARACTER_SET_NAME = 'utf8mb4'
              AND COLLATION_NAME <> ?
            ORDER BY COLLATION_NAME
            LIMIT 1
            SQL);
        $statement->execute([$originalCollation]);
        $driftCollation = $statement->fetchColumn();
        $this->assertIsString($driftCollation);
        $this->assertMatchesRegularExpression('/\Autf8mb4_[A-Za-z0-9_]+\z/', $driftCollation);

        try {
            DB::unprepared(
                "ALTER DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE {$driftCollation}",
            );
            $drifted = (new CanonicalSupplierPhaseThreeP0SchemaInspector($pdo))->inspect();
            $rawTriggerCollations = [];
            foreach ($drifted['raw_signatures'] as $signature) {
                if ($signature['type'] === 'trigger') {
                    $rawTriggerCollations[] = $signature['database_collation'];
                }
            }

            $this->assertSame($driftCollation, $drifted['schema_default_collation']);
            $this->assertSame([$originalCollation], array_values(array_unique($rawTriggerCollations, SORT_REGULAR)));
            $this->assertSame(
                CanonicalSupplierPhaseThreeP0SchemaComparator::UNCLASSIFIED_STATE,
                CanonicalSupplierPhaseThreeP0Schema::classify($pdo)['state'],
            );

            putenv('SUPPLIER_PHASE_THREE_P0_EMPTY_SCHEMA_DOWN_CONFIRMED=true');
            $migration = require database_path('migrations/2026_08_28_090001_create_supplier_import_source_profiles_table.php');
            try {
                $migration->down();
                $this->fail('Collation drift authorized destructive P0 DDL.');
            } catch (CanonicalSupplierPhaseThreeP0SchemaException $exception) {
                $this->assertSame('UNCLASSIFIED_P0_SCHEMA_STATE', $exception->primaryCode);
            }

            $this->assertTrue(Schema::hasTable('supplier_import_source_profiles'));
        } finally {
            DB::unprepared(
                "ALTER DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE {$originalCollation}",
            );
        }

        $this->assertSame('P2', CanonicalSupplierPhaseThreeP0Schema::classify($pdo)['state']);
    }

    public function test_downgrade_requires_authorization_mutex_and_exact_known_state_before_any_ddl(): void
    {
        $migration = require database_path('migrations/2026_08_28_090001_create_supplier_import_source_profiles_table.php');

        try {
            $migration->down();
            $this->fail('Downgrade without destructive authorization was accepted.');
        } catch (CanonicalSupplierPhaseThreeP0SchemaException $exception) {
            $this->assertSame('phase_three_p0_downgrade_not_authorized', $exception->primaryCode);
        }
        $this->assertSame('P2', CanonicalSupplierPhaseThreeP0Schema::classify(DB::connection()->getPdo())['state']);

        putenv('SUPPLIER_PHASE_THREE_P0_EMPTY_SCHEMA_DOWN_CONFIRMED=true');
        $holder = $this->independentConnection('phase_three_p0_lock_holder')->getPdo();
        $this->assertSame(1, $holder->query(
            "SELECT GET_LOCK('mycomputer:phase-iii-p0-schema-ddl-v1', 0)",
        )->fetchColumn());

        try {
            $migration->down();
            $this->fail('Downgrade with an unavailable mutex was accepted.');
        } catch (CanonicalSupplierPhaseThreeP0SchemaException $exception) {
            $this->assertSame('phase_three_p0_schema_guard_unavailable', $exception->primaryCode);
        } finally {
            $this->assertSame(1, $holder->query(
                "SELECT RELEASE_LOCK('mycomputer:phase-iii-p0-schema-ddl-v1')",
            )->fetchColumn());
            DB::purge('phase_three_p0_lock_holder');
        }
        $this->assertSame('P2', CanonicalSupplierPhaseThreeP0Schema::classify(DB::connection()->getPdo())['state']);

        DB::statement(<<<'SQL'
            ALTER TABLE `supplier_import_source_profiles`
            ADD INDEX `ix_unexpected_phase_three_p0_test` (`supplier_id`)
            SQL);

        try {
            $migration->down();
            $this->fail('Unknown schema state executed destructive DDL.');
        } catch (CanonicalSupplierPhaseThreeP0SchemaException $exception) {
            $this->assertSame('UNCLASSIFIED_P0_SCHEMA_STATE', $exception->primaryCode);
        }

        $this->assertTrue(Schema::hasTable('supplier_import_source_profiles'));
        $this->assertSame(
            1,
            (int) DB::table('information_schema.STATISTICS')
                ->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', 'supplier_import_source_profiles')
                ->where('INDEX_NAME', 'ix_unexpected_phase_three_p0_test')
                ->count(),
        );
        DB::statement(<<<'SQL'
            ALTER TABLE `supplier_import_source_profiles`
            DROP INDEX `ix_unexpected_phase_three_p0_test`
            SQL);
        $this->assertSame('P2', CanonicalSupplierPhaseThreeP0Schema::classify(DB::connection()->getPdo())['state']);
    }

    public function test_mysql_named_lock_release_distinguishes_owner_non_owner_and_missing(): void
    {
        $holder = $this->independentConnection('phase_three_p0_release_holder')->getPdo();
        $default = DB::connection()->getPdo();
        $name = 'mycomputer:phase-iii-p0-release-test-v1';

        $this->assertSame(1, $holder->query("SELECT GET_LOCK('{$name}', 0)")->fetchColumn());
        $nonOwner = $default->query("SELECT RELEASE_LOCK('{$name}')")->fetchColumn();
        $owner = $holder->query("SELECT RELEASE_LOCK('{$name}')")->fetchColumn();
        $missing = $default->query("SELECT RELEASE_LOCK('{$name}')")->fetchColumn();

        $this->assertSame('NOT_OWNED', CanonicalSupplierPhaseThreeP0NamedLockResult::release($nonOwner));
        $this->assertSame('RELEASED', CanonicalSupplierPhaseThreeP0NamedLockResult::release($owner));
        $this->assertSame('UNAVAILABLE', CanonicalSupplierPhaseThreeP0NamedLockResult::release($missing));
        DB::purge('phase_three_p0_release_holder');
    }

    public function test_profile_is_append_only_and_nonempty_p2_cannot_be_downgraded(): void
    {
        [$feed, $descriptor] = $this->fixture('append-only');
        $profile = $this->repository(new SequenceSupplierSourceIdentityGenerator([
            hex2bin('00000000000000000000000000000001'),
        ]))->resolveOrCreate($descriptor);

        $this->assertNotNull($profile->getKey());

        try {
            DB::table('supplier_import_source_profiles')
                ->where('id', $profile->getKey())
                ->update(['importer_version' => '2']);
            $this->fail('Append-only UPDATE was accepted.');
        } catch (Throwable $exception) {
            $this->assertStringContainsString('Immutable source profile cannot be updated', $exception->getMessage());
        }

        try {
            DB::table('supplier_import_source_profiles')->where('id', $profile->getKey())->delete();
            $this->fail('Append-only DELETE was accepted.');
        } catch (Throwable $exception) {
            $this->assertStringContainsString('Immutable source profile cannot be deleted', $exception->getMessage());
        }

        putenv('SUPPLIER_PHASE_THREE_P0_EMPTY_SCHEMA_DOWN_CONFIRMED=true');
        $migration = require database_path('migrations/2026_08_28_090001_create_supplier_import_source_profiles_table.php');

        try {
            $migration->down();
            $this->fail('Nonempty P2 downgrade was accepted.');
        } catch (CanonicalSupplierPhaseThreeP0SchemaException $exception) {
            $this->assertSame('phase_three_p0_append_only_table_not_pristine', $exception->primaryCode);
        }

        $this->assertSame($feed->id, (int) DB::table('supplier_import_source_profiles')->value('supplier_feed_id'));
        $this->assertSame('P2', CanonicalSupplierPhaseThreeP0Schema::classify(DB::connection()->getPdo())['state']);
    }

    public function test_descriptor_reuse_identity_retry_and_four_collision_exhaustion_are_exact(): void
    {
        [, $first] = $this->fixture('retry');
        $identities = [
            hex2bin('10000000000000000000000000000001'),
            hex2bin('10000000000000000000000000000002'),
            hex2bin('10000000000000000000000000000003'),
            hex2bin('10000000000000000000000000000004'),
        ];
        $firstGenerator = new SequenceSupplierSourceIdentityGenerator([$identities[0]]);
        $firstProfile = $this->repository($firstGenerator)->resolveOrCreate($first);

        $reuseGenerator = new SequenceSupplierSourceIdentityGenerator([]);
        $reused = $this->repository($reuseGenerator)->resolveOrCreate($first);
        $this->assertSame($firstProfile->getKey(), $reused->getKey());
        $this->assertSame(0, $reuseGenerator->calls);

        $feed = SupplierFeed::query()->findOrFail($first->persistenceAttributes()['supplier_feed_id']);
        $second = $this->descriptor($feed, 'retry-second');
        $retryGenerator = new SequenceSupplierSourceIdentityGenerator([$identities[0], $identities[1]]);
        $secondProfile = $this->repository($retryGenerator)->resolveOrCreate($second);
        $this->assertNotSame($firstProfile->getKey(), $secondProfile->getKey());
        $this->assertSame(2, $retryGenerator->calls);

        $third = $this->descriptor($feed, 'retry-third');
        $fourth = $this->descriptor($feed, 'retry-fourth');
        $this->repository(new SequenceSupplierSourceIdentityGenerator([$identities[2]]))->resolveOrCreate($third);
        $this->repository(new SequenceSupplierSourceIdentityGenerator([$identities[3]]))->resolveOrCreate($fourth);

        $target = $this->descriptor($feed, 'retry-exhausted');
        $before = DB::table('supplier_import_source_profiles')->count();

        try {
            $this->repository(new SequenceSupplierSourceIdentityGenerator($identities))->resolveOrCreate($target);
            $this->fail('A fifth identity outcome or alias was accepted.');
        } catch (SupplierImportSourceProfileIdentityCollisionExhaustedException $exception) {
            $this->assertSame(4, $exception->metadata()['attempt']);
            $this->assertSame(4, $exception->metadata()['maximum']);
            $this->assertNull($exception->getPrevious());
        }

        $this->assertSame($before, DB::table('supplier_import_source_profiles')->count());
        $this->assertFalse(DB::table('supplier_import_source_profiles')
            ->where('source_descriptor_fingerprint', $target->fingerprint())
            ->exists());
    }

    public function test_ineligible_database_failure_rolls_back_and_crosses_only_the_sanitized_boundary(): void
    {
        [, $descriptor] = $this->fixture('ineligible-database-failure');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER `trg_test_source_profile_insert_failure`
            BEFORE INSERT ON `supplier_import_source_profiles`
            FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'sensitive driver diagnostic'
            SQL);

        try {
            $this->repository(new SequenceSupplierSourceIdentityGenerator([
                hex2bin('30000000000000000000000000000001'),
            ]))->resolveOrCreate($descriptor);
            $this->fail('Ineligible database failure was not sanitized.');
        } catch (SupplierImportSourceProfilePersistenceException $exception) {
            $this->assertSame('source_profile_persistence_failed', $exception->getMessage());
            $this->assertSame('45000', $exception->metadata()['sqlstate']);
            $this->assertSame('INSERT_SUPPLIER_IMPORT_SOURCE_PROFILE', $exception->metadata()['operation']);
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('sensitive', serialize($exception->metadata()));
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS `trg_test_source_profile_insert_failure`');
        }

        $this->assertFalse(DB::table('supplier_import_source_profiles')
            ->where('source_descriptor_fingerprint', $descriptor->fingerprint())
            ->exists());
    }

    public function test_concurrent_identical_descriptor_resolution_converges_to_one_profile(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->fail('pcntl_fork is required for Phase III-P0 MySQL concurrency validation.');
        }

        [$feed, $descriptor] = $this->fixture('concurrent');
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phase-three-p0-'.bin2hex(random_bytes(8));
        $start = $directory.DIRECTORY_SEPARATOR.'start';
        $children = [];
        $waited = [];

        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('Unable to create concurrency synchronization directory.');
        }

        $this->purgeConnections();

        try {
            foreach ([
                hex2bin('20000000000000000000000000000001'),
                hex2bin('20000000000000000000000000000002'),
            ] as $index => $identity) {
                $pid = pcntl_fork();

                if ($pid === -1) {
                    throw new RuntimeException('Unable to fork source-profile concurrency process.');
                }

                if ($pid === 0) {
                    while (! file_exists($start)) {
                        usleep(1_000);
                    }

                    try {
                        $profile = $this->repository(
                            new SequenceSupplierSourceIdentityGenerator([$identity]),
                        )->resolveOrCreate($this->descriptor($feed, 'concurrent'));
                        file_put_contents(
                            $directory.DIRECTORY_SEPARATOR."result-{$index}",
                            (string) $profile->getKey(),
                        );
                        exit(0);
                    } catch (Throwable) {
                        exit(1);
                    }
                }

                $children[] = $pid;
            }

            touch($start);

            foreach ($children as $pid) {
                $waitedPid = pcntl_waitpid($pid, $status);
                $waited[] = $pid;
                $this->assertSame($pid, $waitedPid);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }

            $this->purgeConnections();
            $ids = [
                trim((string) file_get_contents($directory.DIRECTORY_SEPARATOR.'result-0')),
                trim((string) file_get_contents($directory.DIRECTORY_SEPARATOR.'result-1')),
            ];
            $this->assertSame($ids[0], $ids[1]);
            $this->assertSame(1, DB::table('supplier_import_source_profiles')
                ->where('supplier_id', $feed->supplier_id)
                ->where('supplier_feed_id', $feed->id)
                ->where('source_descriptor_fingerprint', $descriptor->fingerprint())
                ->count());
        } finally {
            if (! file_exists($start)) {
                touch($start);
            }

            foreach (array_diff($children, $waited) as $pid) {
                pcntl_waitpid($pid, $status);
            }

            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                unlink($path);
            }

            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    /** @return array{0: SupplierFeed, 1: CanonicalSupplierSourceProfileDescriptor} */
    private function fixture(string $scope): array
    {
        $feed = SupplierFeed::factory()->create();

        return [$feed, $this->descriptor($feed, $scope)];
    }

    private function descriptor(
        SupplierFeed $feed,
        string $scope,
    ): CanonicalSupplierSourceProfileDescriptor {
        $mapping = CanonicalSupplierImportMapping::fromArray([
            'schema' => CanonicalSupplierImportMapping::VERSION,
            'feed_type' => 'xml',
            'effective_mapping' => [
                'root_path' => 'CONTENT.PRICE',
                'field_map' => ['sku' => 'WIC'],
                'validation_rules' => ['sku' => 'required'],
                'defaults' => ['currency' => 'BGN'],
            ],
        ]);
        $locator = CanonicalSupplierSourceLocator::fromArray([
            'schema' => CanonicalSupplierSourceLocator::CONTRACT,
            'source_locator_contract_key' => 'test-source-locator',
            'source_locator_contract_version' => '1',
            'scheme' => 'https',
            'ascii_host' => 'feed.example.test',
            'port' => null,
            'path_components' => [
                ['position' => 0, 'classification' => 'source', 'value' => $scope],
            ],
            'query_components' => [],
        ]);

        return CanonicalSupplierSourceProfileDescriptor::fromContracts(
            supplierId: (int) $feed->supplier_id,
            supplierFeedId: (int) $feed->id,
            locator: $locator,
            sourceAccessScopeKey: 'source-access-v1:'.$scope,
            feedType: 'xml',
            importerKey: 'xml-importer',
            importerVersion: '1',
            mapping: $mapping,
        );
    }

    private function repository(
        SupplierSourceIdentityGenerator $generator,
    ): SupplierImportSourceProfileRepository {
        return new SupplierImportSourceProfileRepository(
            app(DatabaseManager::class),
            $generator,
            new SupplierSourceIdentityCollisionClassifier,
        );
    }

    private function purgeConnections(): void
    {
        foreach (array_keys(DB::getConnections()) as $connection) {
            DB::purge($connection);
        }
    }

    private function independentConnection(string $name): Connection
    {
        $default = (string) config('database.default');
        config()->set("database.connections.{$name}", config("database.connections.{$default}"));
        DB::purge($name);

        return DB::connection($name);
    }
}

final class SequenceSupplierSourceIdentityGenerator implements SupplierSourceIdentityGenerator
{
    public int $calls = 0;

    /** @param list<string> $values */
    public function __construct(private array $values) {}

    public function bytes(): string
    {
        $this->calls++;
        $value = array_shift($this->values);

        if (! is_string($value)) {
            throw new RuntimeException('deterministic_identity_sequence_exhausted');
        }

        return $value;
    }
}

<?php

namespace Tests\Feature;

use App\Contracts\Suppliers\SupplierImportSourceDescriptorProvider;
use App\Data\Suppliers\Imports\CanonicalSupplierImportSourceExecution;
use App\Data\Suppliers\Imports\ImportJobIdentity;
use App\Data\Suppliers\Imports\ResolvedSupplierImportSourceContext;
use App\Data\Suppliers\SourceProfiles\CanonicalSupplierImportMapping;
use App\Data\Suppliers\SourceProfiles\CanonicalSupplierSourceLocator;
use App\Data\Suppliers\SourceProfiles\CanonicalSupplierSourceProfileDescriptor;
use App\Models\ImportHistory;
use App\Models\ImportJob;
use App\Models\Supplier;
use App\Models\SupplierFeed;
use App\Models\SupplierImportSourceProfile;
use App\Models\XmlMappingTemplate;
use App\Repositories\Suppliers\SupplierImportSourceExecutionRepository;
use App\Services\Suppliers\SupplierImportSourceContextResolver;
use Database\Migrations\Support\CanonicalSupplierPhaseThreeP0Schema;
use Database\Migrations\Support\CanonicalSupplierPhaseThreeP0SchemaException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;
use Throwable;

require_once __DIR__.'/../../database/migrations/support/CanonicalSupplierPhaseThreeP0Schema.php';

final class PhaseThreeP0SliceTwoMysqlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Phase III-P0 Slice 2 requires disposable MySQL 8.4.');
        }

        $this->assertStringStartsWith('8.4.', (string) DB::scalar('SELECT VERSION()'));
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->assertSame('P3', CanonicalSupplierPhaseThreeP0Schema::classify(DB::connection()->getPdo())['state']);
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

    public function test_p3_schema_is_exact_and_reverses_only_to_p2_under_fresh_authorization(): void
    {
        $classification = CanonicalSupplierPhaseThreeP0Schema::classify(DB::connection()->getPdo());
        $this->assertSame('P3', $classification['state']);
        $this->assertSame(275, $classification['object_count']);
        $this->assertSame(
            '88e8c410ea052d66c6d8a920143f11fdf3ecca47f4c6c570afcecb16fce1cf2b',
            $classification['sha256'],
        );
        $this->assertTrue(Schema::hasTable('supplier_import_source_executions'));

        Log::spy();
        $migration = require database_path('migrations/2026_08_28_090002_create_supplier_import_source_executions_table.php');

        try {
            $migration->down();
            $this->fail('Unauthorized P3 downgrade was accepted.');
        } catch (CanonicalSupplierPhaseThreeP0SchemaException $exception) {
            $this->assertSame('phase_three_p0_downgrade_not_authorized', $exception->primaryCode);
        }

        $this->assertTrue(Schema::hasTable('supplier_import_source_executions'));
        $this->assertSame('P3', CanonicalSupplierPhaseThreeP0Schema::classify(DB::connection()->getPdo())['state']);
        Log::assertLogged('info', fn (string $message, array $context): bool => $message === 'phase_three_p0_downgrade_evidence_v1'
            && $context['primary_outcome'] === 'phase_three_p0_downgrade_not_authorized'
            && $context['completed_ddl_ids'] === []
        );

        putenv('SUPPLIER_PHASE_THREE_P0_EMPTY_SCHEMA_DOWN_CONFIRMED=true');
        $migration->down();
        putenv('SUPPLIER_PHASE_THREE_P0_EMPTY_SCHEMA_DOWN_CONFIRMED');
        $this->assertSame('P2', CanonicalSupplierPhaseThreeP0Schema::classify(DB::connection()->getPdo())['state']);

        $supplier = Supplier::factory()->create();
        $supplierCount = DB::table('suppliers')->count();
        $migration->up();
        $this->assertSame('P3', CanonicalSupplierPhaseThreeP0Schema::classify(DB::connection()->getPdo())['state']);
        $this->assertSame($supplierCount, DB::table('suppliers')->count());
        $this->assertTrue(DB::table('suppliers')->where('id', $supplier->id)->exists());

        try {
            $migration->down();
            $this->fail('A prior successful authorization leaked into another invocation.');
        } catch (CanonicalSupplierPhaseThreeP0SchemaException $exception) {
            $this->assertSame('phase_three_p0_downgrade_not_authorized', $exception->primaryCode);
        }

        $this->assertSame('P3', CanonicalSupplierPhaseThreeP0Schema::classify(DB::connection()->getPdo())['state']);

        try {
            $migration->up();
            $this->fail('P0-03 repeated outside its P2 predecessor was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('phase_three_p0_forward_precondition_failed', $exception->getMessage());
        }
    }

    public function test_malformed_p3_is_rejected_before_destructive_ddl(): void
    {
        DB::unprepared('DROP TRIGGER `trg_import_source_execution_no_delete`');
        $this->assertSame(
            'UNCLASSIFIED_P0_SCHEMA_STATE',
            CanonicalSupplierPhaseThreeP0Schema::classify(DB::connection()->getPdo())['state'],
        );

        putenv('SUPPLIER_PHASE_THREE_P0_EMPTY_SCHEMA_DOWN_CONFIRMED=true');
        $migration = require database_path('migrations/2026_08_28_090002_create_supplier_import_source_executions_table.php');

        try {
            $migration->down();
            $this->fail('Malformed P3 authorized destructive DDL.');
        } catch (CanonicalSupplierPhaseThreeP0SchemaException $exception) {
            $this->assertSame('UNCLASSIFIED_P0_SCHEMA_STATE', $exception->primaryCode);
        }

        $this->assertTrue(Schema::hasTable('supplier_import_source_executions'));
    }

    public function test_xml_resolution_inserts_once_retries_from_persisted_authority_and_is_append_only(): void
    {
        $fixture = $this->fixture('xml');
        $productsBefore = DB::table('products')->count();
        $supplierProductsBefore = DB::table('supplier_products')->count();
        $profileBefore = (array) DB::table('supplier_import_source_profiles')->find($fixture['profile_id']);
        $resolver = $this->resolver($fixture['provider']);
        $firstResolutionQueries = [];
        DB::listen(function ($query) use (&$firstResolutionQueries): void {
            $firstResolutionQueries[] = $query->sql;
        });

        $first = $resolver->resolveSourceContext($fixture['job']->id, $fixture['claim_id']);
        $lockingQueries = collect($firstResolutionQueries)
            ->filter(fn (string $sql): bool => str_contains(strtolower($sql), 'for update'))
            ->values();
        $jobLock = $lockingQueries->search(
            fn (string $sql): bool => str_contains($sql, '`import_jobs`'),
        );
        $feedLock = $lockingQueries->search(
            fn (string $sql): bool => str_contains($sql, '`supplier_feeds`'),
        );
        $templateLock = $lockingQueries->search(
            fn (string $sql): bool => str_contains($sql, '`xml_mapping_templates`'),
        );
        $this->assertNotFalse($jobLock);
        $this->assertNotFalse($feedLock);
        $this->assertNotFalse($templateLock);
        $this->assertLessThan($feedLock, $jobLock);
        $this->assertLessThan($templateLock, $feedLock);

        $fixture['job']->update([
            'xml_mapping_template_id' => null,
            'type' => 'csv',
        ]);
        $fixture['feed']->update([
            'feed_type' => 'csv',
            'mapping' => ['supplier_sku' => 'changed.code'],
            'status' => 'inactive',
        ]);
        $fixture['template']->update([
            'field_map' => ['supplier_sku' => 'changed.code'],
            'is_active' => false,
        ]);
        $retryQueries = [];
        DB::listen(function ($query) use (&$retryQueries): void {
            $retryQueries[] = $query->sql;
        });
        $second = $resolver->resolveSourceContext($fixture['job']->id, $fixture['claim_id']);

        $this->assertSame($first->canonicalBytes(), $second->canonicalBytes());
        $this->assertSame(1, $fixture['provider']->calls);
        $this->assertFalse(collect($retryQueries)->contains(
            fn (string $sql): bool => str_contains($sql, '`supplier_feeds`')
                || str_contains($sql, '`xml_mapping_templates`')
                || (str_contains($sql, '`import_jobs`')
                    && (str_contains($sql, '`xml_mapping_template_id`')
                        || str_contains($sql, '`type`'))),
        ));
        $this->assertSame(1, DB::table('supplier_import_source_executions')->count());
        $this->assertSame($profileBefore, (array) DB::table('supplier_import_source_profiles')->find($fixture['profile_id']));
        $this->assertSame($productsBefore, DB::table('products')->count());
        $this->assertSame($supplierProductsBefore, DB::table('supplier_products')->count());

        $execution = DB::table('supplier_import_source_executions')->first();
        $this->assertNotNull($execution);
        $identity = ImportJobIdentity::fromCanonicalBytes(
            $execution->import_job_identity_canonical_bytes,
            $execution->import_job_identity_fingerprint,
        );
        $this->assertSame($fixture['job']->id, $identity->importJobId());
        $this->assertSame($fixture['profile_id'], (int) $execution->supplier_import_source_profile_id);

        $invalidParent = (array) $execution;
        unset($invalidParent['id']);
        $invalidParent['import_job_id'] = 9_000_000_001;
        $invalidParent['import_history_id'] = 9_000_000_002;
        $invalidParent['import_job_identity_fingerprint'] = str_repeat('d', 64);
        $invalidParent['source_execution_fingerprint'] = str_repeat('e', 64);

        try {
            DB::table('supplier_import_source_executions')->insert($invalidParent);
            $this->fail('Invalid execution parents were accepted.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', $exception->errorInfo[0] ?? null);
            $this->assertSame(1452, (int) ($exception->errorInfo[1] ?? 0));
        }

        $this->assertSame(1, DB::table('supplier_import_source_executions')->count());

        foreach (['update', 'delete'] as $operation) {
            try {
                $query = DB::table('supplier_import_source_executions')->where('id', $execution->id);
                $operation === 'update'
                    ? $query->update(['importer_version' => '2'])
                    : $query->delete();
                $this->fail("Direct {$operation} bypass was accepted.");
            } catch (QueryException $exception) {
                $this->assertSame('45000', $exception->errorInfo[0] ?? null);
                $this->assertSame(1644, (int) ($exception->errorInfo[1] ?? 0));
            }
        }

        $context = ResolvedSupplierImportSourceContext::fromProfile(
            SupplierImportSourceProfile::query()->findOrFail($fixture['profile_id']),
        );
        $conflict = CanonicalSupplierImportSourceExecution::fromContracts(
            $identity,
            $context,
            $fixture['history']->id,
            '2026-08-28T09:10:11.123456Z',
        );

        try {
            DB::connection()->transaction(
                fn () => (new SupplierImportSourceExecutionRepository)->resolveOrInsertWithinTransaction(
                    DB::connection(),
                    $conflict,
                ),
            );
            $this->fail('A conflicting retry overwrote immutable execution authority.');
        } catch (RuntimeException $exception) {
            $this->assertSame('source_execution_fingerprint_collision', $exception->getMessage());
        }

        putenv('SUPPLIER_PHASE_THREE_P0_EMPTY_SCHEMA_DOWN_CONFIRMED=true');
        $migration = require database_path('migrations/2026_08_28_090002_create_supplier_import_source_executions_table.php');
        try {
            $migration->down();
            $this->fail('Non-pristine append-only P3 table was downgraded.');
        } catch (CanonicalSupplierPhaseThreeP0SchemaException $exception) {
            $this->assertSame('phase_three_p0_append_only_table_not_pristine', $exception->primaryCode);
        } finally {
            putenv('SUPPLIER_PHASE_THREE_P0_EMPTY_SCHEMA_DOWN_CONFIRMED');
        }
    }

    public function test_csv_resolution_uses_locked_feed_mapping_and_null_template_selector(): void
    {
        $fixture = $this->fixture('csv');
        $context = $this->resolver($fixture['provider'])
            ->resolveSourceContext($fixture['job']->id, $fixture['claim_id']);

        $this->assertSame('csv', $context->toCanonicalArray()['feed_type']);
        $this->assertSame('csv-importer', $context->importerKey());
        $this->assertSame(1, DB::table('supplier_import_source_executions')->count());

        $execution = DB::table('supplier_import_source_executions')->first();
        $identity = ImportJobIdentity::fromCanonicalBytes(
            $execution->import_job_identity_canonical_bytes,
            $execution->import_job_identity_fingerprint,
        );
        $this->assertNull($identity->toCanonicalArray()['xml_mapping_template_id']);
        $this->assertSame('csv', $identity->importType());
    }

    public function test_resolver_fails_closed_without_creating_or_mutating_profiles(): void
    {
        $missing = $this->fixture('xml', persistProfile: false);
        $profileCount = DB::table('supplier_import_source_profiles')->count();

        try {
            $this->resolver($missing['provider'])
                ->resolveSourceContext($missing['job']->id, $missing['claim_id']);
            $this->fail('Missing profile was created implicitly.');
        } catch (RuntimeException $exception) {
            $this->assertSame('source_context_profile_not_found', $exception->getMessage());
        }

        $this->assertSame($profileCount, DB::table('supplier_import_source_profiles')->count());
        $this->assertSame(0, DB::table('supplier_import_source_executions')->count());

        $inactive = $this->fixture('xml');
        $inactive['feed']->update(['status' => 'inactive']);
        try {
            $this->resolver($inactive['provider'])
                ->resolveSourceContext($inactive['job']->id, $inactive['claim_id']);
            $this->fail('Inactive feed was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('source_context_feed_selector_mismatch', $exception->getMessage());
        }

        $inactiveTemplate = $this->fixture('xml');
        $inactiveTemplate['template']->update(['is_active' => false]);
        try {
            $this->resolver($inactiveTemplate['provider'])
                ->resolveSourceContext($inactiveTemplate['job']->id, $inactiveTemplate['claim_id']);
            $this->fail('Inactive template was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('source_context_xml_template_mismatch', $exception->getMessage());
        }

        $other = $this->fixture('xml');
        try {
            $this->resolver($other['provider'])
                ->resolveSourceContext($other['job']->id, $missing['claim_id']);
            $this->fail('A claim from another job was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('source_context_claim_mismatch', $exception->getMessage());
        }

        $contradictory = $this->fixture('xml', persistProfile: false);
        $attributes = $contradictory['provider']->descriptorFor(
            $contradictory['feed'],
            $this->xmlMapping($contradictory['template']),
        )->persistenceAttributes();
        $attributes['mapping_canonical_bytes'] = str_replace(
            '"currency":"EUR"',
            '"currency":"USD"',
            $attributes['mapping_canonical_bytes'],
        );
        DB::table('supplier_import_source_profiles')->insert([
            ...$attributes,
            'source_identity' => 'snapshot-source-v1:profile:'.sprintf('%032x', $contradictory['feed']->id),
            'created_at' => now('UTC')->format('Y-m-d H:i:s.u'),
        ]);

        try {
            $this->resolver($contradictory['provider'])
                ->resolveSourceContext($contradictory['job']->id, $contradictory['claim_id']);
            $this->fail('Contradictory persisted profile bytes were accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('noncanonical_mapping_contract_bytes', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('supplier_import_source_executions')->count());
    }

    public function test_selector_change_cannot_reinterpret_a_preexisting_profile_or_create_b(): void
    {
        $fixture = $this->fixture('xml');
        $profilesBefore = DB::table('supplier_import_source_profiles')->count();
        $fixture['template']->update([
            'field_map' => ['supplier_sku' => 'changed.code', 'name' => 'changed.name'],
        ]);

        try {
            $this->resolver($fixture['provider'])
                ->resolveSourceContext($fixture['job']->id, $fixture['claim_id']);
            $this->fail('Selector B reused profile A.');
        } catch (RuntimeException $exception) {
            $this->assertSame('source_context_profile_not_found', $exception->getMessage());
        }

        $this->assertSame($profilesBefore, DB::table('supplier_import_source_profiles')->count());
        $this->assertSame(0, DB::table('supplier_import_source_executions')->count());
    }

    public function test_concurrent_resolution_converges_to_one_source_execution(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->fail('pcntl_fork is required for Phase III-P0 Slice 2 MySQL concurrency validation.');
        }

        $fixture = $this->fixture('xml');
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phase-three-p0-slice-two-'.bin2hex(random_bytes(8));
        $start = $directory.DIRECTORY_SEPARATOR.'start';
        $children = [];
        $waited = [];

        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('Unable to create concurrency synchronization directory.');
        }

        $this->purgeConnections();

        try {
            foreach ([0, 1] as $index) {
                $pid = pcntl_fork();

                if ($pid === -1) {
                    throw new RuntimeException('Unable to fork source-execution concurrency process.');
                }

                if ($pid === 0) {
                    while (! file_exists($start)) {
                        usleep(1_000);
                    }

                    try {
                        $context = $this->resolver($fixture['provider'])
                            ->resolveSourceContext($fixture['job']->id, $fixture['claim_id']);
                        file_put_contents(
                            $directory.DIRECTORY_SEPARATOR."result-{$index}",
                            $context->fingerprint(),
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
            $results = [
                trim((string) file_get_contents($directory.DIRECTORY_SEPARATOR.'result-0')),
                trim((string) file_get_contents($directory.DIRECTORY_SEPARATOR.'result-1')),
            ];
            $this->assertSame($results[0], $results[1]);
            $this->assertSame(1, DB::table('supplier_import_source_executions')->count());
            $this->assertSame(0, DB::table('products')->count());
            $this->assertSame(0, DB::table('supplier_products')->count());
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

    /**
     * @return array{
     *   feed: SupplierFeed,
     *   template: ?XmlMappingTemplate,
     *   job: ImportJob,
     *   history: ImportHistory,
     *   claim_id: int,
     *   profile_id: ?int,
     *   provider: FixedSupplierImportSourceDescriptorProvider
     * }
     */
    private function fixture(string $type, bool $persistProfile = true): array
    {
        $supplier = Supplier::factory()->create();
        $feed = SupplierFeed::factory()->create([
            'supplier_id' => $supplier->id,
            'feed_type' => $type,
            'mapping' => [
                'supplier_sku' => 'code',
                'name' => 'name',
                'price' => 'price',
                'quantity' => 'stock',
            ],
            'status' => 'active',
        ]);
        $template = $type === 'xml'
            ? XmlMappingTemplate::factory()->create(['supplier_id' => $supplier->id, 'is_active' => true])
            : null;
        $job = ImportJob::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_feed_id' => $feed->id,
            'xml_mapping_template_id' => $template?->id,
            'type' => $type,
            'mode' => 'queued',
            'status' => 'pending',
        ]);
        $history = ImportHistory::startForImport($job, 'Phase III-P0 Slice 2 isolated verification.');
        $claimId = (int) DB::table('supplier_import_execution_claims')->insertGetId([
            'logical_execution_key' => hash('sha256', "slice-two\0{$job->id}"),
            'supplier_id' => $supplier->id,
            'supplier_feed_id' => $feed->id,
            'supplier_import_run_id' => null,
            'import_job_id' => $job->id,
            'allocated_at' => now('UTC')->format('Y-m-d H:i:s.u'),
            'import_history_id' => $history->id,
            'execution_path' => 'legacy_xml',
            'state' => 'queued',
        ]);
        $provider = new FixedSupplierImportSourceDescriptorProvider(
            locator: $this->locator((int) $feed->id),
            accessScopeKey: 'source-access-v1:test.feed.'.$feed->id,
            importerKey: $type.'-importer',
        );
        $mapping = $type === 'xml'
            ? $this->xmlMapping($template)
            : $this->csvMapping($feed);
        $descriptor = $provider->descriptorFor($feed, $mapping);
        $provider->calls = 0;
        $profileId = null;

        if ($persistProfile) {
            $profileId = (int) DB::table('supplier_import_source_profiles')->insertGetId([
                ...$descriptor->persistenceAttributes(),
                'source_identity' => 'snapshot-source-v1:profile:'.sprintf('%032x', $feed->id),
                'created_at' => now('UTC')->format('Y-m-d H:i:s.u'),
            ]);
        }

        return compact('feed', 'template', 'job', 'history', 'provider') + [
            'claim_id' => $claimId,
            'profile_id' => $profileId,
        ];
    }

    private function resolver(FixedSupplierImportSourceDescriptorProvider $provider): SupplierImportSourceContextResolver
    {
        return new SupplierImportSourceContextResolver(
            app(DatabaseManager::class),
            $provider,
            new SupplierImportSourceExecutionRepository,
        );
    }

    private function xmlMapping(XmlMappingTemplate $template): CanonicalSupplierImportMapping
    {
        return CanonicalSupplierImportMapping::fromArray([
            'schema' => CanonicalSupplierImportMapping::VERSION,
            'feed_type' => 'xml',
            'effective_mapping' => [
                'root_path' => $template->root_path,
                'field_map' => $template->field_map,
                'validation_rules' => $template->validation_rules,
                'defaults' => $template->defaults,
            ],
        ]);
    }

    private function csvMapping(SupplierFeed $feed): CanonicalSupplierImportMapping
    {
        return CanonicalSupplierImportMapping::fromArray([
            'schema' => CanonicalSupplierImportMapping::VERSION,
            'feed_type' => 'csv',
            'effective_mapping' => $feed->mapping,
        ]);
    }

    private function locator(int $feedId): CanonicalSupplierSourceLocator
    {
        return CanonicalSupplierSourceLocator::fromArray([
            'schema' => CanonicalSupplierSourceLocator::CONTRACT,
            'source_locator_contract_key' => 'test-source-locator',
            'source_locator_contract_version' => '1',
            'scheme' => 'https',
            'ascii_host' => 'feed.example.test',
            'port' => null,
            'path_components' => [
                ['position' => 0, 'classification' => 'source', 'value' => 'feed-'.$feedId],
            ],
            'query_components' => [],
        ]);
    }

    private function purgeConnections(): void
    {
        foreach (array_keys(DB::getConnections()) as $connection) {
            DB::purge($connection);
        }
    }
}

final class FixedSupplierImportSourceDescriptorProvider implements SupplierImportSourceDescriptorProvider
{
    public int $calls = 0;

    public function __construct(
        private readonly CanonicalSupplierSourceLocator $locator,
        private readonly string $accessScopeKey,
        private readonly string $importerKey,
    ) {}

    public function descriptorFor(
        SupplierFeed $lockedFeed,
        CanonicalSupplierImportMapping $mapping,
    ): CanonicalSupplierSourceProfileDescriptor {
        $this->calls++;

        return CanonicalSupplierSourceProfileDescriptor::fromContracts(
            supplierId: (int) $lockedFeed->supplier_id,
            supplierFeedId: (int) $lockedFeed->id,
            locator: $this->locator,
            sourceAccessScopeKey: $this->accessScopeKey,
            feedType: $mapping->feedType(),
            importerKey: $this->importerKey,
            importerVersion: '1',
            mapping: $mapping,
        );
    }
}

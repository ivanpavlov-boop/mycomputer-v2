<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use JsonException;
use Tests\TestCase;

class LocalSupplierSourceProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_registered_local_and_read_only_without_unsafe_options(): void
    {
        $command = Artisan::all()['suppliers:profile-local-source'];
        $definition = $command->getDefinition();

        $this->assertStringContainsString('Read-only', (string) $command->getDescription());
        $this->assertStringContainsString('local', (string) $command->getDescription());

        foreach (['supplier', 'source', 'source-format', 'record-path', 'expected-sha256', 'full-file', 'output', 'summary-only', 'sample-limit', 'issue-sample-limit'] as $option) {
            $this->assertTrue($definition->hasOption($option));
        }

        foreach (['apply', 'fix', 'repair', 'unlink', 'link', 'import', 'sync', 'sync-all', 'create', 'update', 'delete', 'fetch', 'schedule', 'enable', 'disable', 'dispatch', 'queue', 'download', 'confirm-'] as $option) {
            $this->assertFalse($definition->hasOption($option));
        }
    }

    /** @throws JsonException */
    public function test_valid_fixture_is_stream_profiled_and_draft_requires_human_review(): void
    {
        Http::fake();
        $fixture = base_path('tests/Fixtures/Suppliers/apcom_legacy/legacy.xml');
        $hash = hash_file('sha256', $fixture);
        $before = $this->tableCounts();

        $this->assertSame(0, Artisan::call('suppliers:profile-local-source', [
            '--supplier' => 'apcom',
            '--source' => $fixture,
            '--source-format' => 'xml',
            '--expected-sha256' => $hash,
            '--full-file' => true,
            '--output' => 'json',
            '--sample-limit' => 1,
        ]));
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('supplier-source-profile-v1', $payload['schema_version']);
        $this->assertSame('local_source_profile', $payload['mode']);
        $this->assertTrue($payload['read_only']);
        $this->assertSame($hash, $payload['source_fingerprint']['sha256']);
        $this->assertTrue($payload['source_fingerprint']['matches']);
        $this->assertSame('products', $payload['parser_result']['root_element']);
        $this->assertSame('products.product', $payload['parser_result']['selected_record_path']);
        $this->assertSame(4, $payload['parser_result']['total_record_count']);
        $this->assertTrue($payload['parser_result']['full_file_parse_completed']);
        $this->assertSame('sku', $payload['likely_field_roles']['sku']['path']);
        $this->assertSame('ean', $payload['likely_field_roles']['ean']['path']);
        $this->assertSame('price', $payload['likely_field_roles']['price']['path']);
        $this->assertSame('currency', $payload['likely_field_roles']['currency']['path']);
        $this->assertSame('quantity', $payload['likely_field_roles']['quantity']['path']);
        $this->assertSame('availability', $payload['likely_field_roles']['availability']['path']);
        $this->assertTrue($payload['feed_profile_draft']['requires_human_review']);
        $this->assertSame(0, array_sum($payload['records_changed']));
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('example.invalid', $encoded);
        $this->assertStringNotContainsString('AP-001', $encoded);
        $this->assertSame($before, $this->tableCounts());
        Http::assertNothingSent();
    }

    public function test_explicit_record_path_and_expected_hash_mismatch_are_safe(): void
    {
        $fixture = base_path('tests/Fixtures/Suppliers/apcom_legacy/legacy.xml');

        $this->assertSame(0, Artisan::call('suppliers:profile-local-source', [
            '--supplier' => 'apcom',
            '--source' => $fixture,
            '--record-path' => 'products/product',
            '--output' => 'json',
        ]));
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('explicit_option', $payload['record_path_analysis']['selection_reason']);

        $this->assertSame(1, Artisan::call('suppliers:profile-local-source', [
            '--supplier' => 'apcom',
            '--source' => $fixture,
            '--expected-sha256' => str_repeat('a', 64),
            '--output' => 'json',
        ]));
        $mismatch = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('source_fingerprint_mismatch', $mismatch['verdict']);
        $this->assertContains('source_fingerprint_mismatch', $mismatch['blockers']);
    }

    public function test_remote_stream_missing_directory_and_malformed_sources_are_rejected(): void
    {
        foreach ([
            'https://example.invalid/apcom.xml',
            'ftp://example.invalid/apcom.xml',
            'php://memory',
            base_path('tests/Fixtures/Suppliers/apcom_legacy'),
            base_path('tests/Fixtures/Suppliers/apcom_legacy/malformed.xml'),
        ] as $source) {
            $this->assertSame(1, Artisan::call('suppliers:profile-local-source', [
                '--supplier' => 'apcom',
                '--source' => $source,
                '--output' => 'json',
            ]));
            $output = Artisan::output();
            $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('invalid_local_source', $payload['verdict']);
            $this->assertSame(0, array_sum($payload['records_changed']));
            $this->assertStringNotContainsString($source, $output);
        }
    }

    public function test_unsafe_global_catalog_sync_configuration_blocks_local_profile(): void
    {
        config(['catalog_sync.auto_enabled' => true]);

        $this->assertSame(1, Artisan::call('suppliers:profile-local-source', [
            '--supplier' => 'apcom',
            '--source' => base_path('tests/Fixtures/Suppliers/apcom_legacy/legacy.xml'),
            '--output' => 'json',
        ]));
        $output = Artisan::output();
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('unsafe_configuration', $payload['verdict']);
        $this->assertContains('unsafe_configuration', $payload['blockers']);
    }

    /** @return array<string, int> */
    private function tableCounts(): array
    {
        return collect(['suppliers', 'supplier_products', 'products', 'categories', 'catalog_sync_batches', 'catalog_sync_logs'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])
            ->put('catalog_sync', 0)
            ->all();
    }
}

<?php

namespace Tests\Feature;

use App\Jobs\ProcessSupplierImportRunJob;
use App\Jobs\ProcessXmlSupplierFeed;
use App\Jobs\RunSupplierImportJob;
use App\Models\AttributeValue;
use App\Models\CanonicalProductFamily;
use App\Models\Category;
use App\Models\CategoryProductAttribute;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\Supplier;
use App\Models\SupplierCategoryMapping;
use App\Models\SupplierFeed;
use App\Models\SupplierImportRun;
use App\Models\SupplierProduct;
use App\Models\XmlMappingTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use JsonException;
use Tests\TestCase;

class SupplierImportCapabilityAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_registered_without_apply_option(): void
    {
        $commands = Artisan::all();

        $this->assertArrayHasKey('suppliers:audit-import-capabilities', $commands);
        $this->assertFalse($commands['suppliers:audit-import-capabilities']->getDefinition()->hasOption('apply'));
        $this->assertTrue($commands['suppliers:audit-import-capabilities']->getDefinition()->hasOption('supplier'));
        $this->assertTrue($commands['suppliers:audit-import-capabilities']->getDefinition()->hasOption('limit'));
        $this->assertTrue($commands['suppliers:audit-import-capabilities']->getDefinition()->hasOption('format'));
        $this->assertTrue($commands['suppliers:audit-import-capabilities']->getDefinition()->hasOption('include-disabled'));
        $this->assertTrue($commands['suppliers:audit-import-capabilities']->getDefinition()->hasOption('only-with-issues'));
        $this->assertTrue($commands['suppliers:audit-import-capabilities']->getDefinition()->hasOption('show-drivers'));
        $this->assertTrue($commands['suppliers:audit-import-capabilities']->getDefinition()->hasOption('show-schedules'));
        $this->assertTrue($commands['suppliers:audit-import-capabilities']->getDefinition()->hasOption('show-config'));
        $this->assertTrue($commands['suppliers:audit-import-capabilities']->getDefinition()->hasOption('show-checklist'));
    }

    public function test_default_audit_is_read_only_and_does_not_dispatch_import_jobs(): void
    {
        Bus::fake();

        $supplier = $this->readySupplier('APCOM');
        $this->supplierProduct($supplier, [
            'supplier_sku' => 'APC-LAP-001',
            'ean' => '1111111111111',
            'mpn' => 'LAP-001',
            'brand_name' => 'Lenovo',
            'category_name' => 'Laptops',
            'price' => 1200,
            'quantity' => 5,
        ]);

        $counts = $this->protectedCounts();

        $this->assertSame(0, Artisan::call('suppliers:audit-import-capabilities'));
        $output = Artisan::output();

        $this->assertStringContainsString('Supplier import capability audit', $output);
        $this->assertStringContainsString('Read-only. No imports, feed fetches, queue jobs, Catalog Sync, or catalog writes were run.', $output);
        $this->assertStringContainsString('APCOM', $output);
        $this->assertStringContainsString('ready_for_staging_import', $output);
        $this->assertStringContainsString('products changed: 0', $output);
        $this->assertStringContainsString('supplier_products changed: 0', $output);
        $this->assertStringContainsString('suppliers changed: 0', $output);
        $this->assertStringContainsString('canonical_product_families changed: 0', $output);
        $this->assertSame($counts, $this->protectedCounts());
        Bus::assertNotDispatched(RunSupplierImportJob::class);
        Bus::assertNotDispatched(ProcessXmlSupplierFeed::class);
        Bus::assertNotDispatched(ProcessSupplierImportRunJob::class);
    }

    /**
     * @throws JsonException
     */
    public function test_json_reports_supplier_capability_driver_schedule_and_staging_summary(): void
    {
        $supplier = $this->readySupplier('APCOM');
        SupplierImportRun::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_feed_id' => $supplier->feeds()->first()->id,
            'trigger_type' => 'manual',
            'import_type' => 'xml',
            'status' => 'completed',
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(8),
            'products_seen' => 1,
        ]);
        $this->supplierProduct($supplier, [
            'supplier_sku' => 'APC-LAP-001',
            'ean' => '1111111111111',
            'mpn' => 'LAP-001',
            'brand_name' => 'Lenovo',
            'category_name' => 'Laptops',
        ]);

        $payload = $this->commandJson('suppliers:audit-import-capabilities', [
            '--format' => 'json',
            '--show-drivers' => true,
            '--show-schedules' => true,
            '--show-config' => true,
            '--show-checklist' => true,
        ]);

        $this->assertSame(1, $payload['summary']['suppliers_checked']);
        $this->assertSame(1, $payload['summary']['staged_supplier_products']);
        $this->assertSame(1, $payload['summary']['suppliers_with_schedule_enabled']);
        $this->assertSame('APCOM', $payload['suppliers'][0]['supplier_name']);
        $this->assertSame('xml', $payload['suppliers'][0]['feed_type']);
        $this->assertSame('configured', $payload['suppliers'][0]['driver_status']);
        $this->assertTrue($payload['suppliers'][0]['can_run_manual_staging_import']);
        $this->assertTrue($payload['suppliers'][0]['schedule_due_now']);
        $this->assertSame('ready_for_staging_import', $payload['suppliers'][0]['readiness_status']);
        $this->assertSame(1, $payload['suppliers'][0]['identifier_completeness']['supplier_sku']);
        $this->assertNotEmpty($payload['drivers']);
        $this->assertNotEmpty($payload['schedules']);
        $this->assertNotEmpty($payload['config']);
        $this->assertNotEmpty($payload['checklist']);
    }

    /**
     * @throws JsonException
     */
    public function test_supplier_filter_include_disabled_and_only_with_issues_are_supported(): void
    {
        $ready = $this->readySupplier('APCOM');
        $this->supplierProduct($ready);
        Supplier::factory()->create([
            'company_name' => 'Disabled Supplier',
            'slug' => 'disabled-supplier',
            'status' => 'inactive',
            'import_enabled' => false,
        ]);

        $default = $this->commandJson('suppliers:audit-import-capabilities', [
            '--format' => 'json',
        ]);
        $this->assertCount(1, $default['suppliers']);
        $this->assertSame('APCOM', $default['suppliers'][0]['supplier_name']);

        $filtered = $this->commandJson('suppliers:audit-import-capabilities', [
            '--format' => 'json',
            '--supplier' => 'apcom',
        ]);
        $this->assertCount(1, $filtered['suppliers']);
        $this->assertSame('APCOM', $filtered['suppliers'][0]['supplier_name']);

        $withDisabled = $this->commandJson('suppliers:audit-import-capabilities', [
            '--format' => 'json',
            '--include-disabled' => true,
        ]);
        $this->assertSame(2, $withDisabled['summary']['suppliers_checked']);

        $issuesOnly = $this->commandJson('suppliers:audit-import-capabilities', [
            '--format' => 'json',
            '--include-disabled' => true,
            '--only-with-issues' => true,
        ]);
        $this->assertCount(1, $issuesOnly['suppliers']);
        $this->assertSame('Disabled Supplier', $issuesOnly['suppliers'][0]['supplier_name']);
        $this->assertContains('disabled_supplier', $issuesOnly['suppliers'][0]['issues']);
        $this->assertContains('missing_feed_url', $issuesOnly['suppliers'][0]['issues']);
    }

    /**
     * @throws JsonException
     */
    public function test_redacts_feed_secrets_from_table_and_json_output(): void
    {
        $supplier = Supplier::factory()->create([
            'company_name' => 'Secret Feed Supplier',
            'slug' => 'secret-feed-supplier',
            'schedule_enabled' => true,
            'schedule_type' => 'daily',
        ]);
        SupplierFeed::factory()->create([
            'supplier_id' => $supplier->id,
            'feed_type' => 'xml',
            'feed_url' => 'https://feeds.example.test/export/index/type/xml/id/123/secret/VERYSECRET?token=RAW_TOKEN&api_key=RAW_KEY',
            'username' => 'feed-user',
            'password' => 'PLAIN_PASSWORD',
            'mapping' => [
                'headers' => [
                    'Authorization' => 'Bearer HEADER_TOKEN',
                ],
            ],
        ]);
        XmlMappingTemplate::factory()->create(['supplier_id' => $supplier->id]);
        $this->supplierProduct($supplier);

        $this->assertSame(0, Artisan::call('suppliers:audit-import-capabilities', [
            '--show-config' => true,
        ]));
        $output = Artisan::output();

        $this->assertStringContainsString('feeds.example.test', $output);
        $this->assertStringContainsString('REDACTED', $output);
        $this->assertStringNotContainsString('VERYSECRET', $output);
        $this->assertStringNotContainsString('RAW_TOKEN', $output);
        $this->assertStringNotContainsString('RAW_KEY', $output);
        $this->assertStringNotContainsString('PLAIN_PASSWORD', $output);
        $this->assertStringNotContainsString('HEADER_TOKEN', $output);

        $payload = $this->commandJson('suppliers:audit-import-capabilities', [
            '--format' => 'json',
            '--show-config' => true,
        ]);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('feeds.example.test', $encoded);
        $this->assertStringContainsString('REDACTED', $encoded);
        $this->assertStringNotContainsString('VERYSECRET', $encoded);
        $this->assertStringNotContainsString('RAW_TOKEN', $encoded);
        $this->assertStringNotContainsString('RAW_KEY', $encoded);
        $this->assertStringNotContainsString('PLAIN_PASSWORD', $encoded);
        $this->assertStringNotContainsString('HEADER_TOKEN', $encoded);
        $this->assertTrue($payload['suppliers'][0]['auth']['has_password']);
        $this->assertTrue($payload['suppliers'][0]['auth']['has_token']);
        $this->assertTrue($payload['suppliers'][0]['auth']['has_secret']);
        $this->assertTrue($payload['suppliers'][0]['auth']['has_api_key']);
        $this->assertTrue($payload['suppliers'][0]['auth']['has_headers']);
    }

    /**
     * @throws JsonException
     */
    public function test_json_reports_all_zero_protected_record_changes_and_sync_flags_remain_locked(): void
    {
        $payload = $this->commandJson('suppliers:audit-import-capabilities', [
            '--format' => 'json',
            '--include-disabled' => true,
        ]);

        $this->assertSame([
            'products' => 0,
            'supplier_products' => 0,
            'categories' => 0,
            'suppliers' => 0,
            'supplier_category_mappings' => 0,
            'canonical_product_families' => 0,
            'category_product_attributes' => 0,
            'product_attributes' => 0,
            'attribute_values' => 0,
            'product_attribute_values' => 0,
        ], $payload['records_changed']);

        $this->assertTrue((bool) config('catalog_sync.create_enabled'));
        $this->assertFalse((bool) config('catalog_sync.update_enabled'));
        $this->assertFalse((bool) config('catalog_sync.sync_all_enabled'));
        $this->assertFalse((bool) config('catalog_sync.auto_enabled'));
        $this->get('/cart')->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function commandJson(string $command, array $arguments): array
    {
        $this->assertSame(0, Artisan::call($command, $arguments));

        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function readySupplier(string $name): Supplier
    {
        $supplier = Supplier::factory()->create([
            'company_name' => $name,
            'slug' => str($name)->slug()->value(),
            'status' => 'active',
            'import_enabled' => true,
            'schedule_enabled' => true,
            'schedule_type' => 'daily',
            'morning_import_time' => '06:00',
            'next_import_at' => now()->subMinute(),
        ]);

        SupplierFeed::factory()->create([
            'supplier_id' => $supplier->id,
            'feed_type' => 'xml',
            'feed_url' => 'https://feeds.example.test/'.$supplier->slug.'/products.xml',
            'mapping' => [
                'supplier_sku' => 'product.sku',
                'name' => 'product.name',
                'price' => 'product.price',
            ],
            'status' => 'active',
        ]);

        XmlMappingTemplate::factory()->create(['supplier_id' => $supplier->id]);

        return $supplier;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function supplierProduct(Supplier $supplier, array $overrides = []): SupplierProduct
    {
        return SupplierProduct::query()->create(array_merge([
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'SUP-'.str()->random(8),
            'ean' => null,
            'mpn' => null,
            'name' => 'Supplier staged product',
            'brand_name' => null,
            'category_name' => null,
            'price' => null,
            'quantity' => null,
            'currency' => 'EUR',
            'raw_data' => ['source' => 'test'],
            'payload_hash' => sha1((string) str()->uuid()),
            'received_at' => now(),
            'status' => 'new',
        ], $overrides));
    }

    /**
     * @return array<string, int>
     */
    private function protectedCounts(): array
    {
        return [
            'products' => Product::query()->count(),
            'supplier_products' => SupplierProduct::query()->count(),
            'categories' => Category::query()->count(),
            'suppliers' => Supplier::query()->count(),
            'supplier_category_mappings' => SupplierCategoryMapping::query()->count(),
            'canonical_product_families' => CanonicalProductFamily::query()->count(),
            'category_product_attributes' => CategoryProductAttribute::query()->count(),
            'product_attributes' => ProductAttribute::query()->count(),
            'attribute_values' => AttributeValue::query()->count(),
            'product_attribute_values' => ProductAttributeValue::query()->count(),
        ];
    }
}

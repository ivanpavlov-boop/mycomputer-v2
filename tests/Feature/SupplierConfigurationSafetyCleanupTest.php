<?php

namespace Tests\Feature;

use App\Jobs\ProcessSupplierImportRunJob;
use App\Jobs\ProcessXmlSupplierFeed;
use App\Jobs\RunSupplierImportJob;
use App\Jobs\SyncProductJob;
use App\Models\AttributeValue;
use App\Models\CanonicalProductFamily;
use App\Models\Category;
use App\Models\CategoryProductAttribute;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductSyncLog;
use App\Models\Supplier;
use App\Models\SupplierCategoryMapping;
use App\Models\SupplierFeed;
use App\Models\SupplierProduct;
use App\Models\XmlMappingTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use JsonException;
use Tests\TestCase;

class SupplierConfigurationSafetyCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_cleanup_unsafe_schedules_command_is_registered(): void
    {
        $commands = Artisan::all();

        $this->assertArrayHasKey('suppliers:cleanup-unsafe-schedules', $commands);
        $this->assertTrue($commands['suppliers:cleanup-unsafe-schedules']->getDefinition()->hasOption('supplier'));
        $this->assertTrue($commands['suppliers:cleanup-unsafe-schedules']->getDefinition()->hasOption('limit'));
        $this->assertTrue($commands['suppliers:cleanup-unsafe-schedules']->getDefinition()->hasOption('format'));
        $this->assertTrue($commands['suppliers:cleanup-unsafe-schedules']->getDefinition()->hasOption('apply'));
        $this->assertTrue($commands['suppliers:cleanup-unsafe-schedules']->getDefinition()->hasOption('dry-run'));
        $this->assertTrue($commands['suppliers:cleanup-unsafe-schedules']->getDefinition()->hasOption('only-unsafe'));
        $this->assertTrue($commands['suppliers:cleanup-unsafe-schedules']->getDefinition()->hasOption('disable-schedules-only'));
    }

    /**
     * @throws JsonException
     */
    public function test_dry_run_identifies_unsafe_scheduled_suppliers_without_changes(): void
    {
        $unsafe = $this->scheduledSupplier('ALSO');
        $safe = $this->scheduledSupplier('APCOM');
        $this->feed($safe);
        $this->mapping($safe);
        $this->supplierProduct($safe);
        $alreadyDisabled = $this->scheduledSupplier('Demo Distribution', [
            'schedule_enabled' => false,
            'schedule_type' => 'manual_only',
        ]);
        $this->feed($alreadyDisabled);
        $this->mapping($alreadyDisabled);
        $this->supplierProduct($alreadyDisabled);
        $hasStaging = $this->scheduledSupplier('Staged Without Feed');
        $this->supplierProduct($hasStaging);

        $counts = $this->protectedCounts();

        $payload = $this->commandJson('suppliers:cleanup-unsafe-schedules', [
            '--format' => 'json',
        ]);

        $this->assertTrue($payload['summary']['dry_run']);
        $this->assertSame(4, $payload['summary']['suppliers_checked']);
        $this->assertSame(1, $payload['summary']['unsafe_scheduled_suppliers']);
        $this->assertSame(1, $payload['summary']['schedules_to_disable']);
        $this->assertSame(0, $payload['summary']['schedules_disabled']);
        $this->assertSame(0, $payload['records_changed']['suppliers']);
        $this->assertSame('would_disable_schedule', $this->rowFor($payload, $unsafe)->action);
        $this->assertSame('no_action_safe', $this->rowFor($payload, $safe)->action);
        $this->assertSame('skipped_already_disabled', $this->rowFor($payload, $alreadyDisabled)->action);
        $this->assertSame('skipped_has_staging', $this->rowFor($payload, $hasStaging)->action);
        $this->assertTrue($unsafe->fresh()->schedule_enabled);
        $this->assertSame($counts, $this->protectedCounts());
    }

    /**
     * @throws JsonException
     */
    public function test_apply_disables_only_unsafe_supplier_schedules_and_is_idempotent(): void
    {
        $unsafe = $this->scheduledSupplier('ASBIS');
        $safe = $this->scheduledSupplier('APCOM');
        $this->feed($safe);
        $this->mapping($safe);
        $this->supplierProduct($safe);
        $hasStaging = $this->scheduledSupplier('Staged Without Feed');
        $this->supplierProduct($hasStaging);
        $counts = $this->protectedCounts();

        $payload = $this->commandJson('suppliers:cleanup-unsafe-schedules', [
            '--format' => 'json',
            '--apply' => true,
            '--only-unsafe' => true,
            '--limit' => 50,
        ]);

        $this->assertFalse($payload['summary']['dry_run']);
        $this->assertSame(1, $payload['summary']['unsafe_scheduled_suppliers']);
        $this->assertSame(1, $payload['summary']['schedules_disabled']);
        $this->assertSame(1, $payload['records_changed']['suppliers']);
        $this->assertSame('disabled_schedule', $payload['rows'][0]['action']);
        $this->assertFalse($unsafe->fresh()->schedule_enabled);
        $this->assertTrue($safe->fresh()->schedule_enabled);
        $this->assertTrue($hasStaging->fresh()->schedule_enabled);
        $this->assertSame($counts, $this->protectedCounts());

        $secondDryRun = $this->commandJson('suppliers:cleanup-unsafe-schedules', [
            '--format' => 'json',
            '--only-unsafe' => true,
        ]);

        $this->assertSame(0, $secondDryRun['summary']['unsafe_scheduled_suppliers']);
        $this->assertSame([], $secondDryRun['rows']);

        $secondApply = $this->commandJson('suppliers:cleanup-unsafe-schedules', [
            '--format' => 'json',
            '--apply' => true,
            '--disable-schedules-only' => true,
            '--only-unsafe' => true,
        ]);

        $this->assertSame(0, $secondApply['summary']['schedules_disabled']);
        $this->assertSame(0, $secondApply['records_changed']['suppliers']);
    }

    /**
     * @throws JsonException
     */
    public function test_supplier_filter_only_unsafe_and_limit_are_supported(): void
    {
        $firstUnsafe = $this->scheduledSupplier('ALSO');
        $this->scheduledSupplier('ASBIS');
        $safe = $this->scheduledSupplier('APCOM');
        $this->feed($safe);
        $this->mapping($safe);

        $filtered = $this->commandJson('suppliers:cleanup-unsafe-schedules', [
            '--format' => 'json',
            '--supplier' => 'also',
            '--only-unsafe' => true,
        ]);

        $this->assertCount(1, $filtered['rows']);
        $this->assertSame($firstUnsafe->id, $filtered['rows'][0]['supplier_id']);
        $this->assertSame('would_disable_schedule', $filtered['rows'][0]['action']);

        $limited = $this->commandJson('suppliers:cleanup-unsafe-schedules', [
            '--format' => 'json',
            '--only-unsafe' => true,
            '--limit' => 1,
        ]);

        $this->assertSame(2, $limited['summary']['unsafe_scheduled_suppliers']);
        $this->assertCount(1, $limited['rows']);
    }

    /**
     * @throws JsonException
     */
    public function test_cleanup_does_not_fetch_feeds_dispatch_jobs_call_sync_or_expose_secrets(): void
    {
        Http::fake();
        Queue::fake([RunSupplierImportJob::class, ProcessXmlSupplierFeed::class, SyncProductJob::class]);
        Bus::fake([RunSupplierImportJob::class, ProcessSupplierImportRunJob::class, ProcessXmlSupplierFeed::class, SyncProductJob::class]);

        $supplier = $this->scheduledSupplier('Secret Safe Supplier');
        $this->feed($supplier, 'https://feeds.example.test/export/index/type/xml/id/123/secret/VERYSECRET?token=RAW_TOKEN&api_key=RAW_KEY');
        $this->mapping($supplier);

        $payload = $this->commandJson('suppliers:cleanup-unsafe-schedules', [
            '--format' => 'json',
            '--apply' => true,
        ]);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('VERYSECRET', $encoded);
        $this->assertStringNotContainsString('RAW_TOKEN', $encoded);
        $this->assertStringNotContainsString('RAW_KEY', $encoded);
        $this->assertSame(0, ProductSyncLog::query()->count());
        $this->assertFalse((bool) config('catalog_sync.update_enabled'));
        $this->assertFalse((bool) config('catalog_sync.sync_all_enabled'));
        $this->assertFalse((bool) config('catalog_sync.auto_enabled'));
        $this->get('/cart')->assertNotFound();

        Http::assertNothingSent();
        Queue::assertNothingPushed();
        Bus::assertNotDispatched(RunSupplierImportJob::class);
        Bus::assertNotDispatched(ProcessSupplierImportRunJob::class);
        Bus::assertNotDispatched(ProcessXmlSupplierFeed::class);
        Bus::assertNotDispatched(SyncProductJob::class);
    }

    public function test_apply_and_dry_run_options_are_mutually_exclusive(): void
    {
        $this->artisan('suppliers:cleanup-unsafe-schedules', [
            '--apply' => true,
            '--dry-run' => true,
        ])
            ->expectsOutput('Use either --apply or --dry-run, not both.')
            ->assertFailed();
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function rowFor(array $payload, Supplier $supplier): object
    {
        $row = collect($payload['rows'])->firstWhere('supplier_id', $supplier->id);

        $this->assertNotNull($row);

        return (object) $row;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function scheduledSupplier(string $name, array $overrides = []): Supplier
    {
        return Supplier::query()->create(array_merge([
            'company_name' => $name,
            'slug' => str($name)->slug()->value(),
            'status' => 'active',
            'priority' => 10,
            'sync_strategy' => 'lowest_price',
            'import_enabled' => true,
            'schedule_enabled' => true,
            'schedule_type' => 'daily',
            'timezone' => 'Europe/Sofia',
            'stagger_minutes' => 20,
            'maximum_product_drop_percent' => 40,
            'minimum_product_count' => 1,
            'allow_destructive_sync' => false,
            'next_import_at' => now()->subMinute(),
        ], $overrides));
    }

    private function feed(Supplier $supplier, string $url = 'https://feeds.example.test/products.xml'): SupplierFeed
    {
        return SupplierFeed::query()->create([
            'supplier_id' => $supplier->id,
            'feed_name' => 'Test Feed',
            'feed_type' => 'xml',
            'feed_url' => $url,
            'update_interval' => 360,
            'status' => 'active',
        ]);
    }

    private function mapping(Supplier $supplier): XmlMappingTemplate
    {
        return XmlMappingTemplate::factory()->create(['supplier_id' => $supplier->id]);
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
            'supplier_category_mappings' => SupplierCategoryMapping::query()->count(),
            'canonical_product_families' => CanonicalProductFamily::query()->count(),
            'category_product_attributes' => CategoryProductAttribute::query()->count(),
            'product_attributes' => ProductAttribute::query()->count(),
            'attribute_values' => AttributeValue::query()->count(),
            'product_attribute_values' => ProductAttributeValue::query()->count(),
            'catalog_sync_logs' => ProductSyncLog::query()->count(),
        ];
    }
}

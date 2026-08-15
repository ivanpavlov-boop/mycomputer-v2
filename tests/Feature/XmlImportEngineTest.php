<?php

namespace Tests\Feature;

use App\Models\AvailabilityStatus;
use App\Models\AvailabilityStatusMapping;
use App\Models\FailedImport;
use App\Models\ImportHistory;
use App\Models\ImportJob;
use App\Models\SupplierFeed;
use App\Models\SupplierProduct;
use App\Models\XmlMappingTemplate;
use App\Services\Imports\XmlImportEngine;
use App\Services\Security\SsrfProtectionService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ExactSyntheticFeedSsrfProtectionService;
use Tests\TestCase;

class XmlImportEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_xml_import_stages_supplier_products_and_logs_failures(): void
    {
        $expectedUrl = 'https://feeds.example.test/products.xml';
        $this->seed();

        Http::preventStrayRequests();
        Http::fake([
            $expectedUrl => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<products>
    <product>
        <code>SUP-XML-001</code>
        <name>XML Demo Laptop</name>
        <brand>Lenovo</brand>
        <category>Business Laptops</category>
        <price>1200.50</price>
        <stock>7</stock>
    </product>
    <product>
        <code></code>
        <name>Invalid Product</name>
        <price>not-a-number</price>
    </product>
</products>
XML, 200),
        ]);

        $feed = SupplierFeed::query()->firstOrFail();
        $feed->update(['feed_url' => $expectedUrl]);

        $template = XmlMappingTemplate::query()->firstOrFail();

        $job = ImportJob::query()->create([
            'supplier_id' => $feed->supplier_id,
            'supplier_feed_id' => $feed->id,
            'xml_mapping_template_id' => $template->id,
            'type' => 'xml',
            'mode' => 'manual',
            'status' => 'pending',
        ]);

        $fake = $this->withExactSyntheticFeed($expectedUrl, fn () => app(XmlImportEngine::class)->import($job));

        $this->assertDatabaseHas('import_histories', [
            'import_job_id' => $job->id,
            'supplier_id' => $feed->supplier_id,
            'event' => 'finished',
        ]);
        $this->assertGreaterThan(0, ImportHistory::query()
            ->where('import_job_id', $job->id)
            ->where('event', 'finished')
            ->max('id'));

        $this->assertDatabaseHas('supplier_products', [
            'supplier_sku' => 'SUP-XML-001',
            'name' => 'XML Demo Laptop',
            'status' => 'new',
        ]);

        $this->assertSame(1, SupplierProduct::query()->where('supplier_sku', 'SUP-XML-001')->count());
        $this->assertSame(1, FailedImport::query()->where('import_job_id', $job->id)->count());
        $this->assertSame('completed_with_errors', $job->refresh()->status);
        $this->assertSame(1, ImportHistory::query()->where('import_job_id', $job->id)->count());
        $this->assertSame([$expectedUrl], $fake->requestedUrls);
        Http::assertSent(static fn (Request $request): bool => $request->url() === $expectedUrl);
        Http::assertSentCount(1);
    }

    public function test_started_generation_precedes_feed_and_staging_and_completed_with_errors_preserves_its_id(): void
    {
        $expectedUrl = 'https://feeds.example.test/generation.xml';
        $this->seed();

        $feed = SupplierFeed::query()->firstOrFail();
        $feed->update(['feed_url' => $expectedUrl]);
        $template = XmlMappingTemplate::query()->firstOrFail();
        $job = ImportJob::query()->create([
            'supplier_id' => $feed->supplier_id,
            'supplier_feed_id' => $feed->id,
            'xml_mapping_template_id' => $template->id,
            'type' => 'xml',
            'mode' => 'manual',
            'status' => 'pending',
        ]);

        $startedIdAtFetch = null;
        $startedIdAtFirstStagingWrite = null;
        $transactionLevel = DB::transactionLevel();
        Http::preventStrayRequests();
        Http::fake([
            $expectedUrl => function () use ($job, $transactionLevel, &$startedIdAtFetch) {
                $this->assertSame($transactionLevel, DB::transactionLevel());
                $history = ImportHistory::query()->where('import_job_id', $job->id)->sole();
                $this->assertSame('started', $history->event);
                $startedIdAtFetch = $history->id;

                return Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<products>
    <product><code>GEN-001</code><name>Generation Product</name><price>10.00</price><stock>1</stock></product>
    <product><code></code><name>Invalid</name><price>bad</price></product>
</products>
XML, 200);
            },
        ]);
        DB::listen(function (QueryExecuted $query) use ($job, &$startedIdAtFirstStagingWrite): void {
            if ($startedIdAtFirstStagingWrite === null
                && preg_match('/^\s*insert\s+into\s+["`]?supplier_products/i', $query->sql) === 1) {
                $history = ImportHistory::query()->where('import_job_id', $job->id)->sole();
                $this->assertSame('started', $history->event);
                $startedIdAtFirstStagingWrite = $history->id;
            }
        });

        $fake = $this->withExactSyntheticFeed($expectedUrl, fn () => app(XmlImportEngine::class)->import($job));

        $history = ImportHistory::query()->where('import_job_id', $job->id)->sole();
        $this->assertNotNull($startedIdAtFetch);
        $this->assertSame($startedIdAtFetch, $startedIdAtFirstStagingWrite);
        $this->assertSame($startedIdAtFetch, $history->id);
        $this->assertSame('finished', $history->event);
        $this->assertSame('warning', $history->level);
        $this->assertSame('completed_with_errors', $job->refresh()->status);
        $this->assertSame([$expectedUrl], $fake->requestedUrls);
        Http::assertSent(static fn (Request $request): bool => $request->url() === $expectedUrl);
        Http::assertSentCount(1);
    }

    public function test_xml_import_maps_external_availability_status_into_supplier_products(): void
    {
        $expectedUrl = 'https://feeds.example.test/products.xml';
        $this->seed();

        Http::preventStrayRequests();
        Http::fake([
            $expectedUrl => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<products>
    <product>
        <code>SUP-XML-AVAIL-001</code>
        <name>XML Availability Laptop</name>
        <brand>Lenovo</brand>
        <category>Business Laptops</category>
        <price>1300.00</price>
        <stock>0</stock>
        <availability>Incoming Shipment</availability>
        <availability_label>Incoming Shipment</availability_label>
    </product>
</products>
XML, 200),
        ]);

        $feed = SupplierFeed::query()->firstOrFail();
        $feed->update(['feed_url' => $expectedUrl]);
        $incoming = AvailabilityStatus::query()->where('code', 'incoming')->firstOrFail();

        AvailabilityStatusMapping::query()->create([
            'source_type' => 'xml',
            'source_code' => $feed->supplier->company_name,
            'external_status' => 'Incoming Shipment',
            'availability_status_id' => $incoming->id,
            'priority' => 1,
            'is_active' => true,
        ]);

        $template = XmlMappingTemplate::query()->firstOrFail();

        $job = ImportJob::query()->create([
            'supplier_id' => $feed->supplier_id,
            'supplier_feed_id' => $feed->id,
            'xml_mapping_template_id' => $template->id,
            'type' => 'xml',
            'mode' => 'manual',
            'status' => 'pending',
        ]);

        $fake = $this->withExactSyntheticFeed($expectedUrl, fn () => app(XmlImportEngine::class)->import($job));

        $supplierProduct = SupplierProduct::query()->where('supplier_sku', 'SUP-XML-AVAIL-001')->firstOrFail();

        $this->assertSame('Incoming Shipment', $supplierProduct->external_availability_status);
        $this->assertSame('Incoming Shipment', $supplierProduct->external_availability_label);
        $this->assertSame($incoming->id, $supplierProduct->availability_status_id);
        $this->assertSame([$expectedUrl], $fake->requestedUrls);
        Http::assertSent(static fn (Request $request): bool => $request->url() === $expectedUrl);
        Http::assertSentCount(1);
    }

    public function test_xml_import_blocks_private_network_feed_urls(): void
    {
        $this->seed();

        $feed = SupplierFeed::query()->firstOrFail();
        $feed->update(['feed_url' => 'http://127.0.0.1/private.xml']);

        $template = XmlMappingTemplate::query()->firstOrFail();

        $job = ImportJob::query()->create([
            'supplier_id' => $feed->supplier_id,
            'supplier_feed_id' => $feed->id,
            'xml_mapping_template_id' => $template->id,
            'type' => 'xml',
            'mode' => 'manual',
            'status' => 'pending',
        ]);
        Http::preventStrayRequests();

        try {
            app(XmlImportEngine::class)->import($job);
            $this->fail('Expected private network URL rejection.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('private or reserved network', $exception->getMessage());
        }

        $history = ImportHistory::query()->where('import_job_id', $job->id)->sole();
        $this->assertSame('failed', $history->event);
        $this->assertSame('error', $history->level);
        $this->assertSame('failed', $job->refresh()->status);
        Http::assertNothingSent();
    }

    private function withExactSyntheticFeed(string $expectedUrl, callable $callback): ExactSyntheticFeedSsrfProtectionService
    {
        $original = app(SsrfProtectionService::class);
        $fake = new ExactSyntheticFeedSsrfProtectionService($expectedUrl);
        $this->app->instance(SsrfProtectionService::class, $fake);

        try {
            $callback();
        } finally {
            $this->app->instance(SsrfProtectionService::class, $original);
        }

        return $fake;
    }
}

<?php

namespace Tests\Feature;

use App\Models\AvailabilityStatus;
use App\Models\AvailabilityStatusMapping;
use App\Models\FailedImport;
use App\Models\ImportJob;
use App\Models\SupplierFeed;
use App\Models\SupplierProduct;
use App\Models\XmlMappingTemplate;
use App\Services\Imports\XmlImportEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class XmlImportEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_xml_import_stages_supplier_products_and_logs_failures(): void
    {
        $this->seed();

        Http::fake([
            'https://feeds.example.test/products.xml' => Http::response(<<<'XML'
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
        $feed->update(['feed_url' => 'https://feeds.example.test/products.xml']);

        $template = XmlMappingTemplate::query()->firstOrFail();

        $job = ImportJob::query()->create([
            'supplier_id' => $feed->supplier_id,
            'supplier_feed_id' => $feed->id,
            'xml_mapping_template_id' => $template->id,
            'type' => 'xml',
            'mode' => 'manual',
            'status' => 'pending',
        ]);

        app(XmlImportEngine::class)->import($job);

        $this->assertDatabaseHas('supplier_products', [
            'supplier_sku' => 'SUP-XML-001',
            'name' => 'XML Demo Laptop',
            'status' => 'new',
        ]);

        $this->assertSame(1, SupplierProduct::query()->where('supplier_sku', 'SUP-XML-001')->count());
        $this->assertSame(1, FailedImport::query()->where('import_job_id', $job->id)->count());
        $this->assertSame('completed_with_errors', $job->refresh()->status);
    }

    public function test_xml_import_maps_external_availability_status_into_supplier_products(): void
    {
        $this->seed();

        Http::fake([
            'https://feeds.example.test/products.xml' => Http::response(<<<'XML'
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
        $feed->update(['feed_url' => 'https://feeds.example.test/products.xml']);
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

        app(XmlImportEngine::class)->import($job);

        $supplierProduct = SupplierProduct::query()->where('supplier_sku', 'SUP-XML-AVAIL-001')->firstOrFail();

        $this->assertSame('Incoming Shipment', $supplierProduct->external_availability_status);
        $this->assertSame('Incoming Shipment', $supplierProduct->external_availability_label);
        $this->assertSame($incoming->id, $supplierProduct->availability_status_id);
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

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('private or reserved network');

        app(XmlImportEngine::class)->import($job);
    }
}

<?php

namespace Tests\Feature;

use App\Models\AvailabilityStatus;
use App\Models\FailedImport;
use App\Models\ImportJob;
use App\Models\Product;
use App\Models\ProductSyncLog;
use App\Models\Supplier;
use App\Models\SupplierFeed;
use App\Models\SupplierProduct;
use App\Models\XmlMappingTemplate;
use App\Services\Imports\XmlImportEngine;
use App\Services\Products\ProductSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApcomSupplierImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('scout.driver', 'database');
        $this->seed();
    }

    public function test_apcom_seeded_feed_template_availability_and_attribute_mapping_exist(): void
    {
        $supplier = Supplier::query()->where('slug', 'apcom')->firstOrFail();

        $this->assertDatabaseHas('supplier_feeds', [
            'supplier_id' => $supplier->id,
            'feed_name' => 'APCOM XML Product Feed',
            'feed_type' => 'xml',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('xml_mapping_templates', [
            'supplier_id' => $supplier->id,
            'name' => 'APCOM XML Product Mapping',
            'root_path' => 'Products.Product',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('availability_status_mappings', [
            'source_type' => 'xml',
            'source_code' => 'APCOM',
            'external_status' => 'available',
        ]);

        $this->assertDatabaseHas('attribute_aliases', [
            'supplier_id' => $supplier->id,
            'alias' => 'RAM Memory',
            'source_type' => 'xml',
        ]);
    }

    public function test_apcom_xml_import_stages_rows_logs_failures_and_keeps_feed_active(): void
    {
        [$feed, $template] = $this->apcomFeedAndTemplate();
        $feed->update(['feed_url' => 'https://feeds.example.test/apcom.xml']);

        Http::fake([
            'https://feeds.example.test/apcom.xml' => Http::response($this->apcomXml(includeInvalidRow: true), 200),
        ]);

        $job = $this->importJob($feed, $template);

        app(XmlImportEngine::class)->import($job);

        $supplierProduct = SupplierProduct::query()->where('supplier_sku', 'APC-MAC-001')->firstOrFail();
        $incoming = AvailabilityStatus::query()->where('code', 'in_stock')->firstOrFail();

        $this->assertSame($feed->supplier_id, $supplierProduct->supplier_id);
        $this->assertSame('Apple MacBook Air 13 M3', $supplierProduct->name);
        $this->assertSame('0888462999999', $supplierProduct->ean);
        $this->assertSame('MC8K4ZE/A', $supplierProduct->mpn);
        $this->assertSame('Apple', $supplierProduct->brand_name);
        $this->assertSame('Laptops > Apple MacBook', $supplierProduct->category_name);
        $this->assertSame('2199.90', $supplierProduct->price);
        $this->assertSame(7, $supplierProduct->quantity);
        $this->assertSame('available', $supplierProduct->external_availability_status);
        $this->assertSame($incoming->id, $supplierProduct->availability_status_id);
        $this->assertSame('https://cdn.example.test/apcom/macbook.jpg', $supplierProduct->raw_data['_mapped']['image_url']);

        $this->assertDatabaseHas('supplier_product_attributes', [
            'supplier_product_id' => $supplierProduct->id,
            'raw_name' => 'RAM Memory',
            'raw_value' => '16GB',
            'status' => 'mapped',
        ]);

        $this->assertSame(1, FailedImport::query()->where('import_job_id', $job->id)->count());
        $this->assertSame('completed_with_errors', $job->refresh()->status);
        $this->assertSame('active', $feed->refresh()->status);
        $this->assertSame('1 rows failed validation.', $feed->last_error);
    }

    public function test_apcom_xml_import_is_retry_safe_and_product_sync_updates_catalog_and_search_payload(): void
    {
        [$feed, $template] = $this->apcomFeedAndTemplate();
        $feed->update(['feed_url' => 'https://feeds.example.test/apcom.xml']);

        Http::fake([
            'https://feeds.example.test/apcom.xml' => Http::sequence()
                ->push($this->apcomXml(price: '2199,90', quantity: 7), 200)
                ->push($this->apcomXml(price: '2099.90', quantity: 3), 200),
        ]);

        app(XmlImportEngine::class)->import($this->importJob($feed, $template));
        app(XmlImportEngine::class)->import($this->importJob($feed, $template));

        $supplierProduct = SupplierProduct::query()->where('supplier_sku', 'APC-MAC-001')->firstOrFail();

        $this->assertSame(1, SupplierProduct::query()->where('supplier_id', $feed->supplier_id)->where('supplier_sku', 'APC-MAC-001')->count());
        $this->assertSame('2099.90', $supplierProduct->price);
        $this->assertSame(3, $supplierProduct->quantity);
        $this->assertSame(4, $supplierProduct->attributes()->count());

        app(ProductSyncService::class)->sync($supplierProduct);

        $product = Product::query()->where('supplier_sku', 'APC-MAC-001')->firstOrFail();

        $this->assertSame('2099.90', $product->price);
        $this->assertSame(3, $product->quantity);
        $this->assertSame('Apple', $product->brand?->name);
        $this->assertSame('Apple MacBook', $product->category?->name);
        $this->assertSame('in_stock', $product->stock_status);
        $this->assertSame('https://cdn.example.test/apcom/macbook.jpg', $product->images()->firstOrFail()->path);
        $this->assertDatabaseHas('product_sync_logs', [
            'product_id' => $product->id,
            'supplier_product_id' => $supplierProduct->id,
            'status' => 'synced',
        ]);
        $this->assertSame(1, ProductSyncLog::query()->where('supplier_product_id', $supplierProduct->id)->count());

        $search = $product->toSearchableArray();
        $this->assertSame('Apple', $search['brand']);
        $this->assertContains('Apple MacBook', $search['category_path']);
        $this->assertSame('in_stock', $search['availability_status_code']);
        $this->assertContains('ram', collect($search['attributes'])->pluck('slug')->all());
    }

    private function apcomFeedAndTemplate(): array
    {
        $supplier = Supplier::query()->where('slug', 'apcom')->firstOrFail();

        return [
            SupplierFeed::query()->where('supplier_id', $supplier->id)->where('feed_name', 'APCOM XML Product Feed')->firstOrFail(),
            XmlMappingTemplate::query()->where('supplier_id', $supplier->id)->where('name', 'APCOM XML Product Mapping')->firstOrFail(),
        ];
    }

    private function importJob(SupplierFeed $feed, XmlMappingTemplate $template): ImportJob
    {
        return ImportJob::query()->create([
            'supplier_id' => $feed->supplier_id,
            'supplier_feed_id' => $feed->id,
            'xml_mapping_template_id' => $template->id,
            'type' => 'xml',
            'mode' => 'manual',
            'status' => 'pending',
        ]);
    }

    private function apcomXml(string $price = '2199.90', int $quantity = 7, bool $includeInvalidRow = false): string
    {
        $invalid = $includeInvalidRow ? <<<'XML'
    <Product>
        <SKU></SKU>
        <Name>Invalid APCOM Row</Name>
        <Price>not-number</Price>
    </Product>
XML : '';

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Products>
    <Product>
        <SKU>APC-MAC-001</SKU>
        <EAN>0888462999999</EAN>
        <MPN>MC8K4ZE/A</MPN>
        <Name>Apple MacBook Air 13 M3</Name>
        <Brand>Apple</Brand>
        <CategoryPath>Laptops &gt; Apple MacBook</CategoryPath>
        <Price>{$price}</Price>
        <Stock>{$quantity}</Stock>
        <Availability>available</Availability>
        <AvailabilityLabel>Available</AvailabilityLabel>
        <Image>https://cdn.example.test/apcom/macbook.jpg</Image>
        <Attributes>
            <Attribute Name="RAM Memory" Value="16GB" />
            <Attribute Name="SSD" Value="512 GB" />
            <Attribute Name="Processor" Value="Apple M3" />
            <Attribute Name="Screen" Value="13 inch" />
        </Attributes>
    </Product>
{$invalid}
</Products>
XML;
    }
}

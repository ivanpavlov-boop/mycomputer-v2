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
            'root_path' => 'xml.product',
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

        $supplierProduct = SupplierProduct::query()->where('supplier_sku', 'MW103ZE/A')->firstOrFail();
        $incoming = AvailabilityStatus::query()->where('code', 'in_stock')->firstOrFail();

        $this->assertSame($feed->supplier_id, $supplierProduct->supplier_id);
        $this->assertSame('Apple MBA 13.6: STARLIGHT/M4 10C CPU/10C GPU/16GB/512GB-ZEE', $supplierProduct->name);
        $this->assertSame('195949837869', $supplierProduct->ean);
        $this->assertSame('MW103ZE/A', $supplierProduct->mpn);
        $this->assertSame('Apple', $supplierProduct->brand_name);
        $this->assertSame('Apcom,Mac > MacBook Air > MacBook Air,EOL Products > EOL Products', $supplierProduct->category_name);
        $this->assertSame('855.62', $supplierProduct->price);
        $this->assertSame(7, $supplierProduct->quantity);
        $this->assertNull($supplierProduct->external_availability_status);
        $this->assertSame($incoming->id, $supplierProduct->availability_status_id);
        $this->assertSame('EUR', $supplierProduct->currency);
        $this->assertSame('https://cdn.example.test/apcom/macbook-01.jpg', $supplierProduct->raw_data['_mapped']['image_url']);

        $zeroStockProduct = SupplierProduct::query()->where('supplier_sku', 'ZERO-STOCK-001')->firstOrFail();
        $this->assertSame(0, $zeroStockProduct->quantity);
        $this->assertSame('out_of_stock', $zeroStockProduct->availabilityStatus?->code);

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
                ->push($this->apcomXml(price: '855,62', quantity: 7), 200)
                ->push($this->apcomXml(price: '849.99', quantity: 3), 200),
        ]);

        app(XmlImportEngine::class)->import($this->importJob($feed, $template));
        app(XmlImportEngine::class)->import($this->importJob($feed, $template));

        $supplierProduct = SupplierProduct::query()->where('supplier_sku', 'MW103ZE/A')->firstOrFail();

        $this->assertSame(1, SupplierProduct::query()->where('supplier_id', $feed->supplier_id)->where('supplier_sku', 'MW103ZE/A')->count());
        $this->assertSame('849.99', $supplierProduct->price);
        $this->assertSame(3, $supplierProduct->quantity);
        $this->assertSame(4, $supplierProduct->attributes()->count());

        app(ProductSyncService::class)->sync($supplierProduct);

        $product = Product::query()->where('supplier_sku', 'MW103ZE/A')->firstOrFail();

        $this->assertSame('849.99', $product->price);
        $this->assertSame(3, $product->quantity);
        $this->assertSame('Apple', $product->brand?->name);
        $this->assertSame('MacBook Air', $product->category?->name);
        $this->assertSame('limited_stock', $product->stock_status);
        $this->assertSame('https://cdn.example.test/apcom/macbook-01.jpg', $product->images()->firstOrFail()->path);
        $this->assertSame(2, $product->images()->count());
        $this->assertDatabaseHas('product_sync_logs', [
            'product_id' => $product->id,
            'supplier_product_id' => $supplierProduct->id,
            'status' => 'synced',
        ]);
        $this->assertSame(1, ProductSyncLog::query()->where('supplier_product_id', $supplierProduct->id)->count());

        $search = $product->toSearchableArray();
        $this->assertSame('Apple', $search['brand']);
        $this->assertContains('MacBook Air', $search['category_path']);
        $this->assertSame('limited_stock', $search['availability_status_code']);
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

    private function apcomXml(string $price = '855.62', int $quantity = 7, bool $includeInvalidRow = false): string
    {
        $invalid = $includeInvalidRow ? <<<'XML'
    <product>
        <partno></partno>
        <name>Invalid APCOM Row</name>
        <fd_price>not-number</fd_price>
    </product>
XML : '';

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<xml encoding="utf-8">
    <product>
        <partno>MW103ZE/A</partno>
        <ean>195949837869</ean>
        <eol>0</eol>
        <name>Apple MBA 13.6: STARLIGHT/M4 10C CPU/10C GPU/16GB/512GB-ZEE</name>
        <category>Apcom,Mac &gt; MacBook Air &gt; MacBook Air,EOL Products &gt; EOL Products</category>
        <dac_price>956</dac_price>
        <fd_price>{$price}</fd_price>
        <stock>{$quantity}</stock>
        <manufacturer>Apple</manufacturer>
        <url>https://apcom.shop/catalog/product/view/id/31980</url>
        <promo>0</promo>
        <news>0</news>
        <images>
            <image>https://cdn.example.test/apcom/macbook-01.jpg</image>
            <image>https://cdn.example.test/apcom/macbook-02.jpg</image>
        </images>
        <cncode>84713000</cncode>
        <width>5.570000</width>
        <height>24.370000</height>
        <depth>33.440000</depth>
        <weight>1.670000</weight>
        <group>Apple CPU</group>
        <Attributes>
            <Attribute Name="RAM Memory" Value="16GB" />
            <Attribute Name="SSD" Value="512 GB" />
            <Attribute Name="Processor" Value="Apple M3" />
            <Attribute Name="Screen" Value="13 inch" />
        </Attributes>
    </product>
    <product>
        <partno>ZERO-STOCK-001</partno>
        <ean></ean>
        <eol>0</eol>
        <name>APCOM Zero Stock Adapter</name>
        <category>Apcom,Accessories &gt; Power &amp; Cable &gt; Adapters &gt; Adapters</category>
        <dac_price>25.49</dac_price>
        <fd_price>25.49</fd_price>
        <stock>0</stock>
        <manufacturer>Satechi</manufacturer>
        <url>https://apcom.shop/catalog/product/view/id/00000</url>
        <promo>0</promo>
        <news>0</news>
        <images>
            <image>https://cdn.example.test/apcom/adapter-01.jpg</image>
        </images>
        <cncode>84718000</cncode>
        <width>0.000000</width>
        <height>0.000000</height>
        <depth>0.000000</depth>
        <weight>0.060000</weight>
        <group>Third Party Products</group>
    </product>
{$invalid}
</xml>
XML;
    }
}

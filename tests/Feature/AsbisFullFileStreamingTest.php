<?php

namespace Tests\Feature;

use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use JsonException;
use Tests\TestCase;

class AsbisFullFileStreamingTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_command_exposes_full_file_options_without_apply(): void
    {
        $definition = Artisan::all()['suppliers:preview-asbis-dual-feed']->getDefinition();

        $this->assertFalse($definition->hasOption('apply'));

        foreach (['full-file', 'summary-only', 'sample-limit', 'issue-sample-limit'] as $option) {
            $this->assertTrue($definition->hasOption($option), 'Missing option '.$option);
        }
    }

    /**
     * @throws JsonException
     */
    public function test_full_file_mode_streams_more_than_5000_rows_without_mutation(): void
    {
        Http::fake();
        Queue::fake();
        Bus::fake();

        Supplier::factory()->create(['company_name' => 'ASBIS', 'slug' => 'asbis']);
        [$productPath, $pricePath] = $this->largeFixtureFiles(6001);
        $counts = $this->protectedCounts();

        try {
            $this->assertSame(0, Artisan::call('suppliers:preview-asbis-dual-feed', [
                '--supplier' => 'asbis',
                '--product-list-fixture' => $productPath,
                '--price-avail-fixture' => $pricePath,
                '--product-key' => 'ProductCode',
                '--price-key' => 'WIC',
                '--full-file' => true,
                '--max-rows' => 5,
                '--summary-only' => true,
                '--sample-limit' => 2,
                '--issue-sample-limit' => 2,
                '--format' => 'json',
            ]));

            $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

            $this->assertTrue($payload['success']);
            $this->assertSame('full_file_preview', $payload['mode']);
            $this->assertSame('streaming_xmlreader', $payload['parser']['parser_mode']);
            $this->assertSame('full_file', $payload['parser']['effective_scan_mode']);
            $this->assertNull($payload['parser']['effective_row_limit']);
            $this->assertTrue($payload['parser']['max_rows_overridden']);
            $this->assertTrue($payload['parser']['full_file_completed']);
            $this->assertSame(6001, $payload['parser']['product_list_rows_scanned']);
            $this->assertSame(6001, $payload['parser']['price_avail_rows_scanned']);
            $this->assertSame(6001, $payload['summary']['joined_rows']);
            $this->assertSame(6001, $payload['summary']['ready_to_create']);
            $this->assertSame(6001, $payload['readiness']['apply_candidate_count']);
            $this->assertSame([], $payload['ready_samples']);
            $this->assertSame([], $payload['issue_samples']);
            $this->assertSame(64, strlen($payload['source_fingerprints']['product_list_sha256']));
            $this->assertSame(64, strlen($payload['source_fingerprints']['price_avail_sha256']));
            $this->assertGreaterThan(0, $payload['source_fingerprints']['product_list_size_bytes']);
            $this->assertGreaterThan(0, $payload['source_fingerprints']['price_avail_size_bytes']);
            $this->assertGreaterThanOrEqual(0, $payload['parser']['elapsed_seconds']);
            $this->assertGreaterThan(0, $payload['parser']['peak_memory_bytes']);
            $this->assertSame($counts, $this->protectedCounts());

            Http::assertNothingSent();
            Queue::assertNothingPushed();
            Bus::assertNothingDispatched();
        } finally {
            @unlink($productPath);
            @unlink($pricePath);
        }
    }

    /**
     * @throws JsonException
     */
    public function test_malformed_xml_fails_safely_without_mutation(): void
    {
        Http::fake();
        Queue::fake();
        Bus::fake();

        Supplier::factory()->create(['company_name' => 'ASBIS', 'slug' => 'asbis']);
        $productPath = tempnam(sys_get_temp_dir(), 'asbis-products-malformed-');
        $pricePath = tempnam(sys_get_temp_dir(), 'asbis-prices-malformed-');
        file_put_contents($productPath, '<ProductCatalog><Product><ProductCode>BROKEN-001</ProductCode></Product>');
        file_put_contents($pricePath, '<CONTENT><PRICE><WIC>BROKEN-001</WIC>');
        $counts = $this->protectedCounts();

        try {
            $this->assertSame(1, Artisan::call('suppliers:preview-asbis-dual-feed', [
                '--supplier' => 'asbis',
                '--product-list-fixture' => $productPath,
                '--price-avail-fixture' => $pricePath,
                '--product-key' => 'ProductCode',
                '--price-key' => 'WIC',
                '--full-file' => true,
                '--format' => 'json',
            ]));

            $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

            $this->assertFalse($payload['success']);
            $this->assertFalse($payload['parser']['full_file_completed']);
            $this->assertSame(0, $payload['records_changed']['products']);
            $this->assertSame(0, $payload['records_changed']['supplier_products']);
            $this->assertSame($counts, $this->protectedCounts());
            Http::assertNothingSent();
            Queue::assertNothingPushed();
            Bus::assertNothingDispatched();
        } finally {
            @unlink($productPath);
            @unlink($pricePath);
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function largeFixtureFiles(int $rows): array
    {
        $productPath = tempnam(sys_get_temp_dir(), 'asbis-products-');
        $pricePath = tempnam(sys_get_temp_dir(), 'asbis-prices-');
        $product = fopen($productPath, 'wb');
        $price = fopen($pricePath, 'wb');

        fwrite($product, '<?xml version="1.0" encoding="UTF-8"?><ProductCatalog>');
        fwrite($price, '<?xml version="1.0" encoding="UTF-8"?><CONTENT>');

        for ($index = 1; $index <= $rows; $index++) {
            $sku = 'STREAM-'.str_pad((string) $index, 6, '0', STR_PAD_LEFT);
            $ean = '590'.str_pad((string) $index, 10, '0', STR_PAD_LEFT);
            fwrite($product, '<Product><ProductCode>'.$sku.'</ProductCode><Vendor>StreamBrand</Vendor><ProductCategory>Streaming</ProductCategory><ProductDescription>Streaming product '.$index.'</ProductDescription><MPN>MPN-'.$index.'</MPN></Product>');
            fwrite($price, '<PRICE><WIC>'.$sku.'</WIC><MY_PRICE>10.00</MY_PRICE><CURRENCY_CODE>EUR</CURRENCY_CODE><AVAIL>да</AVAIL><EAN>'.$ean.'</EAN><DESCRIPTION>Streaming product '.$index.'</DESCRIPTION></PRICE>');
        }

        fwrite($product, '</ProductCatalog>');
        fwrite($price, '</CONTENT>');
        fclose($product);
        fclose($price);

        return [$productPath, $pricePath];
    }

    /**
     * @return array<string, int>
     */
    private function protectedCounts(): array
    {
        return [
            'products' => DB::table('products')->count(),
            'supplier_products' => DB::table('supplier_products')->count(),
            'categories' => DB::table('categories')->count(),
            'suppliers' => DB::table('suppliers')->count(),
            'supplier_category_mappings' => DB::table('supplier_category_mappings')->count(),
            'canonical_product_families' => DB::table('canonical_product_families')->count(),
            'category_product_attributes' => DB::table('category_product_attributes')->count(),
            'product_attributes' => DB::table('product_attributes')->count(),
            'attribute_values' => DB::table('attribute_values')->count(),
            'product_attribute_values' => DB::table('product_attribute_values')->count(),
            'catalog_sync_batches' => $this->tableCount('catalog_sync_batches'),
            'catalog_sync_logs' => $this->tableCount('catalog_sync_logs'),
        ];
    }

    private function tableCount(string $table): int
    {
        return Schema::hasTable($table) ? DB::table($table)->count() : 0;
    }
}

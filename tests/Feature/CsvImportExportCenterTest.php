<?php

namespace Tests\Feature;

use App\Models\CsvExportJob;
use App\Models\CsvImportJob;
use App\Models\Product;
use App\Services\Csv\CsvExportService;
use App\Services\Csv\CsvImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CsvImportExportCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_csv_import_creates_product(): void
    {
        $this->seed();

        $job = $this->importJob('products', "sku;ean;name;brand;category;price;quantity;active\nCSV-LAP-001;3800000000001;CSV Laptop;Lenovo;Laptops;1299.99;7;1\n");

        app(CsvImportService::class)->process($job);

        $this->assertDatabaseHas('products', [
            'sku' => 'CSV-LAP-001',
            'name' => 'CSV Laptop',
            'price' => 1299.99,
            'quantity' => 7,
            'active' => true,
        ]);
        $this->assertSame('completed', $job->fresh()->status);
    }

    public function test_price_update_csv_import_updates_existing_product(): void
    {
        $this->seed();

        $job = $this->importJob('prices', "sku,price,purchase_price,promo_price\nMC-LAP-001,1499.50,1200.00,1399.00\n", 'update-only');

        app(CsvImportService::class)->process($job);

        $product = Product::query()->where('sku', 'MC-LAP-001')->firstOrFail();
        $this->assertSame('1499.50', $product->price);
        $this->assertSame('1200.00', $product->purchase_price);
        $this->assertSame('1399.00', $product->promo_price);
    }

    public function test_stock_update_csv_import_updates_existing_product(): void
    {
        $this->seed();

        $job = $this->importJob('stock', "sku,quantity,stock_status\nMC-LAP-001,0,out_of_stock\n", 'update-only');

        app(CsvImportService::class)->process($job);

        $product = Product::query()->where('sku', 'MC-LAP-001')->firstOrFail();
        $this->assertSame(0, $product->quantity);
        $this->assertSame('out_of_stock', $product->stock_status);
    }

    public function test_failed_rows_are_stored(): void
    {
        $this->seed();

        $job = $this->importJob('prices', "sku,price\nUNKNOWN-SKU,not-a-number\n", 'update-only');

        app(CsvImportService::class)->process($job);

        $this->assertSame('completed_with_errors', $job->fresh()->status);
        $this->assertSame(1, $job->failures()->count());
        $this->assertDatabaseHas('csv_import_failures', [
            'csv_import_job_id' => $job->id,
            'row_number' => 2,
            'error_type' => 'validation',
        ]);
    }

    public function test_preview_mode_maps_rows_without_importing(): void
    {
        $this->seed();

        $job = $this->importJob('products', "sku,name,price\nPREVIEW-001,Preview Product,19.99\n");
        $preview = app(CsvImportService::class)->preview($job);

        $this->assertSame('previewed', $job->fresh()->status);
        $this->assertSame('PREVIEW-001', $preview[0]['mapped']['sku']);
        $this->assertDatabaseMissing('products', ['sku' => 'PREVIEW-001']);
    }

    public function test_dry_run_mode_validates_without_writing_products(): void
    {
        $this->seed();

        $job = $this->importJob('products', "sku,name,price\nDRY-001,Dry Run Product,22.00\n", 'dry-run');

        app(CsvImportService::class)->process($job);

        $this->assertSame('completed', $job->fresh()->status);
        $this->assertDatabaseMissing('products', ['sku' => 'DRY-001']);
    }

    public function test_product_export_writes_csv_file(): void
    {
        $this->seed();

        $job = CsvExportJob::query()->create([
            'type' => 'products',
            'status' => 'pending',
            'filters' => ['active' => '1'],
        ]);

        app(CsvExportService::class)->process($job);

        $job->refresh();
        $this->assertSame('completed', $job->status);
        $this->assertNotNull($job->file_path);
        $this->assertFileExists(storage_path('app/'.$job->file_path));
        $this->assertStringContainsString('MC-LAP-001', file_get_contents(storage_path('app/'.$job->file_path)));
    }

    public function test_failed_row_export_writes_csv_file(): void
    {
        $this->seed();

        $job = $this->importJob('prices', "sku,price\nUNKNOWN-SKU,not-a-number\n", 'update-only');
        app(CsvImportService::class)->process($job);

        $path = app(CsvImportService::class)->exportFailures($job->fresh());

        $this->assertFileExists(storage_path('app/'.$path));
        $this->assertStringContainsString('UNKNOWN-SKU', file_get_contents(storage_path('app/'.$path)));
    }

    private function importJob(string $type, string $contents, string $mode = 'create-or-update'): CsvImportJob
    {
        File::ensureDirectoryExists(storage_path('app/imports'));
        $path = 'imports/test-'.strtolower(str_replace('\\', '-', $type)).'-'.uniqid().'.csv';
        file_put_contents(storage_path('app/'.$path), $contents);

        return CsvImportJob::query()->create([
            'type' => $type,
            'status' => 'pending',
            'file_path' => $path,
            'original_filename' => basename($path),
            'mode' => $mode,
        ]);
    }
}

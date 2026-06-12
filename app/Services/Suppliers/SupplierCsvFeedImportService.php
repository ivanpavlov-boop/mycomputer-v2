<?php

namespace App\Services\Suppliers;

use App\Models\FailedImport;
use App\Models\ImportHistory;
use App\Models\ImportJob;
use App\Models\SupplierFeed;
use App\Models\SupplierProduct;
use App\Services\Attributes\SupplierAttributeExtractionService;
use App\Services\Availability\AvailabilityStatusMapper;
use App\Services\Csv\CsvMappingService;
use App\Services\Security\SsrfProtectionService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

class SupplierCsvFeedImportService
{
    public function __construct(
        private readonly SsrfProtectionService $ssrfProtection,
        private readonly CsvMappingService $csvMapping,
        private readonly AvailabilityStatusMapper $availabilityMapper,
        private readonly SupplierAttributeExtractionService $attributeExtraction,
    ) {}

    public function import(SupplierFeed $feed, ImportJob $job): ImportJob
    {
        $job->update([
            'status' => 'running',
            'started_at' => now(),
            'error_message' => null,
        ]);

        $this->log($job, 'started', 'info', 'CSV supplier import started.');

        try {
            $path = $this->storeFeedFile($feed);
            $rows = $this->csvMapping->readRows($path);

            $job->update(['total_rows' => count($rows)]);

            foreach ($rows as $row) {
                $mapped = $this->csvMapping->mapRow($row['data'], $feed->mapping, 'products');
                $errors = $this->validateMappedRow($mapped);

                if ($errors !== []) {
                    $this->failRow($job, $row['row_number'], $row['data'], implode('; ', $errors));

                    continue;
                }

                $availability = $this->availabilityMapper->mapWithFallback(
                    'csv',
                    $feed->supplier?->company_name,
                    $mapped['external_availability_status'] ?? $mapped['stock_status'] ?? null,
                    isset($mapped['quantity']) ? (int) $mapped['quantity'] : null,
                );

                $supplierProduct = SupplierProduct::query()->create([
                    'supplier_id' => $job->supplier_id,
                    'supplier_feed_id' => $job->supplier_feed_id,
                    'supplier_sku' => $mapped['sku'] ?? $mapped['supplier_sku'] ?? null,
                    'ean' => $mapped['ean'] ?? null,
                    'mpn' => $mapped['mpn'] ?? null,
                    'name' => $mapped['name'] ?? null,
                    'brand_name' => $mapped['brand'] ?? $mapped['brand_name'] ?? null,
                    'category_name' => $mapped['category'] ?? $mapped['category_name'] ?? null,
                    'price' => $mapped['price'] ?? null,
                    'quantity' => $mapped['quantity'] ?? null,
                    'external_availability_status' => $mapped['external_availability_status'] ?? $mapped['stock_status'] ?? null,
                    'external_availability_label' => $mapped['external_availability_label'] ?? null,
                    'availability_status_id' => $availability?->id,
                    'currency' => $mapped['currency'] ?? 'BGN',
                    'raw_data' => $row['data'],
                    'payload_hash' => sha1(json_encode($row['data'], JSON_THROW_ON_ERROR)),
                    'received_at' => now(),
                    'status' => 'new',
                    'mapping_notes' => 'Imported from CSV feed into staging. Catalog products are not updated directly.',
                ]);

                $this->attributeExtraction->stage(
                    $supplierProduct,
                    $this->attributeExtraction->extractFromArray($mapped),
                    'csv',
                    $feed->supplier?->company_name,
                );

                $job->increment('processed_rows');
            }

            $job->update([
                'status' => $job->failed_rows > 0 ? 'completed_with_errors' : 'completed',
                'finished_at' => now(),
            ]);

            $feed->update([
                'last_sync_at' => now(),
                'last_error' => $job->failed_rows > 0 ? "{$job->failed_rows} rows failed validation." : null,
                'status' => $job->failed_rows > 0 ? 'failed' : 'active',
            ]);

            $this->log($job, 'finished', $job->failed_rows > 0 ? 'warning' : 'info', 'CSV supplier import finished.', [
                'processed_rows' => $job->processed_rows,
                'failed_rows' => $job->failed_rows,
            ]);
        } catch (Throwable $exception) {
            $job->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => $exception->getMessage(),
            ]);

            $feed->update([
                'status' => 'failed',
                'last_error' => $exception->getMessage(),
            ]);

            $this->log($job, 'failed', 'error', $exception->getMessage());

            throw $exception;
        }

        return $job->refresh();
    }

    /**
     * @param array<string, mixed> $mapped
     * @return array<int, string>
     */
    private function validateMappedRow(array $mapped): array
    {
        $errors = [];

        if (blank($mapped['sku'] ?? $mapped['supplier_sku'] ?? null)) {
            $errors[] = 'sku is required';
        }

        if (blank($mapped['name'] ?? null)) {
            $errors[] = 'name is required';
        }

        foreach (['price', 'quantity'] as $field) {
            if (filled($mapped[$field] ?? null) && ! is_numeric($mapped[$field])) {
                $errors[] = "{$field} must be numeric";
            }
        }

        return $errors;
    }

    private function storeFeedFile(SupplierFeed $feed): string
    {
        $contents = $this->ssrfProtection->get($feed->feed_url, $feed->username, $feed->password);

        if (trim($contents) === '') {
            throw new \RuntimeException('CSV feed is empty or unreadable.');
        }

        $directory = storage_path('app/'.CsvMappingService::IMPORT_DIR);
        File::ensureDirectoryExists($directory);

        $path = CsvMappingService::IMPORT_DIR.'/supplier-feed-'.$feed->id.'-'.now()->format('YmdHis').'-'.Str::random(8).'.csv';
        File::put(storage_path('app/'.$path), $contents);

        return $path;
    }

    /**
     * @param array<string, mixed> $rawData
     */
    private function failRow(ImportJob $job, int $rowNumber, array $rawData, string $message): void
    {
        FailedImport::query()->create([
            'import_job_id' => $job->id,
            'supplier_id' => $job->supplier_id,
            'supplier_feed_id' => $job->supplier_feed_id,
            'row_number' => $rowNumber,
            'error_type' => 'validation',
            'error_message' => $message,
            'raw_data' => $rawData,
        ]);

        $job->increment('failed_rows');
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(ImportJob $job, string $event, string $level, ?string $message = null, array $context = []): void
    {
        ImportHistory::query()->create([
            'import_job_id' => $job->id,
            'supplier_id' => $job->supplier_id,
            'supplier_feed_id' => $job->supplier_feed_id,
            'event' => $event,
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ]);
    }
}

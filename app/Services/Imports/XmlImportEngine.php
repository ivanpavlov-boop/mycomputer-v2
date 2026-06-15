<?php

namespace App\Services\Imports;

use App\Models\FailedImport;
use App\Models\ImportHistory;
use App\Models\ImportJob;
use App\Models\SupplierFeed;
use App\Models\SupplierProduct;
use App\Models\XmlMappingTemplate;
use App\Services\Attributes\SupplierAttributeExtractionService;
use App\Services\Availability\AvailabilityStatusMapper;
use App\Services\Security\SsrfProtectionService;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use SimpleXMLElement;
use Throwable;

class XmlImportEngine
{
    public function __construct(
        private readonly SsrfProtectionService $ssrfProtection,
        private readonly AvailabilityStatusMapper $availabilityMapper,
        private readonly SupplierAttributeExtractionService $attributeExtraction,
    ) {}

    /**
     * Preview mapped XML rows without writing supplier products.
     *
     * @return array<int, array<string, mixed>>
     */
    public function preview(SupplierFeed $feed, XmlMappingTemplate $template, int $limit = 20): array
    {
        $xml = $this->loadXml($feed);
        $rows = $this->extractRows($xml, $template->root_path);
        $preview = [];

        foreach (array_slice($rows, 0, $limit) as $row) {
            $preview[] = $this->mapRow($row, $template);
        }

        return $preview;
    }

    public function import(ImportJob $job): ImportJob
    {
        $job->loadMissing(['feed', 'mappingTemplate']);

        $feed = $job->feed;
        $template = $job->mappingTemplate;

        if (! $feed || ! $template) {
            throw new \RuntimeException('Import job is missing feed or XML mapping template.');
        }

        $job->update([
            'status' => 'running',
            'started_at' => now(),
            'error_message' => null,
        ]);

        $this->log($job, 'started', 'info', 'XML import started.');

        try {
            $xml = $this->loadXml($feed);
            $rows = $this->extractRows($xml, $template->root_path);

            $job->update(['total_rows' => count($rows)]);

            foreach ($rows as $index => $row) {
                $mapped = $this->mapRow($row, $template);
                $errors = $this->validateMappedRow($mapped, $template);

                if ($errors !== []) {
                    $this->failRow($job, $index + 1, $mapped, implode('; ', $errors));

                    continue;
                }

                $availability = $this->availabilityMapper->mapWithFallback(
                    'xml',
                    $feed->supplier?->company_name,
                    $mapped['external_availability_status'] ?? $mapped['stock_status'] ?? null,
                    isset($mapped['quantity']) ? (int) $mapped['quantity'] : null,
                );

                $rawData = $mapped['_raw'];
                $mappedData = Arr::except($mapped, ['_raw']);

                if ($mappedData !== []) {
                    $rawData['_mapped'] = $mappedData;
                }

                $categoryName = $this->supplierProductCategoryName($mapped['category_name'] ?? null);

                if ($categoryName !== null && Str::length($categoryName) > 255) {
                    $this->failRow($job, $index + 1, $mapped, 'category_name exceeds 255 characters after primary category path mapping');

                    continue;
                }

                $supplierProduct = SupplierProduct::query()->updateOrCreate(
                    $this->supplierProductLookup($job, $mapped),
                    [
                        'supplier_id' => $job->supplier_id,
                        'supplier_feed_id' => $job->supplier_feed_id,
                        'supplier_sku' => $mapped['supplier_sku'] ?? null,
                        'ean' => $mapped['ean'] ?? null,
                        'mpn' => $mapped['mpn'] ?? null,
                        'name' => $mapped['name'] ?? null,
                        'brand_name' => $mapped['brand_name'] ?? null,
                        'category_name' => $categoryName,
                        'price' => $mapped['price'] ?? null,
                        'quantity' => $mapped['quantity'] ?? null,
                        'external_availability_status' => $mapped['external_availability_status'] ?? $mapped['stock_status'] ?? null,
                        'external_availability_label' => $mapped['external_availability_label'] ?? null,
                        'availability_status_id' => $availability?->id,
                        'currency' => $mapped['currency'] ?? 'BGN',
                        'raw_data' => $rawData,
                        'payload_hash' => sha1(json_encode($mapped['_raw'], JSON_THROW_ON_ERROR)),
                        'received_at' => now(),
                        'synced_at' => null,
                        'status' => 'new',
                        'mapping_notes' => 'Imported from XML feed into staging. Catalog products are not updated directly.',
                    ],
                );

                $supplierProduct->attributes()->delete();

                $this->attributeExtraction->stage(
                    $supplierProduct,
                    $this->attributeExtraction->extractFromXml($row),
                    'xml',
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
                'status' => 'active',
            ]);

            $this->log($job, 'finished', $job->failed_rows > 0 ? 'warning' : 'info', 'XML import finished.', [
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

    protected function loadXml(SupplierFeed $feed): SimpleXMLElement
    {
        $source = $feed->feed_url;

        if (! Str::startsWith($source, ['http://', 'https://'])) {
            throw new \RuntimeException('Supplier XML feeds must use HTTPS or HTTP URLs.');
        }

        $path = $this->ssrfProtection->downloadToTemporaryFile($source, $feed->username, $feed->password);

        try {
            clearstatcache(true, $path);

            if (! file_exists($path) || filesize($path) === 0) {
                throw new \RuntimeException('XML feed is empty or unreadable.');
            }

            libxml_use_internal_errors(true);
            $xml = simplexml_load_file($path, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);

            if (! $xml instanceof SimpleXMLElement) {
                $errors = collect(libxml_get_errors())
                    ->map(fn ($error): string => trim($error->message))
                    ->filter()
                    ->implode('; ');

                libxml_clear_errors();

                throw new \RuntimeException($errors ?: 'Invalid XML document.');
            }

            libxml_clear_errors();

            return $xml;
        } finally {
            @unlink($path);
        }
    }

    /**
     * @return array<int, SimpleXMLElement>
     */
    protected function extractRows(SimpleXMLElement $xml, string $rootPath): array
    {
        $xpath = Str::startsWith($rootPath, ['/', '.'])
            ? $rootPath
            : '//'.str_replace('.', '/', trim($rootPath, '.'));

        $rows = $xml->xpath($xpath);

        if ($rows === false || $rows === []) {
            throw new \RuntimeException("No XML rows matched root path [{$rootPath}].");
        }

        return array_values($rows);
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapRow(SimpleXMLElement $row, XmlMappingTemplate $template): array
    {
        $mapped = $template->defaults ?? [];

        foreach ($template->field_map as $targetField => $xmlPath) {
            $value = $this->readValue($row, $xmlPath);

            if ($value !== null || ! array_key_exists($targetField, $mapped)) {
                $mapped[$targetField] = $value;
            }
        }

        foreach (['price', 'quantity'] as $numericField) {
            if (array_key_exists($numericField, $mapped)) {
                $mapped[$numericField] = $this->normalizeNumericValue($mapped[$numericField]);
            }
        }

        $mapped['_raw'] = json_decode(json_encode($row, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        return $mapped;
    }

    protected function readValue(SimpleXMLElement $row, mixed $xmlPath): ?string
    {
        if (is_array($xmlPath)) {
            foreach ($xmlPath as $path) {
                $value = $this->readValue($row, $path);

                if (filled($value)) {
                    return $value;
                }
            }

            return null;
        }

        if (! $xmlPath) {
            return null;
        }

        $xpath = Str::startsWith($xmlPath, ['@', './', './/', '/'])
            ? $xmlPath
            : str_replace('.', '/', $xmlPath);

        $matches = $row->xpath($xpath);

        if ($matches === false || $matches === []) {
            return null;
        }

        $value = trim((string) $matches[0]);

        return $value === '' ? null : $value;
    }

    protected function normalizeNumericValue(mixed $value): mixed
    {
        if (blank($value) || is_numeric($value)) {
            return $value;
        }

        $value = trim(str_replace([' ', "\xc2\xa0"], '', (string) $value));

        if (substr_count($value, ',') === 1 && substr_count($value, '.') === 0) {
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '', $value);
        }

        return is_numeric($value) ? $value : $value;
    }

    protected function supplierProductCategoryName(?string $categoryName): ?string
    {
        if (blank($categoryName)) {
            return null;
        }

        $paths = collect(explode(',', $categoryName))
            ->map(fn (string $path): string => trim($path))
            ->filter()
            ->reject(fn (string $path): bool => in_array(Str::lower($path), ['apcom', 'eol products'], true))
            ->values();

        return $paths->first(fn (string $path): bool => str_contains($path, '>'))
            ?? $paths->first()
            ?? trim($categoryName);
    }

    /**
     * @param  array<string, mixed>  $mapped
     * @return array<string, mixed>
     */
    protected function supplierProductLookup(ImportJob $job, array $mapped): array
    {
        $lookup = [
            'supplier_id' => $job->supplier_id,
            'supplier_feed_id' => $job->supplier_feed_id,
        ];

        foreach (['supplier_sku', 'ean', 'mpn'] as $identifier) {
            if (filled($mapped[$identifier] ?? null)) {
                return $lookup + [$identifier => $mapped[$identifier]];
            }
        }

        return $lookup + [
            'payload_hash' => sha1(json_encode($mapped['_raw'] ?? $mapped, JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * @param  array<string, mixed>  $mapped
     * @return array<int, string>
     */
    protected function validateMappedRow(array $mapped, XmlMappingTemplate $template): array
    {
        $errors = [];

        foreach (($template->validation_rules ?? []) as $field => $rules) {
            $rules = is_array($rules) ? $rules : explode('|', (string) $rules);
            $value = $mapped[$field] ?? null;

            if (in_array('required', $rules, true) && blank($value)) {
                $errors[] = "{$field} is required";
            }

            if (filled($value) && in_array('numeric', $rules, true) && ! is_numeric($value)) {
                $errors[] = "{$field} must be numeric";
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $mapped
     */
    protected function failRow(ImportJob $job, int $rowNumber, array $mapped, string $message): void
    {
        FailedImport::query()->create([
            'import_job_id' => $job->id,
            'supplier_id' => $job->supplier_id,
            'supplier_feed_id' => $job->supplier_feed_id,
            'supplier_sku' => $mapped['supplier_sku'] ?? null,
            'row_number' => $rowNumber,
            'error_type' => 'validation',
            'error_message' => $message,
            'raw_data' => $mapped['_raw'] ?? $mapped,
        ]);

        $job->increment('failed_rows');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function log(ImportJob $job, string $event, string $level, ?string $message = null, array $context = []): void
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

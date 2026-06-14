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

                $supplierProduct = SupplierProduct::query()->create([
                    'supplier_id' => $job->supplier_id,
                    'supplier_feed_id' => $job->supplier_feed_id,
                    'supplier_sku' => $mapped['supplier_sku'] ?? null,
                    'ean' => $mapped['ean'] ?? null,
                    'mpn' => $mapped['mpn'] ?? null,
                    'name' => $mapped['name'] ?? null,
                    'brand_name' => $mapped['brand_name'] ?? null,
                    'category_name' => $mapped['category_name'] ?? null,
                    'price' => $mapped['price'] ?? null,
                    'quantity' => $mapped['quantity'] ?? null,
                    'external_availability_status' => $mapped['external_availability_status'] ?? $mapped['stock_status'] ?? null,
                    'external_availability_label' => $mapped['external_availability_label'] ?? null,
                    'availability_status_id' => $availability?->id,
                    'currency' => $mapped['currency'] ?? 'BGN',
                    'raw_data' => $mapped['_raw'],
                    'payload_hash' => sha1(json_encode($mapped['_raw'], JSON_THROW_ON_ERROR)),
                    'received_at' => now(),
                    'status' => 'new',
                    'mapping_notes' => 'Imported from XML feed into staging. Catalog products are not updated directly.',
                ]);

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
                'status' => $job->failed_rows > 0 ? 'failed' : 'active',
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

        $contents = $this->ssrfProtection->get($source, $feed->username, $feed->password);

        if ($contents === false || trim($contents) === '') {
            throw new \RuntimeException('XML feed is empty or unreadable.');
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($contents);

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
            $mapped[$targetField] = $this->readValue($row, $xmlPath);
        }

        $mapped['_raw'] = json_decode(json_encode($row, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        return $mapped;
    }

    protected function readValue(SimpleXMLElement $row, ?string $xmlPath): ?string
    {
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

        return trim((string) $matches[0]) ?: null;
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

<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;
use App\Models\SupplierProduct;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class AsbisApplyReadinessAuditService
{
    private const DEFAULT_MAX_ROWS = 5000;

    private const DEFAULT_SAMPLE_LIMIT = 20;

    private const MAX_SAMPLE_LIMIT = 100;

    private const SUPPLIER_KEY = 'asbis';

    private const PRODUCT_FIELDS = [
        'supplier_sku' => ['ProductCode', 'ProductID', 'supplier_sku', 'sku', 'code'],
        'ean_gtin' => ['EAN', 'GTIN', 'barcode', 'ean13', 'upc'],
        'mpn' => ['MPN', 'ManufacturerPartNumber', 'manufacturer_sku', 'part_number', 'vendor_part_number'],
        'brand' => ['Vendor', 'Brand', 'brand_name', 'manufacturer'],
        'name' => ['ProductDescription', 'Name', 'product_name', 'title'],
        'category' => ['ProductCategory', 'Category', 'category_name', 'category_path'],
        'image_url' => ['Image', 'Images.Image', 'ImageURL', 'image_url', 'thumbnail'],
        'description' => ['ProductDescription', 'Description', 'full_description', 'long_description'],
    ];

    private const PRICE_FIELDS = [
        'supplier_sku' => ['WIC', 'ProductID', 'supplier_sku', 'ProductCode', 'sku', 'code'],
        'ean_gtin' => ['EAN', 'GTIN', 'barcode', 'ean13', 'upc'],
        'mpn' => ['MPN', 'ManufacturerPartNumber', 'manufacturer_sku', 'part_number', 'vendor_part_number'],
        'brand' => ['VENDOR_NAME', 'Brand', 'brand_name', 'manufacturer'],
        'name' => ['DESCRIPTION', 'Name', 'product_name', 'title'],
        'category' => ['GROUP_NAME', 'Category', 'category_name', 'category_path'],
        'image_url' => ['SMALL_IMAGE', 'Image', 'ImageURL', 'image_url', 'thumbnail'],
        'description' => ['DESCRIPTION', 'Description', 'full_description', 'long_description'],
        'price' => ['MY_PRICE', 'Price', 'supplier_price', 'dealer_price', 'cost', 'net_price'],
        'retail_price' => ['RETAIL_PRICE', 'retail_price'],
        'currency' => ['CURRENCY_CODE', 'Currency', 'currency'],
        'availability' => ['AVAIL', 'Availability', 'availability_status', 'stock_status'],
        'stock' => ['Stock', 'Quantity', 'qty', 'available_quantity', 'AVAIL'],
    ];

    public function __construct(
        private readonly AsbisXmlStreamReader $xmlReader,
        private readonly AsbisStagingCandidatePayloadBuilder $candidatePayloadBuilder,
        private readonly AsbisCandidateFingerprintService $candidateFingerprintService,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function run(array $options): array
    {
        $startedAt = microtime(true);
        $format = strtolower((string) ($options['format'] ?? 'table'));
        $fullFile = (bool) ($options['full_file'] ?? true);
        $requestedMaxRows = max(1, (int) ($options['max_rows'] ?? self::DEFAULT_MAX_ROWS));
        $effectiveLimit = $fullFile ? null : $requestedMaxRows;
        $summaryOnly = (bool) ($options['summary_only'] ?? false);
        $sampleLimit = $this->sampleLimit($options['sample_limit'] ?? self::DEFAULT_SAMPLE_LIMIT);
        $issueSampleLimit = $this->sampleLimit($options['issue_sample_limit'] ?? self::DEFAULT_SAMPLE_LIMIT);
        $mode = (string) ($options['mode'] ?? 'apply_readiness_audit');

        if (! in_array($format, ['table', 'json'], true)) {
            return $this->failure('unsupported_format', 'Unsupported format. Use table or json.', $mode, $startedAt);
        }

        $supplierInput = trim((string) ($options['supplier'] ?? ''));

        if ($supplierInput === '') {
            return $this->failure('supplier_required', 'The --supplier option is required.', $mode, $startedAt);
        }

        $supplier = $this->resolveSupplier($supplierInput);

        if (! $supplier instanceof Supplier) {
            return $this->failure('supplier_not_found', 'Selected supplier was not found.', $mode, $startedAt);
        }

        if ($this->supplierKey($supplier) !== self::SUPPLIER_KEY) {
            return $this->failure('supplier_must_be_asbis', 'The --supplier option must resolve to ASBIS.', $mode, $startedAt, $supplier);
        }

        $productSource = $this->resolveSource(
            (string) ($options['product_list'] ?? ''),
            (string) ($options['product_list_fixture'] ?? ''),
            'ProductList'
        );
        $priceSource = $this->resolveSource(
            (string) ($options['price_avail'] ?? ''),
            (string) ($options['price_avail_fixture'] ?? ''),
            'PriceAvail'
        );

        if (! ($productSource['success'] ?? false)) {
            return $this->failure(
                (string) ($productSource['issue'] ?? 'product_list_source_error'),
                (string) ($productSource['message'] ?? 'ProductList source could not be read.'),
                $mode,
                $startedAt,
                $supplier,
                $productSource,
                $priceSource
            );
        }

        if (! ($priceSource['success'] ?? false)) {
            return $this->failure(
                (string) ($priceSource['issue'] ?? 'price_avail_source_error'),
                (string) ($priceSource['message'] ?? 'PriceAvail source could not be read.'),
                $mode,
                $startedAt,
                $supplier,
                $productSource,
                $priceSource
            );
        }

        try {
            $productSample = $this->xmlReader->read((string) $productSource['path'], 'Product', 50);
            $priceSample = $this->xmlReader->read((string) $priceSource['path'], 'Price', 50);
            $productFieldMap = $this->detectFieldMap($productSample['rows'], self::PRODUCT_FIELDS);
            $priceFieldMap = $this->detectFieldMap($priceSample['rows'], self::PRICE_FIELDS);
            $join = $this->resolveJoin($productSample['rows'], $priceSample['rows'], $productFieldMap, $priceFieldMap, $options);

            if ($join['product_key'] === null || $join['price_key'] === null) {
                return $this->failure(
                    'missing_join_key',
                    'Unable to resolve ProductCode and WIC join keys. Provide valid --product-key and --price-key values.',
                    $mode,
                    $startedAt,
                    $supplier,
                    $productSource,
                    $priceSource,
                    $join
                );
            }

            $priceScan = $this->scanPriceRows(
                (string) $priceSource['path'],
                $priceFieldMap,
                (string) $join['price_key'],
                $effectiveLimit
            );
            $productScan = $this->scanProductRows(
                (string) $productSource['path'],
                $productFieldMap,
                (string) $join['product_key'],
                $effectiveLimit,
                $sampleLimit
            );
        } catch (Throwable) {
            return $this->failure(
                'parse_error',
                'Unable to parse the local ASBIS XML sources safely.',
                $mode,
                $startedAt,
                $supplier,
                $productSource,
                $priceSource
            );
        }

        $rows = $this->joinedRows($productScan, $priceScan);
        $duplicateEans = $this->duplicateGroups($rows, 'ean_gtin');
        $duplicateMpns = $this->duplicateGroups($rows, 'mpn');
        $duplicateBrandMpns = $this->duplicateBrandMpnGroups($rows);
        $existing = $this->existingComparison($supplier, $rows);
        $classifiedRows = collect($rows)
            ->map(fn (array $row): array => $this->classifyRow(
                $row,
                $duplicateEans,
                $duplicateMpns,
                $existing
            ))
            ->values()
            ->all();

        $overlapAudit = $this->overlapAudit($classifiedRows, $existing);
        $readiness = $this->readiness($classifiedRows);
        $issueCounts = $this->issueCounts($classifiedRows);
        $reconciliation = $this->reconciliation($productScan, $priceScan, $classifiedRows, $readiness);

        foreach ($reconciliation['reconciliation_issues'] as $issue) {
            $issueCounts['reconciliation:'.$issue] = ($issueCounts['reconciliation:'.$issue] ?? 0) + 1;
        }

        ksort($issueCounts);
        $verdict = $this->verdict($readiness, $issueCounts, $reconciliation);
        $fullFileCompleted = $fullFile
            && (bool) $productScan['completed']
            && (bool) $priceScan['completed'];
        $fingerprints = [
            'product_list_sha256' => hash_file('sha256', (string) $productSource['path']),
            'price_avail_sha256' => hash_file('sha256', (string) $priceSource['path']),
            'product_list_size_bytes' => filesize((string) $productSource['path']) ?: 0,
            'price_avail_size_bytes' => filesize((string) $priceSource['path']) ?: 0,
            'product_list_modified_at' => $this->modifiedAt((string) $productSource['path']),
            'price_avail_modified_at' => $this->modifiedAt((string) $priceSource['path']),
        ];
        $candidatePayloads = $this->candidatePayloadBuilder->build($classifiedRows, $supplier, $fingerprints);
        $candidateFingerprint = $this->candidateFingerprintService->fingerprint($candidatePayloads);
        $parser = [
            'parser_mode' => 'streaming_xmlreader',
            'requested_mode' => $fullFile ? 'full_file' : 'bounded',
            'effective_scan_mode' => $fullFile ? 'full_file' : 'bounded',
            'effective_row_limit' => $effectiveLimit,
            'requested_max_rows' => $requestedMaxRows,
            'max_rows_overridden' => $fullFile && (bool) ($options['max_rows_explicit'] ?? false),
            'full_file_completed' => $fullFileCompleted,
            'product_list_rows_scanned' => $productScan['rows_scanned'],
            'price_avail_rows_scanned' => $priceScan['rows_scanned'],
            'elapsed_seconds' => round(microtime(true) - $startedAt, 4),
            'peak_memory_bytes' => memory_get_peak_usage(true),
        ];
        $identifierAudit = $this->identifierAudit(
            $productScan,
            $priceScan,
            $classifiedRows,
            $duplicateEans,
            $duplicateMpns,
            $duplicateBrandMpns,
            $existing,
            $overlapAudit
        );
        $summary = $this->summary(
            $supplier,
            $productSource,
            $priceSource,
            $productScan,
            $priceScan,
            $readiness,
            $identifierAudit,
            $overlapAudit,
            $parser,
            $verdict,
            $reconciliation
        );
        $samples = $this->samples($classifiedRows, $summaryOnly ? 0 : $sampleLimit, $summaryOnly ? 0 : $issueSampleLimit);

        return [
            'success' => true,
            'mode' => $mode,
            'supplier' => $this->supplierPayload($supplier),
            'sources' => [
                'product_list' => $this->sourcePayload($productSource),
                'price_avail' => $this->sourcePayload($priceSource),
            ],
            'source_fingerprints' => $fingerprints,
            'parser' => $parser,
            'join' => $join,
            'summary' => $summary,
            'readiness' => [
                ...$readiness,
                ...$verdict,
                'ready_to_create_candidate_count' => count($candidatePayloads),
                'ready_to_create_candidate_set_sha256' => $candidateFingerprint,
                'candidate_payload_schema_version' => AsbisCandidateFingerprintService::SCHEMA_VERSION,
            ],
            'ready_to_create_candidate_count' => count($candidatePayloads),
            'ready_to_create_candidate_set_sha256' => $candidateFingerprint,
            'candidate_payload_schema_version' => AsbisCandidateFingerprintService::SCHEMA_VERSION,
            'reconciliation' => $reconciliation,
            'identifier_audit' => $identifierAudit,
            'availability_audit' => $priceScan['availability_audit'],
            'pricing_audit' => $priceScan['pricing_audit'],
            'category_content_audit' => $productScan['category_content_audit'],
            'overlap_audit' => $overlapAudit,
            'issue_counts' => $issueCounts,
            'issue_samples' => $samples['issue_samples'],
            'ready_samples' => $samples['ready_samples'],
            'manual_review_samples' => $samples['manual_review_samples'],
            'unmatched_product_samples' => $samples['unmatched_product_samples'],
            'unmatched_price_samples' => $samples['unmatched_price_samples'],
            'detected_product_fields' => [
                'raw_field_names' => $productScan['raw_fields'],
                'normalized_field_map' => $productFieldMap,
            ],
            'detected_price_fields' => [
                'raw_field_names' => $priceScan['raw_fields'],
                'normalized_field_map' => $priceFieldMap,
            ],
            'identifier_summary' => $identifierAudit,
            'category_summary' => $productScan['category_content_audit'],
            'price_stock_summary' => [
                ...$priceScan['pricing_audit'],
                ...$priceScan['availability_audit'],
            ],
            'joined_rows' => $summaryOnly ? [] : array_slice(array_map(fn (array $row): array => $this->sampleRow($row), $classifiedRows), 0, $sampleLimit),
            'unmatched_product_rows' => $samples['unmatched_product_samples'],
            'unmatched_price_rows' => $samples['unmatched_price_samples'],
            'overlaps' => $samples['overlap_samples'],
            'issues' => $samples['issue_samples'],
            'records_changed' => $this->recordsChanged(),
            ...((bool) ($options['include_candidate_payloads'] ?? false) ? [
                'candidate_payloads' => $candidatePayloads,
                'source_paths' => [
                    'product_list' => (string) $productSource['path'],
                    'price_avail' => (string) $priceSource['path'],
                ],
            ] : []),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function recordsChanged(): array
    {
        return [
            'products' => 0,
            'supplier_products' => 0,
            'categories' => 0,
            'suppliers' => 0,
            'supplier_category_mappings' => 0,
            'canonical_product_families' => 0,
            'category_product_attributes' => 0,
            'product_attributes' => 0,
            'attribute_values' => 0,
            'product_attribute_values' => 0,
            'catalog_sync' => 0,
        ];
    }

    /**
     * @param  array<string, string|null>  $fieldMap
     * @return array<string, mixed>
     */
    private function scanPriceRows(string $path, array $fieldMap, string $joinKey, ?int $limit): array
    {
        $index = [];
        $missingKeyRows = [];
        $keyPresentRows = 0;
        $keyMissingRows = 0;
        $indexedRows = 0;
        $availabilityCounts = [
            'in_stock' => 0,
            'limited_stock' => 0,
            'on_request' => 0,
            'unknown_availability' => 0,
            'missing_availability' => 0,
        ];
        $rawAvailabilityCounts = [];
        $pricing = [
            'valid_my_price_count' => 0,
            'retail_price_fallback_count' => 0,
            'missing_price_count' => 0,
            'invalid_price_count' => 0,
            'missing_currency_count' => 0,
            'non_eur_currency_count' => 0,
        ];
        $currencies = [];

        $result = $this->xmlReader->scan($path, 'Price', function (array $row, int $rowNumber) use (
            &$index,
            &$missingKeyRows,
            &$keyPresentRows,
            &$keyMissingRows,
            &$indexedRows,
            &$availabilityCounts,
            &$rawAvailabilityCounts,
            &$pricing,
            &$currencies,
            $fieldMap,
            $joinKey
        ): void {
            $normalized = $this->normalizePriceRow($row, $fieldMap, $joinKey, $rowNumber);
            $key = $normalized['normalized_join_key'];

            if ($key === null) {
                $keyMissingRows++;
                $missingKeyRows[] = $normalized;
            } else {
                $keyPresentRows++;
                $indexedRows++;
                $this->addIndexRow($index, $key, $normalized, ['price', 'currency', 'availability', 'ean_gtin']);
            }

            $availabilityKey = $normalized['availability'] ?? null;

            if (is_string($availabilityKey) && array_key_exists($availabilityKey, $availabilityCounts)) {
                $availabilityCounts[$availabilityKey]++;
            } elseif ($normalized['raw_availability'] === null) {
                $availabilityCounts['missing_availability']++;
            } else {
                $availabilityCounts['unknown_availability']++;
            }

            $rawLabel = $normalized['raw_availability'] ?? '(missing)';
            $rawAvailabilityCounts[$rawLabel] = ($rawAvailabilityCounts[$rawLabel] ?? 0) + 1;
            $priceSource = $normalized['price_source'];

            if ($priceSource === 'MY_PRICE') {
                $pricing['valid_my_price_count']++;
            } elseif ($priceSource === 'RETAIL_PRICE') {
                $pricing['retail_price_fallback_count']++;
            } elseif ($normalized['price_issue'] === 'missing_price') {
                $pricing['missing_price_count']++;
            } else {
                $pricing['invalid_price_count']++;
            }

            $currency = $normalized['currency'];

            if ($currency === null) {
                $pricing['missing_currency_count']++;
            } else {
                $currencies[$currency] = ($currencies[$currency] ?? 0) + 1;

                if ($currency !== 'EUR') {
                    $pricing['non_eur_currency_count']++;
                }
            }
        }, $limit);

        ksort($rawAvailabilityCounts);
        ksort($currencies);

        return [
            ...$result,
            'index' => $index,
            'missing_key_rows' => $missingKeyRows,
            'key_audit' => [
                'key_name' => $joinKey,
                'present_rows' => $keyPresentRows,
                'missing_rows' => $keyMissingRows,
                'indexed_rows' => $indexedRows,
                'unique_values' => count($index),
                'malformed_rows' => 0,
            ],
            'availability_audit' => [
                'normalized_in_stock_count' => $availabilityCounts['in_stock'],
                'normalized_limited_stock_count' => $availabilityCounts['limited_stock'],
                'normalized_on_request_count' => $availabilityCounts['on_request'],
                'unknown_availability_count' => $availabilityCounts['unknown_availability'],
                'missing_availability_count' => $availabilityCounts['missing_availability'],
                'raw_availability_value_counts' => $rawAvailabilityCounts,
            ],
            'pricing_audit' => [
                ...$pricing,
                'currencies' => $currencies,
            ],
        ];
    }

    /**
     * @param  array<string, string|null>  $fieldMap
     * @return array<string, mixed>
     */
    private function scanProductRows(string $path, array $fieldMap, string $joinKey, ?int $limit, int $sampleLimit): array
    {
        $index = [];
        $missingKeyRows = [];
        $keyPresentRows = 0;
        $keyMissingRows = 0;
        $indexedRows = 0;
        $coverage = [
            'category_present' => 0,
            'category_missing' => 0,
            'brand_present' => 0,
            'brand_missing' => 0,
            'name_present' => 0,
            'name_missing' => 0,
            'description_present' => 0,
            'description_missing' => 0,
            'image_url_present' => 0,
            'image_url_missing' => 0,
        ];
        $categories = [];
        $imageHosts = [];

        $result = $this->xmlReader->scan($path, 'Product', function (array $row, int $rowNumber) use (
            &$index,
            &$missingKeyRows,
            &$keyPresentRows,
            &$keyMissingRows,
            &$indexedRows,
            &$coverage,
            &$categories,
            &$imageHosts,
            $fieldMap,
            $joinKey,
            $sampleLimit
        ): void {
            $normalized = $this->normalizeProductRow($row, $fieldMap, $joinKey, $rowNumber);
            $key = $normalized['normalized_join_key'];

            if ($key === null) {
                $keyMissingRows++;
                $missingKeyRows[] = $normalized;
            } else {
                $keyPresentRows++;
                $indexedRows++;
                $this->addIndexRow($index, $key, $normalized, ['ean_gtin', 'mpn', 'brand', 'name', 'category']);
            }

            foreach (['category', 'brand', 'name'] as $field) {
                $coverage[$field.'_'.($normalized[$field] === null ? 'missing' : 'present')]++;
            }

            foreach (['description', 'image_url'] as $field) {
                $present = (bool) $normalized[$field.'_present'];
                $coverage[$field.'_'.($present ? 'present' : 'missing')]++;
            }

            if ($normalized['category'] !== null) {
                $categories[$normalized['category']] = ($categories[$normalized['category']] ?? 0) + 1;
            }

            if ($normalized['image_url_host'] !== null && count($imageHosts) < $sampleLimit) {
                $imageHosts[$normalized['image_url_host']] = true;
            }
        }, $limit);

        arsort($categories);

        return [
            ...$result,
            'index' => $index,
            'missing_key_rows' => $missingKeyRows,
            'key_audit' => [
                'key_name' => $joinKey,
                'present_rows' => $keyPresentRows,
                'missing_rows' => $keyMissingRows,
                'indexed_rows' => $indexedRows,
                'unique_values' => count($index),
                'malformed_rows' => 0,
            ],
            'category_content_audit' => [
                ...$coverage,
                'distinct_supplier_categories' => count($categories),
                'top_supplier_categories' => array_slice($categories, 0, 20, true),
                'image_host_samples' => array_keys($imageHosts),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $productScan
     * @param  array<string, mixed>  $priceScan
     * @return array<int, array<string, mixed>>
     */
    private function joinedRows(array $productScan, array $priceScan): array
    {
        $productIndex = $productScan['index'];
        $priceIndex = $priceScan['index'];
        $keys = array_values(array_unique([...array_keys($productIndex), ...array_keys($priceIndex)]));
        $rows = [];

        foreach ($keys as $key) {
            $product = $productIndex[$key]['row'] ?? null;
            $price = $priceIndex[$key]['row'] ?? null;

            $rows[] = [
                'join_key' => $key,
                'supplier_sku' => $product['supplier_sku'] ?? $price['supplier_sku'] ?? $key,
                'ean_gtin' => $price['ean_gtin'] ?? $product['ean_gtin'] ?? null,
                'mpn' => $product['mpn'] ?? $price['mpn'] ?? null,
                'brand' => $product['brand'] ?? $price['brand'] ?? null,
                'name' => $price['name'] ?? $product['name'] ?? null,
                'category' => $product['category'] ?? $price['category'] ?? null,
                'price' => $price['price'] ?? null,
                'price_source' => $price['price_source'] ?? null,
                'price_issue' => $price !== null ? $price['price_issue'] : 'missing_price',
                'currency' => $price['currency'] ?? null,
                'stock' => $price['stock'] ?? null,
                'availability' => $price['availability'] ?? null,
                'raw_availability' => $price['raw_availability'] ?? null,
                'description_present' => (bool) (($product['description_present'] ?? false) || ($price['description_present'] ?? false)),
                'image_url_present' => (bool) (($product['image_url_present'] ?? false) || ($price['image_url_present'] ?? false)),
                'image_url_host' => $product['image_url_host'] ?? $price['image_url_host'] ?? null,
                'product_list_present' => $product !== null,
                'price_avail_present' => $price !== null,
                'missing_product_code' => false,
                'missing_wic' => false,
                'missing_join_key' => null,
                'duplicate_product_code' => ($productIndex[$key]['count'] ?? 0) > 1,
                'duplicate_wic' => ($priceIndex[$key]['count'] ?? 0) > 1,
                'conflicting_product_rows' => (bool) ($productIndex[$key]['conflicting'] ?? false),
                'conflicting_price_rows' => (bool) ($priceIndex[$key]['conflicting'] ?? false),
                'product_row_number' => $product['row_number'] ?? null,
                'price_row_number' => $price['row_number'] ?? null,
            ];
        }

        foreach ($productScan['missing_key_rows'] as $index => $product) {
            $rows[] = $this->missingKeyRow($product, null, 'product-'.$index);
        }

        foreach ($priceScan['missing_key_rows'] as $index => $price) {
            $rows[] = $this->missingKeyRow(null, $price, 'price-'.$index);
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>|null  $product
     * @param  array<string, mixed>|null  $price
     * @return array<string, mixed>
     */
    private function missingKeyRow(?array $product, ?array $price, string $suffix): array
    {
        return [
            'join_key' => '__missing_'.$suffix,
            'supplier_sku' => null,
            'ean_gtin' => $price['ean_gtin'] ?? $product['ean_gtin'] ?? null,
            'mpn' => $product['mpn'] ?? $price['mpn'] ?? null,
            'brand' => $product['brand'] ?? $price['brand'] ?? null,
            'name' => $price['name'] ?? $product['name'] ?? null,
            'category' => $product['category'] ?? $price['category'] ?? null,
            'price' => $price['price'] ?? null,
            'price_source' => $price['price_source'] ?? null,
            'price_issue' => $price['price_issue'] ?? 'missing_price',
            'currency' => $price['currency'] ?? null,
            'stock' => $price['stock'] ?? null,
            'availability' => $price['availability'] ?? null,
            'raw_availability' => $price['raw_availability'] ?? null,
            'description_present' => (bool) (($product['description_present'] ?? false) || ($price['description_present'] ?? false)),
            'image_url_present' => (bool) (($product['image_url_present'] ?? false) || ($price['image_url_present'] ?? false)),
            'image_url_host' => $product['image_url_host'] ?? $price['image_url_host'] ?? null,
            'product_list_present' => $product !== null,
            'price_avail_present' => $price !== null,
            'missing_product_code' => $product !== null,
            'missing_wic' => $price !== null,
            'missing_join_key' => $product !== null ? 'missing_product_code' : 'missing_wic',
            'duplicate_product_code' => false,
            'duplicate_wic' => false,
            'conflicting_product_rows' => false,
            'conflicting_price_rows' => false,
            'product_row_number' => $product['row_number'] ?? null,
            'price_row_number' => $price['row_number'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, array<int, int>>  $duplicateEans
     * @param  array<string, array<int, int>>  $duplicateMpns
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    private function classifyRow(array $row, array $duplicateEans, array $duplicateMpns, array $existing): array
    {
        $issues = [];
        $warnings = [];
        $normalizedSku = $this->normalizeIdentifier($row['supplier_sku']);
        $ean = $this->normalizeIdentifier($row['ean_gtin']);
        $mpn = $this->normalizeIdentifier($row['mpn']);

        if (($row['missing_product_code'] ?? false) || ($row['missing_wic'] ?? false)) {
            if ($row['missing_product_code'] ?? false) {
                $issues[] = 'missing_product_code';
            }

            if ($row['missing_wic'] ?? false) {
                $issues[] = 'missing_wic';
            }

            $issues[] = 'missing_supplier_sku';

            if ($row['name'] === null) {
                $issues[] = 'missing_name';
            }

            if ($row['price_issue'] !== null) {
                $issues[] = $row['price_issue'];
            }

            $row['readiness_state'] = 'blocked';
            $row['issues'] = array_values(array_unique($issues));
            $row['warnings'] = [];
            $row['cross_supplier_overlap_types'] = [];
            $row['same_supplier_sku_match'] = false;
            $row['future_staging_action'] = 'would_skip_row';

            return $row;
        }

        if (! $row['product_list_present'] && $row['price_avail_present']) {
            $row['readiness_state'] = $row['duplicate_wic'] ? 'blocked' : 'price_only';
            $row['issues'] = $row['duplicate_wic'] ? ['price_avail_only', 'duplicate_wic'] : ['price_avail_only'];
            $row['warnings'] = [];
            $row['future_staging_action'] = $row['duplicate_wic'] ? 'would_skip_row' : 'would_need_manual_review';

            return $row;
        }

        if ($row['product_list_present'] && ! $row['price_avail_present']) {
            $row['readiness_state'] = $row['duplicate_product_code'] ? 'blocked' : 'product_only';
            $row['issues'] = $row['duplicate_product_code'] ? ['product_list_only', 'duplicate_product_code'] : ['product_list_only'];
            $row['warnings'] = [];
            $row['future_staging_action'] = $row['duplicate_product_code'] ? 'would_skip_row' : 'would_need_manual_review';

            return $row;
        }

        if ($normalizedSku === null) {
            $issues[] = 'missing_supplier_sku';
        }

        if ($row['duplicate_product_code']) {
            $issues[] = 'duplicate_product_code';
        }

        if ($row['duplicate_wic']) {
            $issues[] = 'duplicate_wic';
        }

        if ($row['conflicting_product_rows'] || $row['conflicting_price_rows']) {
            $issues[] = 'conflicting_duplicate_rows';
        }

        if ($row['name'] === null) {
            $issues[] = 'missing_name';
        }

        if ($row['price_issue'] !== null) {
            $issues[] = $row['price_issue'];
        }

        if ($row['currency'] === null) {
            $issues[] = 'missing_currency';
        }

        if ($row['raw_availability'] === null) {
            $issues[] = 'missing_availability';
        } elseif ($row['availability'] === null) {
            $issues[] = 'unknown_availability';
        }

        if ($ean === null && $mpn === null) {
            $issues[] = 'missing_ean_and_mpn';
        } elseif ($ean === null) {
            $warnings[] = 'missing_ean_with_mpn';
        }

        if ($ean !== null && array_key_exists($ean, $duplicateEans)) {
            $warnings[] = 'duplicate_ean_across_asbis_skus';
        }

        if ($mpn !== null && array_key_exists($mpn, $duplicateMpns)) {
            $warnings[] = 'duplicate_mpn_across_asbis_skus';
        }

        $crossOverlapTypes = [];

        if ($ean !== null && isset($existing['cross_supplier_eans'][$ean])) {
            $crossOverlapTypes[] = 'ean';
        }

        if ($mpn !== null && isset($existing['cross_supplier_mpns'][$mpn])) {
            $crossOverlapTypes[] = 'mpn';
        }

        $brandMpn = $this->brandMpnKey($row['brand'], $row['mpn']);

        if ($brandMpn !== null && isset($existing['cross_supplier_brand_mpns'][$brandMpn])) {
            $crossOverlapTypes[] = 'brand_mpn';
        }

        if ($crossOverlapTypes !== []) {
            $warnings[] = 'cross_supplier_identifier_overlap';
        }

        $blockingIssues = array_intersect($issues, [
            'missing_supplier_sku',
            'missing_name',
            'missing_price',
            'invalid_price',
            'duplicate_product_code',
            'duplicate_wic',
            'conflicting_duplicate_rows',
        ]);
        $manualIssues = array_diff($issues, $blockingIssues);
        $exists = $normalizedSku !== null && isset($existing['same_supplier_skus'][$normalizedSku]);

        if ($blockingIssues !== []) {
            $state = 'blocked';
            $futureAction = 'would_skip_row';
        } elseif ($manualIssues !== []) {
            $state = 'manual_review';
            $futureAction = 'would_need_manual_review';
        } elseif ($warnings !== []) {
            $state = 'ready_with_warning';
            $futureAction = $exists ? 'would_update_supplier_product' : 'would_create_supplier_product';
        } else {
            $state = $exists ? 'ready_to_update' : 'ready_to_create';
            $futureAction = $exists ? 'would_update_supplier_product' : 'would_create_supplier_product';
        }

        $row['readiness_state'] = $state;
        $row['issues'] = array_values(array_unique($issues));
        $row['warnings'] = array_values(array_unique($warnings));
        $row['cross_supplier_overlap_types'] = $crossOverlapTypes;
        $row['same_supplier_sku_match'] = $exists;
        $row['future_staging_action'] = $futureAction;

        return $row;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function existingComparison(Supplier $supplier, array $rows): array
    {
        if (! Schema::hasTable('supplier_products')) {
            return [
                'same_supplier_skus' => [],
                'cross_supplier_eans' => [],
                'cross_supplier_mpns' => [],
                'cross_supplier_brand_mpns' => [],
            ];
        }

        $sameSupplierSkus = [];

        SupplierProduct::query()
            ->where('supplier_id', $supplier->getKey())
            ->whereNotNull('supplier_sku')
            ->pluck('supplier_sku')
            ->each(function (mixed $sku) use (&$sameSupplierSkus): void {
                $normalized = $this->normalizeIdentifier($sku);

                if ($normalized !== null) {
                    $sameSupplierSkus[$normalized] = true;
                }
            });
        $otherRows = SupplierProduct::query()
            ->where('supplier_id', '!=', $supplier->getKey())
            ->get(['ean', 'mpn', 'brand_name']);
        $crossEans = [];
        $crossMpns = [];
        $crossBrandMpns = [];

        foreach ($otherRows as $other) {
            $ean = $this->normalizeIdentifier($other->ean);
            $mpn = $this->normalizeIdentifier($other->mpn);
            $brandMpn = $this->brandMpnKey($other->brand_name, $other->mpn);

            if ($ean !== null) {
                $crossEans[$ean] = true;
            }

            if ($mpn !== null) {
                $crossMpns[$mpn] = true;
            }

            if ($brandMpn !== null) {
                $crossBrandMpns[$brandMpn] = true;
            }
        }

        return [
            'same_supplier_skus' => $sameSupplierSkus,
            'cross_supplier_eans' => $crossEans,
            'cross_supplier_mpns' => $crossMpns,
            'cross_supplier_brand_mpns' => $crossBrandMpns,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function readiness(array $rows): array
    {
        $counts = array_fill_keys([
            'ready_to_create',
            'ready_to_update',
            'ready_with_warning',
            'manual_review',
            'blocked',
            'product_only',
            'price_only',
        ], 0);

        foreach ($rows as $row) {
            $state = $row['readiness_state'];
            $counts[$state]++;
        }

        $counts['would_create'] = collect($rows)->where('future_staging_action', 'would_create_supplier_product')->count();
        $counts['would_update'] = collect($rows)->where('future_staging_action', 'would_update_supplier_product')->count();
        $counts['hard_blocker_count'] = $counts['blocked'];
        $counts['manual_review_count'] = $counts['manual_review'];
        $counts['unmatched_count'] = $counts['product_only'] + $counts['price_only'];
        $counts['apply_excluded_count'] = $counts['blocked'] + $counts['manual_review'] + $counts['unmatched_count'];
        $counts['apply_blocker_count'] = $counts['apply_excluded_count'];

        return $counts;
    }

    /**
     * @param  array<string, int>  $readiness
     * @param  array<string, int>  $issueCounts
     * @return array<string, mixed>
     */
    private function verdict(array $readiness, array $issueCounts, array $reconciliation): array
    {
        $candidateCount = $readiness['ready_to_create'] + $readiness['ready_to_update'] + $readiness['ready_with_warning'];
        $blockerCount = $readiness['apply_excluded_count'];
        $duplicateJoinKeys = ($issueCounts['duplicate_product_code'] ?? 0) + ($issueCounts['duplicate_wic'] ?? 0);

        if (! $reconciliation['reconciliation_valid'] || $candidateCount === 0 || $duplicateJoinKeys > 0) {
            $verdict = 'not_ready_for_controlled_staging_apply';
        } elseif ($blockerCount > 0 || $readiness['ready_with_warning'] > 0) {
            $verdict = 'ready_with_warnings';
        } else {
            $verdict = 'ready_for_controlled_staging_apply';
        }

        return [
            'verdict' => $verdict,
            'apply_candidate_count' => $candidateCount,
            'apply_blocker_count' => $blockerCount,
            'hard_blocker_count' => $readiness['hard_blocker_count'],
            'manual_review_count' => $readiness['manual_review_count'],
            'unmatched_count' => $readiness['unmatched_count'],
            'apply_excluded_count' => $readiness['apply_excluded_count'],
            'reconciliation_valid' => $reconciliation['reconciliation_valid'],
            'blocker_reasons' => collect($issueCounts)
                ->filter(fn (int $count, string $reason): bool => $count > 0 && ! str_starts_with($reason, 'warning:'))
                ->all(),
            'warning_reasons' => collect($issueCounts)
                ->filter(fn (int $count, string $reason): bool => $count > 0 && str_starts_with($reason, 'warning:'))
                ->mapWithKeys(fn (int $count, string $reason): array => [Str::after($reason, 'warning:') => $count])
                ->all(),
            'advisory_only' => true,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function issueCounts(array $rows): array
    {
        $counts = [];

        foreach ($rows as $row) {
            foreach ($row['issues'] as $issue) {
                $counts[$issue] = ($counts[$issue] ?? 0) + 1;
            }

            foreach ($row['warnings'] as $warning) {
                $key = 'warning:'.$warning;
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function samples(array $rows, int $sampleLimit, int $issueSampleLimit): array
    {
        $collection = collect($rows);

        return [
            'issue_samples' => $collection
                ->filter(fn (array $row): bool => $row['issues'] !== [] || $row['warnings'] !== [])
                ->take($issueSampleLimit)
                ->map(fn (array $row): array => $this->sampleRow($row))
                ->values()
                ->all(),
            'ready_samples' => $collection
                ->whereIn('readiness_state', ['ready_to_create', 'ready_to_update', 'ready_with_warning'])
                ->take($sampleLimit)
                ->map(fn (array $row): array => $this->sampleRow($row))
                ->values()
                ->all(),
            'manual_review_samples' => $collection
                ->whereIn('readiness_state', ['manual_review', 'blocked'])
                ->take($sampleLimit)
                ->map(fn (array $row): array => $this->sampleRow($row))
                ->values()
                ->all(),
            'unmatched_product_samples' => $collection
                ->filter(fn (array $row): bool => $row['product_list_present'] && ! $row['price_avail_present'] && ! ($row['missing_product_code'] ?? false))
                ->take($sampleLimit)
                ->map(fn (array $row): array => $this->sampleRow($row))
                ->values()
                ->all(),
            'unmatched_price_samples' => $collection
                ->filter(fn (array $row): bool => ! $row['product_list_present'] && $row['price_avail_present'] && ! ($row['missing_wic'] ?? false))
                ->take($sampleLimit)
                ->map(fn (array $row): array => $this->sampleRow($row))
                ->values()
                ->all(),
            'overlap_samples' => $collection
                ->filter(fn (array $row): bool => ($row['cross_supplier_overlap_types'] ?? []) !== [])
                ->take($sampleLimit)
                ->map(fn (array $row): array => $this->sampleRow($row))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function sampleRow(array $row): array
    {
        return [
            'supplier_sku' => $row['supplier_sku'],
            'ean_gtin' => $row['ean_gtin'],
            'mpn' => $row['mpn'],
            'brand' => $row['brand'],
            'name' => $row['name'],
            'category' => $row['category'],
            'price' => $row['price'],
            'currency' => $row['currency'],
            'stock' => $row['stock'],
            'availability' => $row['availability'],
            'raw_availability' => $row['raw_availability'],
            'readiness_state' => $row['readiness_state'],
            'future_staging_action' => $row['future_staging_action'],
            'product_list_present' => $row['product_list_present'],
            'price_avail_present' => $row['price_avail_present'],
            'missing_product_code' => $row['missing_product_code'] ?? false,
            'missing_wic' => $row['missing_wic'] ?? false,
            'missing_join_key' => $row['missing_join_key'] ?? null,
            'issues' => $row['issues'],
            'warnings' => $row['warnings'],
            'cross_supplier_overlap_types' => $row['cross_supplier_overlap_types'] ?? [],
            'description_present' => $row['description_present'],
            'image_url_present' => $row['image_url_present'],
            'image_url_host' => $row['image_url_host'],
        ];
    }

    /**
     * @param  array<string, mixed>  $productScan
     * @param  array<string, mixed>  $priceScan
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, array<int, int>>  $duplicateEans
     * @param  array<string, array<int, int>>  $duplicateMpns
     * @param  array<string, array<int, int>>  $duplicateBrandMpns
     * @param  array<string, mixed>  $existing
     * @param  array<string, int>  $overlapAudit
     * @return array<string, mixed>
     */
    private function identifierAudit(array $productScan, array $priceScan, array $rows, array $duplicateEans, array $duplicateMpns, array $duplicateBrandMpns, array $existing, array $overlapAudit): array
    {
        $joined = collect($rows)->filter(fn (array $row): bool => $row['product_list_present'] && $row['price_avail_present'] && ! ($row['missing_product_code'] ?? false) && ! ($row['missing_wic'] ?? false));
        $eanPresent = collect($rows)->filter(fn (array $row): bool => $this->normalizeIdentifier($row['ean_gtin']) !== null)->count();
        $mpnPresent = collect($rows)->filter(fn (array $row): bool => $this->normalizeIdentifier($row['mpn']) !== null)->count();
        $productKeyAudit = $productScan['key_audit'];
        $priceKeyAudit = $priceScan['key_audit'];

        return [
            'product_code_rows' => $productScan['rows_scanned'],
            'product_code_present_rows' => $productKeyAudit['present_rows'],
            'product_code_missing_rows' => $productKeyAudit['missing_rows'],
            'unique_product_code_values' => $productKeyAudit['unique_values'],
            'duplicate_product_code_keys' => collect($productScan['index'])->where('count', '>', 1)->count(),
            'duplicate_product_code_affected_rows' => collect($productScan['index'])->where('count', '>', 1)->sum('count'),
            'wic_rows' => $priceScan['rows_scanned'],
            'wic_present_rows' => $priceKeyAudit['present_rows'],
            'wic_missing_rows' => $priceKeyAudit['missing_rows'],
            'unique_wic_values' => $priceKeyAudit['unique_values'],
            'duplicate_wic_keys' => collect($priceScan['index'])->where('count', '>', 1)->count(),
            'duplicate_wic_affected_rows' => collect($priceScan['index'])->where('count', '>', 1)->sum('count'),
            'joined_unique_keys' => $joined->count(),
            'product_list_only_keys' => collect($rows)->filter(fn (array $row): bool => $row['product_list_present'] && ! $row['price_avail_present'] && ! ($row['missing_product_code'] ?? false))->count(),
            'price_avail_only_keys' => collect($rows)->filter(fn (array $row): bool => ! $row['product_list_present'] && $row['price_avail_present'] && ! ($row['missing_wic'] ?? false))->count(),
            'ean_present' => $eanPresent,
            'ean_missing' => count($rows) - $eanPresent,
            'duplicate_ean_groups' => count($duplicateEans),
            'duplicate_ean_affected_rows' => array_sum(array_map('count', $duplicateEans)),
            'mpn_present' => $mpnPresent,
            'mpn_missing' => count($rows) - $mpnPresent,
            'duplicate_mpn_groups' => count($duplicateMpns),
            'duplicate_mpn_affected_rows' => array_sum(array_map('count', $duplicateMpns)),
            'brand_mpn_duplicate_groups' => count($duplicateBrandMpns),
            'brand_mpn_duplicate_affected_rows' => array_sum(array_map('count', $duplicateBrandMpns)),
            'same_asbis_supplier_sku_matches' => collect($rows)->where('same_supplier_sku_match', true)->count(),
            'would_create_count' => collect($rows)->where('future_staging_action', 'would_create_supplier_product')->count(),
            'would_update_count' => collect($rows)->where('future_staging_action', 'would_update_supplier_product')->count(),
            'cross_supplier_ean_overlap_groups' => $overlapAudit['ean_overlap_groups'],
            'cross_supplier_ean_overlap_affected_rows' => $overlapAudit['ean_overlap_affected_rows'],
            'cross_supplier_mpn_overlap_groups' => $overlapAudit['mpn_overlap_groups'],
            'cross_supplier_mpn_overlap_affected_rows' => $overlapAudit['mpn_overlap_affected_rows'],
            'cross_supplier_brand_mpn_overlap_groups' => $overlapAudit['brand_mpn_overlap_groups'],
            'cross_supplier_brand_mpn_overlap_affected_rows' => $overlapAudit['brand_mpn_overlap_affected_rows'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $existing
     * @return array<string, int>
     */
    private function overlapAudit(array $rows, array $existing): array
    {
        $groups = [
            'ean' => [],
            'mpn' => [],
            'brand_mpn' => [],
        ];

        foreach ($rows as $rowIndex => $row) {
            $normalizedEan = $this->normalizeIdentifier($row['ean_gtin']);
            $normalizedMpn = $this->normalizeIdentifier($row['mpn']);
            $normalizedBrandMpn = $this->brandMpnKey($row['brand'], $row['mpn']);

            if ($normalizedEan !== null && isset($existing['cross_supplier_eans'][$normalizedEan])) {
                $groups['ean'][$normalizedEan][] = $rowIndex;
            }

            if ($normalizedMpn !== null && isset($existing['cross_supplier_mpns'][$normalizedMpn])) {
                $groups['mpn'][$normalizedMpn][] = $rowIndex;
            }

            if ($normalizedBrandMpn !== null && isset($existing['cross_supplier_brand_mpns'][$normalizedBrandMpn])) {
                $groups['brand_mpn'][$normalizedBrandMpn][] = $rowIndex;
            }
        }

        return [
            'ean_overlap_groups' => count($groups['ean']),
            'ean_overlap_affected_rows' => array_sum(array_map('count', $groups['ean'])),
            'mpn_overlap_groups' => count($groups['mpn']),
            'mpn_overlap_affected_rows' => array_sum(array_map('count', $groups['mpn'])),
            'brand_mpn_overlap_groups' => count($groups['brand_mpn']),
            'brand_mpn_overlap_affected_rows' => array_sum(array_map('count', $groups['brand_mpn'])),
            'total_overlap_groups' => count($groups['ean']) + count($groups['mpn']) + count($groups['brand_mpn']),
            'report_only' => 1,
        ];
    }

    /**
     * Reconciles physical source rows, indexed keys, joined keys, and the
     * mutually-exclusive readiness buckets before the audit can be considered
     * internally consistent.
     *
     * @param  array<string, mixed>  $productScan
     * @param  array<string, mixed>  $priceScan
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $readiness
     * @return array<string, mixed>
     */
    private function reconciliation(array $productScan, array $priceScan, array $rows, array $readiness): array
    {
        $productKeyAudit = $productScan['key_audit'];
        $priceKeyAudit = $priceScan['key_audit'];
        $identifierRows = collect($rows);
        $joined = $identifierRows->filter(fn (array $row): bool => $row['product_list_present'] && $row['price_avail_present'] && ! ($row['missing_product_code'] ?? false) && ! ($row['missing_wic'] ?? false))->count();
        $productOnly = $identifierRows->filter(fn (array $row): bool => $row['product_list_present'] && ! $row['price_avail_present'] && ! ($row['missing_product_code'] ?? false))->count();
        $priceOnly = $identifierRows->filter(fn (array $row): bool => ! $row['product_list_present'] && $row['price_avail_present'] && ! ($row['missing_wic'] ?? false))->count();
        $classifiedBucketCount = array_sum(array_intersect_key($readiness, array_fill_keys([
            'ready_to_create',
            'ready_to_update',
            'ready_with_warning',
            'manual_review',
            'blocked',
            'product_only',
            'price_only',
        ], true)));
        $issues = [];

        if ($productScan['rows_scanned'] !== $productKeyAudit['indexed_rows'] + $productKeyAudit['missing_rows'] + $productKeyAudit['malformed_rows']) {
            $issues[] = 'product_list_rows_not_reconciled';
        }

        if ($priceScan['rows_scanned'] !== $priceKeyAudit['indexed_rows'] + $priceKeyAudit['missing_rows'] + $priceKeyAudit['malformed_rows']) {
            $issues[] = 'price_avail_rows_not_reconciled';
        }

        if ($productKeyAudit['unique_values'] !== $joined + $productOnly) {
            $issues[] = 'product_list_keys_not_reconciled';
        }

        if ($priceKeyAudit['unique_values'] !== $joined + $priceOnly) {
            $issues[] = 'price_avail_keys_not_reconciled';
        }

        if (count($rows) !== $classifiedBucketCount) {
            $issues[] = 'readiness_buckets_not_reconciled';
        }

        return [
            'reconciliation_valid' => $issues === [],
            'reconciliation_issues' => $issues,
            'classification_basis' => 'unique_join_key_buckets_plus_missing_key_rows',
            'joined_unique_keys' => $joined,
            'valid_product_only_keys' => $productOnly,
            'valid_price_only_keys' => $priceOnly,
            'missing_product_code_rows' => $productKeyAudit['missing_rows'],
            'missing_wic_rows' => $priceKeyAudit['missing_rows'],
            'duplicate_product_code_affected_rows' => collect($productScan['index'])->where('count', '>', 1)->sum('count'),
            'duplicate_wic_affected_rows' => collect($priceScan['index'])->where('count', '>', 1)->sum('count'),
            'malformed_product_list_rows' => $productKeyAudit['malformed_rows'],
            'malformed_price_avail_rows' => $priceKeyAudit['malformed_rows'],
            'product_list' => [
                'rows' => $productScan['rows_scanned'],
                'indexed_rows' => $productKeyAudit['indexed_rows'],
                'missing_key_rows' => $productKeyAudit['missing_rows'],
                'malformed_rows' => $productKeyAudit['malformed_rows'],
                'unique_keys' => $productKeyAudit['unique_values'],
                'rows_reconciled' => $productScan['rows_scanned'] === $productKeyAudit['indexed_rows'] + $productKeyAudit['missing_rows'] + $productKeyAudit['malformed_rows'],
                'keys_reconciled' => $productKeyAudit['unique_values'] === $joined + $productOnly,
            ],
            'price_avail' => [
                'rows' => $priceScan['rows_scanned'],
                'indexed_rows' => $priceKeyAudit['indexed_rows'],
                'missing_key_rows' => $priceKeyAudit['missing_rows'],
                'malformed_rows' => $priceKeyAudit['malformed_rows'],
                'unique_keys' => $priceKeyAudit['unique_values'],
                'rows_reconciled' => $priceScan['rows_scanned'] === $priceKeyAudit['indexed_rows'] + $priceKeyAudit['missing_rows'] + $priceKeyAudit['malformed_rows'],
                'keys_reconciled' => $priceKeyAudit['unique_values'] === $joined + $priceOnly,
            ],
            'classification' => [
                'rows' => count($rows),
                'bucket_rows' => $classifiedBucketCount,
                'reconciled' => count($rows) === $classifiedBucketCount,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $productScan
     * @param  array<string, mixed>  $priceScan
     * @param  array<string, int>  $readiness
     * @param  array<string, mixed>  $identifierAudit
     * @param  array<string, int>  $overlapAudit
     * @param  array<string, mixed>  $parser
     * @param  array<string, mixed>  $verdict
     * @param  array<string, mixed>  $reconciliation
     * @return array<string, mixed>
     */
    private function summary(Supplier $supplier, array $productSource, array $priceSource, array $productScan, array $priceScan, array $readiness, array $identifierAudit, array $overlapAudit, array $parser, array $verdict, array $reconciliation): array
    {
        return [
            'supplier_name' => $supplier->company_name,
            'product_list_source_label' => $productSource['label'],
            'price_avail_source_label' => $priceSource['label'],
            'mode' => $parser['effective_scan_mode'],
            'parser_mode' => $parser['parser_mode'],
            'max_rows' => $parser['effective_row_limit'],
            'effective_row_limit' => $parser['effective_row_limit'],
            'full_file_completed' => $parser['full_file_completed'],
            'product_list_rows' => $productScan['rows_scanned'],
            'price_avail_rows' => $priceScan['rows_scanned'],
            'joined_rows' => $identifierAudit['joined_unique_keys'],
            'unique_product_code' => $identifierAudit['unique_product_code_values'],
            'unique_wic' => $identifierAudit['unique_wic_values'],
            'would_create' => $readiness['would_create'],
            'would_update' => $readiness['would_update'],
            'ready_to_create' => $readiness['ready_to_create'],
            'ready_to_update' => $readiness['ready_to_update'],
            'ready_with_warning' => $readiness['ready_with_warning'],
            'manual_review' => $readiness['manual_review'],
            'blocked' => $readiness['blocked'],
            'skipped' => $readiness['blocked'] + $readiness['manual_review'] + $readiness['product_only'] + $readiness['price_only'],
            'hard_blocker_count' => $readiness['hard_blocker_count'],
            'manual_review_count' => $readiness['manual_review_count'],
            'unmatched_count' => $readiness['unmatched_count'],
            'apply_excluded_count' => $readiness['apply_excluded_count'],
            'product_only_rows' => $readiness['product_only'],
            'price_only_rows' => $readiness['price_only'],
            'duplicate_product_code' => $identifierAudit['duplicate_product_code_keys'],
            'duplicate_wic' => $identifierAudit['duplicate_wic_keys'],
            'duplicate_ean' => $identifierAudit['duplicate_ean_groups'],
            'duplicate_keys' => $identifierAudit['duplicate_product_code_keys'] + $identifierAudit['duplicate_wic_keys'],
            'cross_supplier_matches' => $overlapAudit['total_overlap_groups'],
            'elapsed_seconds' => $parser['elapsed_seconds'],
            'peak_memory_bytes' => $parser['peak_memory_bytes'],
            'verdict' => $verdict['verdict'],
            'apply_candidate_count' => $verdict['apply_candidate_count'],
            'apply_blocker_count' => $verdict['apply_blocker_count'],
            'reconciliation_valid' => $reconciliation['reconciliation_valid'],
            'reconciliation_issues' => $reconciliation['reconciliation_issues'],
            'safety_status' => 'read_only_no_changes',
            'records_changed' => $this->recordsChanged(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array<int, int>>
     */
    private function duplicateGroups(array $rows, string $field): array
    {
        $groups = [];

        foreach ($rows as $index => $row) {
            $value = $this->normalizeIdentifier($row[$field] ?? null);

            if ($value !== null) {
                $groups[$value][] = $index;
            }
        }

        return array_filter($groups, fn (array $indexes): bool => count($indexes) > 1);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array<int, int>>
     */
    private function duplicateBrandMpnGroups(array $rows): array
    {
        $groups = [];

        foreach ($rows as $index => $row) {
            $key = $this->brandMpnKey($row['brand'] ?? null, $row['mpn'] ?? null);

            if ($key !== null) {
                $groups[$key][] = $index;
            }
        }

        return array_filter($groups, fn (array $indexes): bool => count($indexes) > 1);
    }

    /**
     * @param  array<string, array{count: int, row: array<string, mixed>, fingerprint: string, conflicting: bool}>  $index
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $fingerprintFields
     */
    private function addIndexRow(array &$index, string $key, array $row, array $fingerprintFields): void
    {
        $fingerprint = hash('sha256', json_encode(collect($row)->only($fingerprintFields)->all(), JSON_THROW_ON_ERROR));

        if (! isset($index[$key])) {
            $index[$key] = [
                'count' => 1,
                'row' => $row,
                'fingerprint' => $fingerprint,
                'conflicting' => false,
            ];

            return;
        }

        $index[$key]['count']++;
        $index[$key]['conflicting'] = $index[$key]['conflicting'] || $index[$key]['fingerprint'] !== $fingerprint;
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, string|null>  $fieldMap
     * @return array<string, mixed>
     */
    private function normalizeProductRow(array $row, array $fieldMap, string $joinKey, int $rowNumber): array
    {
        $supplierSku = $this->clean($row[$joinKey] ?? $this->mappedValue($row, $fieldMap['supplier_sku']));
        $image = $this->clean($this->mappedValue($row, $fieldMap['image_url']));

        return [
            'row_number' => $rowNumber,
            'normalized_join_key' => $this->normalizeIdentifier($supplierSku),
            'supplier_sku' => $supplierSku,
            'ean_gtin' => $this->clean($this->mappedValue($row, $fieldMap['ean_gtin'])),
            'mpn' => $this->clean($this->mappedValue($row, $fieldMap['mpn'])),
            'brand' => $this->compactText($this->mappedValue($row, $fieldMap['brand'])),
            'name' => $this->compactText($this->mappedValue($row, $fieldMap['name'])),
            'category' => $this->compactText($this->mappedValue($row, $fieldMap['category'])),
            'description_present' => $this->clean($this->mappedValue($row, $fieldMap['description'])) !== null,
            'image_url_present' => $image !== null,
            'image_url_host' => $this->imageHost($image),
        ];
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, string|null>  $fieldMap
     * @return array<string, mixed>
     */
    private function normalizePriceRow(array $row, array $fieldMap, string $joinKey, int $rowNumber): array
    {
        $supplierSku = $this->clean($row[$joinKey] ?? $this->mappedValue($row, $fieldMap['supplier_sku']));
        $myPriceRaw = $this->clean($this->mappedValue($row, $fieldMap['price']));
        $retailPriceRaw = $this->clean($this->mappedValue($row, $fieldMap['retail_price']));
        $myPrice = $this->decimal($myPriceRaw);
        $retailPrice = $this->decimal($retailPriceRaw);

        if ($myPrice !== null) {
            $price = $myPrice;
            $priceSource = 'MY_PRICE';
            $priceIssue = null;
        } elseif ($retailPrice !== null) {
            $price = $retailPrice;
            $priceSource = 'RETAIL_PRICE';
            $priceIssue = null;
        } else {
            $price = null;
            $priceSource = null;
            $priceIssue = $myPriceRaw === null && $retailPriceRaw === null ? 'missing_price' : 'invalid_price';
        }

        $rawAvailability = $this->clean($this->mappedValue($row, $fieldMap['availability']));
        $rawStock = $this->clean($this->mappedValue($row, $fieldMap['stock']));
        $availability = $this->normalizeAvailability($rawAvailability);
        $stock = $rawStock !== null && preg_match('/^-?\d+$/', $rawStock) === 1 ? (int) $rawStock : null;
        $image = $this->clean($this->mappedValue($row, $fieldMap['image_url']));

        return [
            'row_number' => $rowNumber,
            'normalized_join_key' => $this->normalizeIdentifier($supplierSku),
            'supplier_sku' => $supplierSku,
            'ean_gtin' => $this->clean($this->mappedValue($row, $fieldMap['ean_gtin'])),
            'mpn' => $this->clean($this->mappedValue($row, $fieldMap['mpn'])),
            'brand' => $this->compactText($this->mappedValue($row, $fieldMap['brand'])),
            'name' => $this->compactText($this->mappedValue($row, $fieldMap['name'])),
            'category' => $this->compactText($this->mappedValue($row, $fieldMap['category'])),
            'price' => $price,
            'price_source' => $priceSource,
            'price_issue' => $priceIssue,
            'currency' => ($currency = $this->clean($this->mappedValue($row, $fieldMap['currency']))) !== null ? strtoupper($currency) : null,
            'stock' => $stock,
            'availability' => $availability,
            'raw_availability' => $rawAvailability,
            'description_present' => $this->clean($this->mappedValue($row, $fieldMap['description'])) !== null,
            'image_url_present' => $image !== null,
            'image_url_host' => $this->imageHost($image),
        ];
    }

    private function normalizeAvailability(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $normalized = mb_strtolower(trim($raw));

        if (preg_match('/^-?\d+$/', $normalized) === 1) {
            return (int) $normalized > 0 ? 'in_stock' : null;
        }

        return match ($normalized) {
            'да', 'in_stock', 'in stock', 'available' => 'in_stock',
            'ограничено', 'limited_stock', 'limited stock' => 'limited_stock',
            'по заявка', 'on_request', 'on request' => 'on_request',
            default => null,
        };
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     * @param  array<string, array<int, string>>  $aliases
     * @return array<string, string|null>
     */
    private function detectFieldMap(array $rows, array $aliases): array
    {
        $rawFields = collect($rows)->flatMap(fn (array $row): array => array_keys($row))->unique()->values();
        $byNormalized = $rawFields->mapWithKeys(fn (string $field): array => [$this->normalizeKey($field) => $field]);

        return collect($aliases)->mapWithKeys(function (array $candidates, string $field) use ($byNormalized): array {
            foreach ($candidates as $candidate) {
                $normalized = $this->normalizeKey($candidate);

                if ($byNormalized->has($normalized)) {
                    return [$field => $byNormalized->get($normalized)];
                }
            }

            return [$field => null];
        })->all();
    }

    /**
     * @param  array<int, array<string, string>>  $productRows
     * @param  array<int, array<string, string>>  $priceRows
     * @param  array<string, string|null>  $productFieldMap
     * @param  array<string, string|null>  $priceFieldMap
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function resolveJoin(array $productRows, array $priceRows, array $productFieldMap, array $priceFieldMap, array $options): array
    {
        $requestedProductKey = $this->clean($options['product_key'] ?? null);
        $requestedPriceKey = $this->clean($options['price_key'] ?? null);
        $productKey = $requestedProductKey !== null
            ? $this->resolveRawKey($productRows, $requestedProductKey)
            : $this->resolveRawKey($productRows, 'ProductCode');
        $priceKey = $requestedPriceKey !== null
            ? $this->resolveRawKey($priceRows, $requestedPriceKey)
            : $this->resolveRawKey($priceRows, 'WIC');

        if ($requestedProductKey === null && $productKey === null) {
            $productKey = $productFieldMap['supplier_sku'];
        }

        if ($requestedPriceKey === null && $priceKey === null) {
            $priceKey = $priceFieldMap['supplier_sku'];
        }

        $confidence = $productKey !== null && $priceKey !== null
            ? (($requestedProductKey !== null || $requestedPriceKey !== null) ? 'explicit_key_match' : 'inferred_key_match')
            : 'missing_join_key';

        if ($productKey !== null && $priceKey !== null && $this->normalizeKey($productKey) === $this->normalizeKey($priceKey)) {
            $confidence = ($requestedProductKey !== null || $requestedPriceKey !== null) ? 'explicit_key_match' : 'exact_key_match';
        }

        return [
            'product_key' => $productKey,
            'price_key' => $priceKey,
            'confidence' => $confidence,
            'candidate_product_keys' => array_values(array_unique(array_filter([$productFieldMap['supplier_sku'], $this->resolveRawKey($productRows, 'ProductCode')]))),
            'candidate_price_keys' => array_values(array_unique(array_filter([$priceFieldMap['supplier_sku'], $this->resolveRawKey($priceRows, 'WIC')]))),
            'candidate_normalized_keys' => $productKey === 'ProductCode' && $priceKey === 'WIC' ? ['productcode:wic'] : [],
            'issues' => $confidence === 'missing_join_key' ? [['reason' => 'missing_join_key']] : [],
        ];
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     */
    private function resolveRawKey(array $rows, string $key): ?string
    {
        $normalized = $this->normalizeKey($key);

        return collect($rows)
            ->flatMap(fn (array $row): array => array_keys($row))
            ->unique()
            ->first(fn (string $field): bool => $field === $key || $this->normalizeKey($field) === $normalized);
    }

    private function resolveSupplier(string $supplier): ?Supplier
    {
        if (! Schema::hasTable('suppliers')) {
            return null;
        }

        if (is_numeric($supplier)) {
            return Supplier::query()->whereKey((int) $supplier)->first();
        }

        $normalized = Str::lower($supplier);

        return Supplier::query()
            ->where(function (Builder $query) use ($normalized): void {
                $query
                    ->whereRaw('LOWER(slug) = ?', [$normalized])
                    ->orWhereRaw('LOWER(company_name) = ?', [$normalized]);
            })
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveSource(string $source, string $fixture, string $kind): array
    {
        $candidate = trim($fixture !== '' ? $fixture : $source);

        if ($candidate === '') {
            return [
                'success' => false,
                'issue' => Str::snake($kind).'_required',
                'message' => $kind.' local file is required.',
                'label' => '-',
            ];
        }

        if ($this->isRemoteSource($candidate)) {
            return [
                'success' => false,
                'issue' => 'remote_source_disabled',
                'message' => 'Remote feed fetching is disabled for the ASBIS readiness audit. Provide local files.',
                'label' => $this->safeSourceLabel($candidate),
            ];
        }

        $path = $this->absolutePath($candidate);

        if (! is_file($path)) {
            return [
                'success' => false,
                'issue' => Str::snake($kind).'_file_missing',
                'message' => $kind.' source file was not found.',
                'label' => $this->safeSourceLabel($candidate),
            ];
        }

        return [
            'success' => true,
            'path' => $path,
            'label' => $this->safeSourceLabel($path),
            'source_type' => 'xml',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $supplier
     * @return array<string, mixed>
     */
    private function failure(string $reason, string $message, string $mode, float $startedAt, ?Supplier $supplier = null, ?array $productSource = null, ?array $priceSource = null, ?array $join = null): array
    {
        return [
            'success' => false,
            'mode' => $mode,
            'supplier' => $supplier instanceof Supplier ? $this->supplierPayload($supplier) : null,
            'sources' => [
                'product_list' => $this->sourcePayload($productSource ?? []),
                'price_avail' => $this->sourcePayload($priceSource ?? []),
            ],
            'source_fingerprints' => [],
            'ready_to_create_candidate_count' => 0,
            'ready_to_create_candidate_set_sha256' => $this->candidateFingerprintService->fingerprint([]),
            'candidate_payload_schema_version' => AsbisCandidateFingerprintService::SCHEMA_VERSION,
            'parser' => [
                'parser_mode' => 'streaming_xmlreader',
                'effective_scan_mode' => 'not_started',
                'effective_row_limit' => null,
                'full_file_completed' => false,
                'product_list_rows_scanned' => 0,
                'price_avail_rows_scanned' => 0,
                'elapsed_seconds' => round(microtime(true) - $startedAt, 4),
                'peak_memory_bytes' => memory_get_peak_usage(true),
            ],
            'join' => $join ?? [
                'product_key' => null,
                'price_key' => null,
                'confidence' => 'missing_join_key',
            ],
            'summary' => [
                'safety_status' => 'read_only_failed_safely',
                'records_changed' => $this->recordsChanged(),
            ],
            'readiness' => [
                'verdict' => 'not_ready_for_controlled_staging_apply',
                'apply_candidate_count' => 0,
                'ready_to_create_candidate_count' => 0,
                'ready_to_create_candidate_set_sha256' => $this->candidateFingerprintService->fingerprint([]),
                'candidate_payload_schema_version' => AsbisCandidateFingerprintService::SCHEMA_VERSION,
                'apply_blocker_count' => 1,
                'blocker_reasons' => [$reason => 1],
                'warning_reasons' => [],
                'advisory_only' => true,
            ],
            'identifier_audit' => [],
            'availability_audit' => [],
            'pricing_audit' => [],
            'category_content_audit' => [],
            'overlap_audit' => [],
            'issue_counts' => [$reason => 1],
            'issue_samples' => [[
                'type' => 'source_or_parser',
                'reason' => $reason,
                'message' => $message,
            ]],
            'ready_samples' => [],
            'manual_review_samples' => [],
            'unmatched_product_samples' => [],
            'unmatched_price_samples' => [],
            'issues' => [[
                'type' => 'source_or_parser',
                'reason' => $reason,
                'message' => $message,
            ]],
            'records_changed' => $this->recordsChanged(),
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function sourcePayload(array $source): array
    {
        return [
            'label' => $source['label'] ?? '-',
            'source_type' => $source['source_type'] ?? 'xml',
            'local_only' => true,
            'remote_fetch' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function supplierPayload(Supplier $supplier): array
    {
        return [
            'id' => $supplier->getKey(),
            'name' => $supplier->company_name,
            'slug' => $supplier->slug,
            'key' => $this->supplierKey($supplier),
        ];
    }

    private function mappedValue(array $row, ?string $key): mixed
    {
        return $key !== null ? ($row[$key] ?? null) : null;
    }

    private function clean(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $clean = trim((string) $value);

        return $clean === '' ? null : $clean;
    }

    private function compactText(mixed $value): ?string
    {
        $clean = $this->clean($value);

        return $clean === null ? null : Str::limit($clean, 500, '');
    }

    private function decimal(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $normalized = str_replace([' ', ','], ['', '.'], $value);

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function normalizeIdentifier(mixed $value): ?string
    {
        $clean = $this->clean($value);

        return $clean === null ? null : Str::upper($clean);
    }

    private function brandMpnKey(mixed $brand, mixed $mpn): ?string
    {
        $normalizedBrand = $this->normalizeIdentifier($brand);
        $normalizedMpn = $this->normalizeIdentifier($mpn);

        return $normalizedBrand !== null && $normalizedMpn !== null
            ? $normalizedBrand.'|'.$normalizedMpn
            : null;
    }

    private function normalizeKey(string $key): string
    {
        return preg_replace('/[^a-z0-9]+/', '', Str::lower($key)) ?? '';
    }

    private function imageHost(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? Str::lower($host) : null;
    }

    private function modifiedAt(string $path): ?string
    {
        $timestamp = filemtime($path);

        return $timestamp === false ? null : date(DATE_ATOM, $timestamp);
    }

    private function sampleLimit(mixed $value): int
    {
        return max(0, min((int) $value, self::MAX_SAMPLE_LIMIT));
    }

    private function supplierKey(Supplier $supplier): string
    {
        return Str::slug((string) ($supplier->slug ?: $supplier->company_name));
    }

    private function isRemoteSource(string $source): bool
    {
        return preg_match('/^https?:\/\//i', trim($source)) === 1;
    }

    private function absolutePath(string $path): string
    {
        if (preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }

    private function safeSourceLabel(string $source): string
    {
        if ($this->isRemoteSource($source)) {
            $host = parse_url($source, PHP_URL_HOST);

            return is_string($host) && $host !== '' ? 'remote://'.$host.'/[redacted]' : 'remote://[redacted]';
        }

        return basename(str_replace('\\', '/', $source));
    }
}

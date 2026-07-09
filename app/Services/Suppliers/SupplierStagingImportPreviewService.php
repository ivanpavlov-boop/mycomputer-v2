<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;
use App\Models\SupplierProduct;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use JsonException;
use SimpleXMLElement;
use Throwable;

class SupplierStagingImportPreviewService
{
    private const MAX_SCAN_ROWS = 5000;

    private const FIELD_ALIASES = [
        'supplier_sku' => ['supplier_sku', 'sku', 'code', 'product_code', 'item_code', 'item_number', 'part_number', 'pn'],
        'ean_gtin' => ['ean', 'gtin', 'barcode', 'ean13', 'upc'],
        'mpn' => ['mpn', 'manufacturer_sku', 'manufacturer_part_number', 'manufacturer_code', 'mfr_part_number', 'vendor_part_number'],
        'brand' => ['brand', 'brand_name', 'manufacturer', 'vendor'],
        'name' => ['name', 'product_name', 'title'],
        'category' => ['category', 'category_name', 'category_path', 'categories', 'path'],
        'price' => ['price', 'supplier_price', 'dealer_price', 'cost', 'net_price', 'regular_price'],
        'stock' => ['stock', 'qty', 'quantity', 'available_quantity', 'inventory'],
        'availability' => ['availability', 'availability_status', 'stock_status'],
        'currency' => ['currency', 'curr'],
        'vat' => ['vat', 'vat_rate', 'tax', 'tax_rate'],
        'image_url' => ['image', 'image_url', 'picture', 'picture_url', 'thumbnail', 'image1'],
        'description' => ['description', 'full_description', 'long_description', 'desc'],
    ];

    /**
     * @return array<string, mixed>
     */
    public function preview(
        ?string $supplier = null,
        ?string $source = null,
        ?string $fixture = null,
        string $sourceType = 'auto',
        int $limit = 50,
        bool $showRawFields = false,
        bool $showNormalized = false,
        bool $showIdentifiers = false,
        bool $showCategories = false,
        bool $showIssues = false,
    ): array {
        $limit = max(1, min($limit, self::MAX_SCAN_ROWS));
        $supplierModel = $this->resolveSupplier($supplier);
        $sourceInfo = $this->resolveSource($source, $fixture, $sourceType);

        if (! $sourceInfo['success']) {
            return $this->failurePayload($supplierModel, $supplier, $sourceInfo, $limit);
        }

        try {
            $rawRows = $this->parseRows($sourceInfo['path'], $sourceInfo['source_type']);
        } catch (Throwable $exception) {
            return $this->failurePayload($supplierModel, $supplier, [
                ...$sourceInfo,
                'success' => false,
                'issue' => 'parse_error',
                'message' => 'Unable to parse preview source: '.$exception->getMessage(),
            ], $limit);
        }

        $rawRows = $rawRows->take(self::MAX_SCAN_ROWS)->values();
        $fieldMap = $this->detectedFieldMap($rawRows);
        $normalizedRows = $this->normalizedRows($rawRows, $fieldMap);
        $normalizedRows = $this->markDuplicateIssues($normalizedRows);
        $comparison = $this->compareRows($normalizedRows, $supplierModel);
        $rowsWithActions = $this->applyFutureActions($comparison['rows']);
        $issues = [
            ...$this->issues($rowsWithActions, $sourceInfo),
            ...$comparison['issues'],
        ];

        return [
            'success' => true,
            'summary' => [
                'supplier_id' => $supplierModel?->id,
                'supplier_key' => $supplierModel?->slug ?? $supplier,
                'supplier_name' => $supplierModel?->company_name ?? $supplier ?? 'next-supplier-preview',
                'source_type' => $sourceInfo['source_type'],
                'source_label' => $sourceInfo['label'],
                'rows_scanned' => $rawRows->count(),
                'rows_returned' => min($limit, $rowsWithActions->count()),
                'display_limit' => $limit,
                'would_create_supplier_products' => $rowsWithActions->where('future_staging_action', 'would_create_supplier_product')->count(),
                'would_update_supplier_products' => $rowsWithActions->where('future_staging_action', 'would_update_supplier_product')->count(),
                'would_skip_rows' => $rowsWithActions->where('future_staging_action', 'would_skip_row')->count(),
                'would_need_manual_review' => $rowsWithActions->where('needs_manual_review', true)->count(),
                'possible_cross_supplier_matches' => collect($comparison['overlaps'])->where('scope', 'cross_supplier')->count(),
                'duplicate_rows_in_preview' => $rowsWithActions->filter(fn (array $row): bool => $this->hasDuplicateIssue($row))->count(),
                'catalog_sync_changed' => 0,
                'records_changed' => $this->recordsChanged(),
                'show_raw_fields' => $showRawFields,
                'show_normalized' => $showNormalized,
                'show_identifiers' => $showIdentifiers,
                'show_categories' => $showCategories,
                'show_issues' => $showIssues,
            ],
            'detected_fields' => [
                'raw_field_names' => $this->rawFieldNames($rawRows),
                'normalized_field_map' => $fieldMap,
            ],
            'normalized_coverage' => $this->coverage($normalizedRows, [
                'supplier_sku',
                'ean_gtin',
                'mpn',
                'brand',
                'name',
                'category',
                'price',
                'stock',
                'availability',
                'currency',
            ]),
            'identifier_summary' => $this->identifierSummary($normalizedRows),
            'category_summary' => $this->categorySummary($normalizedRows),
            'price_stock_summary' => $this->priceStockSummary($normalizedRows),
            'preview_rows' => $this->displayRows($rowsWithActions, $limit, $showRawFields),
            'overlaps' => collect($comparison['overlaps'])->take($limit)->values()->all(),
            'issues' => $issues,
            'records_changed' => $this->recordsChanged(),
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
        ];
    }

    private function resolveSupplier(?string $supplier): ?Supplier
    {
        if (blank($supplier) || ! $this->hasTableSafely('suppliers')) {
            return null;
        }

        $query = Supplier::query();

        if (is_numeric($supplier)) {
            return $query->whereKey((int) $supplier)->first();
        }

        $normalized = Str::lower(trim($supplier));

        return $query
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
    private function resolveSource(?string $source, ?string $fixture, string $sourceType): array
    {
        $sourceType = Str::lower($sourceType ?: 'auto');

        if (! in_array($sourceType, ['auto', 'xml', 'csv', 'json'], true)) {
            return [
                'success' => false,
                'issue' => 'unsupported_source_type',
                'message' => 'Unsupported source type. Use xml, csv, json, or auto.',
                'source_type' => $sourceType,
                'label' => $source ?? $fixture ?? '-',
            ];
        }

        $candidate = $fixture ?: $source;

        if (blank($candidate)) {
            $candidate = base_path('tests/Fixtures/Suppliers/next_supplier_preview.xml');
        }

        $candidate = (string) $candidate;

        if ($this->isRemoteSource($candidate)) {
            return [
                'success' => false,
                'issue' => 'remote_source_disabled',
                'message' => 'Remote feed fetching is disabled in preview-only phase.',
                'source_type' => $sourceType === 'auto' ? $this->inferSourceType($candidate) : $sourceType,
                'label' => $this->safeSourceLabel($candidate),
            ];
        }

        $path = $this->absolutePath($candidate);

        if (! is_file($path)) {
            return [
                'success' => false,
                'issue' => 'source_file_missing',
                'message' => 'Preview source file was not found.',
                'source_type' => $sourceType === 'auto' ? $this->inferSourceType($candidate) : $sourceType,
                'label' => $this->safeSourceLabel($candidate),
            ];
        }

        $resolvedType = $sourceType === 'auto' ? $this->inferSourceType($path) : $sourceType;

        if (! in_array($resolvedType, ['xml', 'csv', 'json'], true)) {
            return [
                'success' => false,
                'issue' => 'unsupported_source_type',
                'message' => 'Unsupported source type. Use xml, csv, json, or auto.',
                'source_type' => $resolvedType,
                'label' => $this->safeSourceLabel($path),
            ];
        }

        return [
            'success' => true,
            'path' => $path,
            'source_type' => $resolvedType,
            'label' => $this->safeSourceLabel($path),
        ];
    }

    private function isRemoteSource(string $source): bool
    {
        return Str::startsWith(Str::lower($source), ['http://', 'https://']);
    }

    private function absolutePath(string $path): string
    {
        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1 || Str::startsWith($path, ['/', '\\\\'])) {
            return $path;
        }

        return base_path($path);
    }

    private function inferSourceType(string $source): string
    {
        $extension = Str::lower(pathinfo(parse_url($source, PHP_URL_PATH) ?: $source, PATHINFO_EXTENSION));

        return match ($extension) {
            'xml' => 'xml',
            'csv' => 'csv',
            'json' => 'json',
            default => 'unsupported',
        };
    }

    private function safeSourceLabel(string $source): string
    {
        if ($this->isRemoteSource($source)) {
            $host = parse_url($source, PHP_URL_HOST);

            return is_string($host) && $host !== '' ? 'remote:'.$host : 'remote';
        }

        return basename($source);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     *
     * @throws JsonException
     */
    private function parseRows(string $path, string $sourceType): Collection
    {
        return match ($sourceType) {
            'xml' => $this->parseXmlRows($path),
            'csv' => $this->parseCsvRows($path),
            'json' => $this->parseJsonRows($path),
            default => collect(),
        };
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function parseXmlRows(string $path): Collection
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            return collect();
        }

        $xml = simplexml_load_string($contents, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);

        if (! $xml instanceof SimpleXMLElement) {
            return collect();
        }

        $nodes = collect($xml->xpath('//*[local-name()="product" or local-name()="item" or local-name()="row"]') ?: []);

        if ($nodes->isEmpty()) {
            $nodes = collect($xml->children());
        }

        return $nodes
            ->map(fn (SimpleXMLElement $node): array => $this->flattenXml($node))
            ->filter(fn (array $row): bool => $row !== [])
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function flattenXml(SimpleXMLElement $node, string $prefix = ''): array
    {
        $row = [];

        foreach ($node->attributes() as $key => $value) {
            $row[trim($prefix.'attr_'.$key, '.')] = trim((string) $value);
        }

        foreach ($node->children() as $key => $child) {
            $field = trim($prefix.$key, '.');

            if ($child->children()->count() > 0) {
                $row = array_merge($row, $this->flattenXml($child, $field.'.'));
            } else {
                $value = trim((string) $child);
                $row[$field] = array_key_exists($field, $row) && filled($row[$field])
                    ? $row[$field].' | '.$value
                    : $value;
            }
        }

        return $row;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function parseCsvRows(string $path): Collection
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return collect();
        }

        $headers = fgetcsv($handle);

        if (! is_array($headers)) {
            fclose($handle);

            return collect();
        }

        $headers = array_map(fn (mixed $header): string => trim((string) $header), $headers);
        $rows = [];

        while (($data = fgetcsv($handle)) !== false) {
            $row = [];

            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }

                $row[$header] = $data[$index] ?? null;
            }

            $rows[] = $row;
        }

        fclose($handle);

        return collect($rows);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     *
     * @throws JsonException
     */
    private function parseJsonRows(string $path): Collection
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            return collect();
        }

        $payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        if (is_array($payload) && array_is_list($payload)) {
            return collect($payload)->map(fn (mixed $row): array => is_array($row) ? $this->flattenArray($row) : [])->filter()->values();
        }

        if (! is_array($payload)) {
            return collect();
        }

        foreach (['products', 'items', 'data', 'rows'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return collect($payload[$key])->map(fn (mixed $row): array => is_array($row) ? $this->flattenArray($row) : [])->filter()->values();
            }
        }

        return collect([$this->flattenArray($payload)]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function flattenArray(array $payload, string $prefix = ''): array
    {
        $row = [];

        foreach ($payload as $key => $value) {
            $field = trim($prefix.(string) $key, '.');

            if (is_array($value) && ! array_is_list($value)) {
                $row = array_merge($row, $this->flattenArray($value, $field.'.'));

                continue;
            }

            if (is_array($value)) {
                $row[$field] = implode(' | ', array_map(fn (mixed $item): string => is_scalar($item) ? (string) $item : (json_encode($item) ?: ''), $value));

                continue;
            }

            $row[$field] = $value;
        }

        return $row;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rawRows
     * @return array<string, string|null>
     */
    private function detectedFieldMap(Collection $rawRows): array
    {
        $keys = $this->rawFieldNames($rawRows);
        $normalizedKeys = collect($keys)->mapWithKeys(fn (string $key): array => [$this->normalizeKey($key) => $key]);

        return collect(self::FIELD_ALIASES)
            ->mapWithKeys(function (array $aliases, string $field) use ($normalizedKeys): array {
                foreach ($aliases as $alias) {
                    $normalizedAlias = $this->normalizeKey($alias);

                    if ($normalizedKeys->has($normalizedAlias)) {
                        return [$field => $normalizedKeys->get($normalizedAlias)];
                    }
                }

                foreach ($normalizedKeys as $normalizedKey => $rawKey) {
                    foreach ($aliases as $alias) {
                        if (str_contains($normalizedKey, $this->normalizeKey($alias))) {
                            return [$field => $rawKey];
                        }
                    }
                }

                return [$field => null];
            })
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rawRows
     * @return array<int, string>
     */
    private function rawFieldNames(Collection $rawRows): array
    {
        return $rawRows
            ->flatMap(fn (array $row): array => array_keys($row))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function normalizeKey(string $key): string
    {
        return Str::of($key)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->toString();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rawRows
     * @param  array<string, string|null>  $fieldMap
     * @return Collection<int, array<string, mixed>>
     */
    private function normalizedRows(Collection $rawRows, array $fieldMap): Collection
    {
        return $rawRows
            ->values()
            ->map(function (array $row, int $index) use ($fieldMap): array {
                $normalized = [];

                foreach (array_keys(self::FIELD_ALIASES) as $field) {
                    $normalized[$field] = $this->cleanValue($this->mappedValue($row, $fieldMap[$field] ?? null));
                }

                $normalized['price'] = $this->decimalValue($normalized['price']);
                $normalized['stock'] = $this->integerValue($normalized['stock']);
                $normalized['image_url_host'] = $this->urlHost($normalized['image_url']);
                $normalized['image_url_present'] = filled($normalized['image_url']);
                $normalized['description_present'] = filled($normalized['description']);
                unset($normalized['image_url'], $normalized['description']);

                $issues = $this->rowIssues($normalized);

                return [
                    'row_index' => $index + 1,
                    'normalized' => $normalized,
                    'raw_field_names' => array_values(array_keys($row)),
                    'issues' => $issues,
                    'needs_manual_review' => $issues !== [],
                ];
            });
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function mappedValue(array $row, ?string $key): mixed
    {
        return $key === null ? null : ($row[$key] ?? null);
    }

    private function cleanValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function decimalValue(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $normalized = str_replace(',', '.', preg_replace('/[^0-9,\.\-]/', '', $value) ?? '');

        return is_numeric($normalized) ? round((float) $normalized, 2) : null;
    }

    private function integerValue(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $normalized = preg_replace('/[^0-9\-]/', '', $value) ?? '';

        return is_numeric($normalized) ? (int) $normalized : null;
    }

    private function urlHost(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }

        $host = parse_url((string) $url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return array<int, string>
     */
    private function rowIssues(array $normalized): array
    {
        $issues = [];

        foreach ([
            'supplier_sku' => 'missing_supplier_sku',
            'ean_gtin' => 'missing_ean_gtin',
            'mpn' => 'missing_mpn',
            'brand' => 'missing_brand',
            'category' => 'missing_category',
            'price' => 'missing_price',
        ] as $field => $issue) {
            if (! $this->hasValue($normalized[$field] ?? null)) {
                $issues[] = $issue;
            }
        }

        if (! $this->hasValue($normalized['stock'] ?? null) && ! $this->hasValue($normalized['availability'] ?? null)) {
            $issues[] = 'missing_stock_availability';
        }

        return $issues;
    }

    private function hasValue(mixed $value): bool
    {
        if (is_string($value)) {
            return trim($value) !== '';
        }

        return $value !== null;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function markDuplicateIssues(Collection $rows): Collection
    {
        $duplicateValues = [];

        foreach (['supplier_sku', 'ean_gtin', 'mpn'] as $field) {
            $counts = $rows
                ->map(fn (array $row): ?string => $this->normalizedIdentifier($row['normalized'][$field] ?? null))
                ->filter()
                ->countBy();

            $duplicateValues[$field] = $counts->filter(fn (int $count): bool => $count > 1)->keys()->all();
            $duplicateValues[$field] = array_map('strval', $duplicateValues[$field]);
        }

        return $rows
            ->map(function (array $row) use ($duplicateValues): array {
                foreach ([
                    'supplier_sku' => 'duplicate_supplier_sku_in_preview',
                    'ean_gtin' => 'duplicate_ean_gtin_in_preview',
                    'mpn' => 'duplicate_mpn_in_preview',
                ] as $field => $issue) {
                    $value = $this->normalizedIdentifier($row['normalized'][$field] ?? null);

                    if ($value !== null && in_array($value, $duplicateValues[$field], true)) {
                        $row['issues'][] = $issue;
                    }
                }

                $row['issues'] = array_values(array_unique($row['issues']));
                $row['needs_manual_review'] = $row['issues'] !== [];

                return $row;
            });
    }

    private function normalizedIdentifier(mixed $value): ?string
    {
        if (! $this->hasValue($value)) {
            return null;
        }

        return Str::of((string) $value)->ascii()->lower()->trim()->toString();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{rows: Collection<int, array<string, mixed>>, overlaps: array<int, array<string, mixed>>, issues: array<int, array<string, mixed>>}
     */
    private function compareRows(Collection $rows, ?Supplier $supplier): array
    {
        $issues = [];
        $existing = collect();

        try {
            $existing = $this->existingSupplierProducts();
        } catch (Throwable) {
            $issues[] = [
                'type' => 'staging_comparison',
                'reason' => 'staging_comparison_unavailable',
                'message' => 'Existing supplier_products comparison was skipped because the database is unavailable.',
            ];
        }

        $overlaps = [];

        $rows = $rows->map(function (array $row) use ($existing, $supplier, &$overlaps): array {
            $normalized = $row['normalized'];
            $sameSupplierSkuMatch = null;

            foreach ($existing as $product) {
                $match = $this->matchExistingSupplierProduct($product, $normalized, $supplier);

                if ($match === null) {
                    continue;
                }

                $overlaps[] = [
                    'row_index' => $row['row_index'],
                    ...$match,
                ];

                if ($match['type'] === 'same_supplier_sku') {
                    $sameSupplierSkuMatch = $match;
                }
            }

            $row['same_supplier_sku_match'] = $sameSupplierSkuMatch;
            $row['overlap_count'] = collect($overlaps)->where('row_index', $row['row_index'])->count();

            return $row;
        });

        return [
            'rows' => $rows,
            'overlaps' => $overlaps,
            'issues' => $issues,
        ];
    }

    /**
     * @return Collection<int, SupplierProduct>
     */
    private function existingSupplierProducts(): Collection
    {
        if (! Schema::hasTable('supplier_products')) {
            return collect();
        }

        return SupplierProduct::query()
            ->with('supplier:id,company_name,slug')
            ->select([
                'id',
                'supplier_id',
                'supplier_sku',
                'ean',
                'mpn',
                'brand_name',
                'name',
            ])
            ->get();
    }

    private function hasTableSafely(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return array<string, mixed>|null
     */
    private function matchExistingSupplierProduct(SupplierProduct $product, array $normalized, ?Supplier $supplier): ?array
    {
        $sameSupplier = $supplier instanceof Supplier && (int) $product->supplier_id === (int) $supplier->id;

        if ($sameSupplier && $this->sameIdentifier($product->supplier_sku, $normalized['supplier_sku'] ?? null)) {
            return $this->matchRow('same_supplier_sku', 'same_supplier', 'high', $product, 'supplier_sku');
        }

        if ($this->sameIdentifier($product->ean, $normalized['ean_gtin'] ?? null)) {
            return $this->matchRow('ean_gtin', $sameSupplier ? 'same_supplier' : 'cross_supplier', 'high', $product, 'ean_gtin');
        }

        if ($this->sameIdentifier($product->mpn, $normalized['mpn'] ?? null)) {
            if ($this->sameIdentifier($product->brand_name, $normalized['brand'] ?? null)) {
                return $this->matchRow('brand_mpn', $sameSupplier ? 'same_supplier' : 'cross_supplier', 'high', $product, 'brand_mpn');
            }

            return $this->matchRow('mpn', $sameSupplier ? 'same_supplier' : 'cross_supplier', 'medium', $product, 'mpn');
        }

        if ($this->normalizedName($product->name) !== null && $this->normalizedName($product->name) === $this->normalizedName($normalized['name'] ?? null)) {
            return $this->matchRow('normalized_name', $sameSupplier ? 'same_supplier' : 'cross_supplier', 'low', $product, 'name');
        }

        return null;
    }

    private function sameIdentifier(mixed $left, mixed $right): bool
    {
        return $this->normalizedIdentifier($left) !== null
            && $this->normalizedIdentifier($left) === $this->normalizedIdentifier($right);
    }

    private function normalizedName(mixed $name): ?string
    {
        if (! $this->hasValue($name)) {
            return null;
        }

        return Str::of((string) $name)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->toString();
    }

    /**
     * @return array<string, mixed>
     */
    private function matchRow(string $type, string $scope, string $confidence, SupplierProduct $product, string $field): array
    {
        return [
            'type' => $type,
            'scope' => $scope,
            'confidence' => $confidence,
            'match_field' => $field,
            'supplier_product_id' => (int) $product->id,
            'supplier_id' => (int) $product->supplier_id,
            'supplier_name' => $product->supplier?->company_name,
            'supplier_sku' => $product->supplier_sku,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function applyFutureActions(Collection $rows): Collection
    {
        return $rows
            ->map(function (array $row): array {
                if (! $this->hasValue($row['normalized']['supplier_sku'] ?? null)) {
                    $row['future_staging_action'] = 'would_skip_row';
                } elseif (is_array($row['same_supplier_sku_match'] ?? null)) {
                    $row['future_staging_action'] = 'would_update_supplier_product';
                } else {
                    $row['future_staging_action'] = 'would_create_supplier_product';
                }

                return $row;
            });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $fields
     * @return array<string, array<string, int|float>>
     */
    private function coverage(Collection $rows, array $fields): array
    {
        $total = $rows->count();

        return collect($fields)
            ->mapWithKeys(function (string $field) use ($rows, $total): array {
                $present = $rows->filter(fn (array $row): bool => $this->hasValue($row['normalized'][$field] ?? null))->count();

                return [$field => [
                    'present' => $present,
                    'missing' => max(0, $total - $present),
                    'percent' => $total > 0 ? round(($present / $total) * 100, 2) : 0.0,
                ]];
            })
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function identifierSummary(Collection $rows): array
    {
        $coverage = $this->coverage($rows, ['supplier_sku', 'ean_gtin', 'mpn', 'brand']);

        return [
            'supplier_sku_present' => $coverage['supplier_sku']['present'],
            'supplier_sku_missing' => $coverage['supplier_sku']['missing'],
            'ean_gtin_present' => $coverage['ean_gtin']['present'],
            'ean_gtin_missing' => $coverage['ean_gtin']['missing'],
            'mpn_present' => $coverage['mpn']['present'],
            'mpn_missing' => $coverage['mpn']['missing'],
            'brand_present' => $coverage['brand']['present'],
            'brand_missing' => $coverage['brand']['missing'],
            'duplicate_supplier_sku_in_preview' => $this->duplicateValueCount($rows, 'supplier_sku'),
            'duplicate_ean_gtin_in_preview' => $this->duplicateValueCount($rows, 'ean_gtin'),
            'duplicate_mpn_in_preview' => $this->duplicateValueCount($rows, 'mpn'),
        ];
    }

    private function duplicateValueCount(Collection $rows, string $field): int
    {
        return $rows
            ->map(fn (array $row): ?string => $this->normalizedIdentifier($row['normalized'][$field] ?? null))
            ->filter()
            ->countBy()
            ->filter(fn (int $count): bool => $count > 1)
            ->count();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function categorySummary(Collection $rows): array
    {
        $categories = $rows
            ->map(fn (array $row): ?string => $row['normalized']['category'] ?? null)
            ->filter()
            ->unique()
            ->values();

        return [
            'category_present' => $rows->filter(fn (array $row): bool => $this->hasValue($row['normalized']['category'] ?? null))->count(),
            'category_missing' => $rows->filter(fn (array $row): bool => ! $this->hasValue($row['normalized']['category'] ?? null))->count(),
            'distinct_categories_count' => $categories->count(),
            'sample_categories' => $categories->take(10)->all(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function priceStockSummary(Collection $rows): array
    {
        return [
            'price_present' => $rows->filter(fn (array $row): bool => $this->hasValue($row['normalized']['price'] ?? null))->count(),
            'price_missing' => $rows->filter(fn (array $row): bool => ! $this->hasValue($row['normalized']['price'] ?? null))->count(),
            'currency_present' => $rows->filter(fn (array $row): bool => $this->hasValue($row['normalized']['currency'] ?? null))->count(),
            'currency_missing' => $rows->filter(fn (array $row): bool => ! $this->hasValue($row['normalized']['currency'] ?? null))->count(),
            'stock_present' => $rows->filter(fn (array $row): bool => $this->hasValue($row['normalized']['stock'] ?? null))->count(),
            'stock_missing' => $rows->filter(fn (array $row): bool => ! $this->hasValue($row['normalized']['stock'] ?? null))->count(),
            'availability_present' => $rows->filter(fn (array $row): bool => $this->hasValue($row['normalized']['availability'] ?? null))->count(),
            'availability_missing' => $rows->filter(fn (array $row): bool => ! $this->hasValue($row['normalized']['availability'] ?? null))->count(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function displayRows(Collection $rows, int $limit, bool $showRawFields): array
    {
        return $rows
            ->take($limit)
            ->map(function (array $row) use ($showRawFields): array {
                $display = [
                    'row_index' => $row['row_index'],
                    'supplier_sku' => $row['normalized']['supplier_sku'],
                    'ean_gtin' => $row['normalized']['ean_gtin'],
                    'mpn' => $row['normalized']['mpn'],
                    'brand' => $row['normalized']['brand'],
                    'name' => $row['normalized']['name'],
                    'category' => $row['normalized']['category'],
                    'price' => $row['normalized']['price'],
                    'stock' => $row['normalized']['stock'],
                    'availability' => $row['normalized']['availability'],
                    'currency' => $row['normalized']['currency'],
                    'image_url_present' => $row['normalized']['image_url_present'],
                    'image_url_host' => $row['normalized']['image_url_host'],
                    'description_present' => $row['normalized']['description_present'],
                    'future_staging_action' => $row['future_staging_action'],
                    'needs_manual_review' => $row['needs_manual_review'],
                    'issues' => $row['issues'],
                    'overlap_count' => $row['overlap_count'],
                ];

                if ($showRawFields) {
                    $display['raw_field_names'] = $row['raw_field_names'];
                }

                return $display;
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $sourceInfo
     * @return array<int, array<string, mixed>>
     */
    private function issues(Collection $rows, array $sourceInfo): array
    {
        $issues = $rows
            ->flatMap(fn (array $row): array => collect($row['issues'])
                ->map(fn (string $issue): array => [
                    'type' => 'preview_row',
                    'row_index' => $row['row_index'],
                    'reason' => $issue,
                ])
                ->all())
            ->values();

        if (isset($sourceInfo['issue'])) {
            $issues->push([
                'type' => 'source',
                'reason' => $sourceInfo['issue'],
                'message' => $sourceInfo['message'] ?? null,
            ]);
        }

        return $issues->all();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function hasDuplicateIssue(array $row): bool
    {
        return collect($row['issues'] ?? [])
            ->contains(fn (string $issue): bool => str_starts_with($issue, 'duplicate_'));
    }

    /**
     * @param  array<string, mixed>  $sourceInfo
     * @return array<string, mixed>
     */
    private function failurePayload(?Supplier $supplierModel, ?string $supplier, array $sourceInfo, int $limit): array
    {
        $recordsChanged = $this->recordsChanged();

        return [
            'success' => false,
            'summary' => [
                'supplier_id' => $supplierModel?->id,
                'supplier_key' => $supplierModel?->slug ?? $supplier,
                'supplier_name' => $supplierModel?->company_name ?? $supplier ?? 'next-supplier-preview',
                'source_type' => $sourceInfo['source_type'] ?? 'unknown',
                'source_label' => $sourceInfo['label'] ?? '-',
                'rows_scanned' => 0,
                'rows_returned' => 0,
                'would_create_supplier_products' => 0,
                'would_update_supplier_products' => 0,
                'would_skip_rows' => 0,
                'would_need_manual_review' => 0,
                'possible_cross_supplier_matches' => 0,
                'duplicate_rows_in_preview' => 0,
                'display_limit' => $limit,
                'catalog_sync_changed' => 0,
                'records_changed' => $recordsChanged,
            ],
            'detected_fields' => [
                'raw_field_names' => [],
                'normalized_field_map' => [],
            ],
            'normalized_coverage' => [],
            'identifier_summary' => [],
            'category_summary' => [],
            'price_stock_summary' => [],
            'preview_rows' => [],
            'overlaps' => [],
            'issues' => [[
                'type' => 'source',
                'reason' => $sourceInfo['issue'] ?? 'source_error',
                'message' => $sourceInfo['message'] ?? 'Unable to read preview source.',
            ]],
            'records_changed' => $recordsChanged,
        ];
    }
}

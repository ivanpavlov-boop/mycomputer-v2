<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;
use App\Models\SupplierProduct;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use JsonException;
use SimpleXMLElement;
use Throwable;

class ControlledSupplierStagingImportService
{
    private const MAX_ROWS = 5000;

    private const APPLY_SUPPLIER = 'asbis';

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
        'image_url' => ['image', 'image_url', 'picture', 'picture_url', 'thumbnail', 'image1'],
    ];

    /**
     * @return array<string, mixed>
     */
    public function run(array $options): array
    {
        $apply = (bool) ($options['apply'] ?? false);
        $limit = max(1, min((int) ($options['limit'] ?? 50), self::MAX_ROWS));
        $maxRows = max(1, min((int) ($options['max_rows'] ?? self::MAX_ROWS), self::MAX_ROWS));
        $format = (string) ($options['format'] ?? 'table');

        if (! in_array($format, ['table', 'json'], true)) {
            return $this->failure('unsupported_format', 'Unsupported format. Use table or json.', $limit, $apply);
        }

        $sourceType = Str::lower((string) ($options['source_type'] ?? 'auto'));

        if (! in_array($sourceType, ['auto', 'xml', 'csv', 'json'], true)) {
            return $this->failure('unsupported_source_type', 'Unsupported source type. Use xml, csv, json, or auto.', $limit, $apply);
        }

        $supplierInput = trim((string) ($options['supplier'] ?? ''));

        if ($supplierInput === '') {
            return $this->failure('supplier_required', 'The --supplier option is required.', $limit, $apply);
        }

        $sourceInput = trim((string) ($options['source'] ?? ''));
        $fixtureInput = trim((string) ($options['fixture'] ?? ''));

        if ($sourceInput === '' && $fixtureInput === '') {
            return $this->failure('source_required', 'The --source or --fixture option is required.', $limit, $apply);
        }

        $supplier = $this->resolveSupplier($supplierInput);

        if (! $supplier instanceof Supplier) {
            return $this->failure('supplier_not_found', 'Selected supplier was not found.', $limit, $apply);
        }

        $supplierKey = $this->supplierKey($supplier);

        if ($apply && $supplierKey !== self::APPLY_SUPPLIER) {
            return $this->failure('apply_supplier_not_allowed', 'Apply is currently allowed only for ASBIS.', $limit, $apply, $supplier);
        }

        if ($apply && trim((string) ($options['confirm_supplier'] ?? '')) !== self::APPLY_SUPPLIER) {
            return $this->failure('supplier_confirmation_required', 'Apply requires --confirm-supplier=asbis.', $limit, $apply, $supplier);
        }

        $source = $this->resolveSource($sourceInput, $fixtureInput, $sourceType);

        if (! ($source['success'] ?? false)) {
            return $this->failure(
                (string) ($source['issue'] ?? 'source_error'),
                (string) ($source['message'] ?? 'Unable to read source file.'),
                $limit,
                $apply,
                $supplier,
                $source
            );
        }

        try {
            $rawRows = $this->parseRows((string) $source['path'], (string) $source['source_type'])
                ->take($maxRows)
                ->values();
        } catch (Throwable $exception) {
            return $this->failure('parse_error', 'Unable to parse source: '.$exception->getMessage(), $limit, $apply, $supplier, $source);
        }

        $fieldMap = $this->detectedFieldMap($rawRows);
        $rows = $this->analyzedRows($rawRows, $fieldMap, $supplier);
        $summary = $this->summary($supplier, $source, $rows, $limit, $maxRows, $apply);
        $recordsChanged = $this->recordsChanged();
        $appliedRows = collect();

        if ($apply) {
            try {
                $applied = DB::transaction(fn (): array => $this->applyRows($rows, $supplier));
            } catch (Throwable $exception) {
                return [
                    'success' => false,
                    'mode' => 'apply',
                    'supplier' => $this->supplierPayload($supplier),
                    'source' => $this->sourcePayload($source),
                    'summary' => [
                        ...$summary,
                        'created' => 0,
                        'updated' => 0,
                        'skipped' => $summary['skipped'],
                        'safety_status' => 'failed_rolled_back',
                    ],
                    'preview_rows' => $this->displayRows($rows, $limit),
                    'applied_rows' => [],
                    'overlaps' => $this->overlaps($rows, $limit),
                    'issues' => [
                        ...$this->issues($rows),
                        [
                            'type' => 'apply',
                            'reason' => 'apply_failed_rolled_back',
                            'message' => $exception->getMessage(),
                        ],
                    ],
                    'records_changed' => $recordsChanged,
                ];
            }

            $summary['created'] = $applied['created'];
            $summary['updated'] = $applied['updated'];
            $summary['skipped'] = $applied['skipped'];
            $summary['safety_status'] = 'applied_supplier_products_only';
            $recordsChanged['supplier_products'] = $applied['created'] + $applied['updated'];
            $appliedRows = collect($applied['rows']);
        }

        return [
            'success' => true,
            'mode' => $apply ? 'apply' : 'dry_run',
            'supplier' => $this->supplierPayload($supplier),
            'source' => $this->sourcePayload($source),
            'summary' => $summary,
            'preview_rows' => $this->displayRows($rows, $limit),
            'applied_rows' => $appliedRows->take($limit)->values()->all(),
            'overlaps' => $this->overlaps($rows, $limit),
            'issues' => $this->issues($rows),
            'records_changed' => $recordsChanged,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{created: int, updated: int, skipped: int, rows: array<int, array<string, mixed>>}
     */
    protected function applyRows(Collection $rows, Supplier $supplier): array
    {
        $created = 0;
        $updated = 0;
        $appliedRows = [];

        foreach ($rows as $row) {
            if (! ($row['eligible_for_apply'] ?? false)) {
                continue;
            }

            $normalized = $row['normalized'];
            $attributes = $this->supplierProductAttributes($supplier, $row);

            $existing = SupplierProduct::query()
                ->where('supplier_id', $supplier->id)
                ->where('supplier_sku', $normalized['supplier_sku'])
                ->first();

            if ($existing instanceof SupplierProduct) {
                $existing->fill($attributes);
                $existing->save();
                $updated++;
                $action = 'updated';
                $id = $existing->id;
            } else {
                $createdProduct = SupplierProduct::query()->create([
                    'supplier_id' => $supplier->id,
                    'supplier_sku' => $normalized['supplier_sku'],
                    ...$attributes,
                ]);
                $created++;
                $action = 'created';
                $id = $createdProduct->id;
            }

            $appliedRows[] = [
                'row_index' => $row['row_index'],
                'supplier_product_id' => $id,
                'supplier_sku' => $normalized['supplier_sku'],
                'action' => $action,
                'needs_manual_review' => (bool) $row['needs_manual_review'],
            ];
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $rows->where('eligible_for_apply', false)->count(),
            'rows' => $appliedRows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function supplierProductAttributes(Supplier $supplier, array $row): array
    {
        $normalized = $row['normalized'];
        $issues = $row['issues'] ?? [];

        return [
            'ean' => $normalized['ean_gtin'],
            'mpn' => $normalized['mpn'],
            'name' => $normalized['name'],
            'brand_name' => $normalized['brand'],
            'category_name' => $normalized['category'],
            'price' => $normalized['price'],
            'supplier_price_raw' => Schema::hasColumn('supplier_products', 'supplier_price_raw') ? $normalized['price'] : null,
            'quantity' => $normalized['stock'],
            'external_availability_status' => $normalized['availability'],
            'external_availability_label' => $normalized['availability'],
            'currency' => $normalized['currency'] ?: 'EUR',
            'raw_data' => [
                'source' => 'controlled_staging_import',
                'supplier_key' => $this->supplierKey($supplier),
                'row_index' => $row['row_index'],
                'normalized' => $normalized,
                'raw' => $row['raw'],
                'issues' => $issues,
            ],
            'payload_hash' => sha1(json_encode([
                'supplier_id' => $supplier->id,
                'supplier_sku' => $normalized['supplier_sku'],
                'normalized' => $normalized,
            ], JSON_THROW_ON_ERROR)),
            'received_at' => now(),
            'status' => 'new',
            'mapping_notes' => $issues === [] ? null : 'controlled_staging_import_manual_review: '.implode(',', $issues),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rawRows
     * @param  array<string, string|null>  $fieldMap
     * @return Collection<int, array<string, mixed>>
     */
    private function analyzedRows(Collection $rawRows, array $fieldMap, Supplier $supplier): Collection
    {
        $normalizedRows = $rawRows
            ->values()
            ->map(function (array $row, int $index) use ($fieldMap): array {
                $normalized = [];

                foreach (array_keys(self::FIELD_ALIASES) as $field) {
                    $normalized[$field] = $this->cleanValue($this->mappedValue($row, $fieldMap[$field] ?? null));
                }

                $normalized['price'] = $this->decimalValue($normalized['price']);
                $normalized['stock'] = $this->integerValue($normalized['stock']);

                return [
                    'row_index' => $index + 1,
                    'raw' => $row,
                    'normalized' => $normalized,
                    'issues' => $this->rowIssues($normalized),
                ];
            });

        $normalizedRows = $this->markDuplicateIssues($normalizedRows);
        $existingRows = $this->existingSupplierProducts();

        return $normalizedRows
            ->map(function (array $row) use ($supplier, $existingRows): array {
                $sameSupplierSkuMatch = null;
                $overlaps = [];

                foreach ($existingRows as $existing) {
                    $match = $this->matchExistingSupplierProduct($existing, $row['normalized'], $supplier);

                    if ($match === null) {
                        continue;
                    }

                    $overlaps[] = $match;

                    if ($match['type'] === 'same_supplier_sku') {
                        $sameSupplierSkuMatch = $match;
                    }
                }

                $row['same_supplier_sku_match'] = $sameSupplierSkuMatch;
                $row['overlaps'] = $overlaps;
                $row['future_staging_action'] = $this->futureAction($row);
                $row['needs_manual_review'] = $this->needsManualReview($row);
                $row['eligible_for_apply'] = $this->isEligibleForApply($row);
                $row['skip_reason'] = $row['eligible_for_apply'] ? null : $this->skipReason($row);

                return $row;
            });
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
            'name' => 'missing_name',
            'ean_gtin' => 'missing_ean_gtin',
            'mpn' => 'missing_mpn',
            'price' => 'missing_price',
        ] as $field => $issue) {
            if (! $this->hasValue($normalized[$field] ?? null)) {
                $issues[] = $issue;
            }
        }

        if (! $this->hasValue($normalized['stock'] ?? null) && ! $this->hasValue($normalized['availability'] ?? null)) {
            $issues[] = 'missing_stock_availability';
        }

        if (! $this->hasValue($normalized['ean_gtin'] ?? null) && ! $this->hasValue($normalized['mpn'] ?? null)) {
            $issues[] = 'missing_ean_and_mpn';
        }

        return array_values(array_unique($issues));
    }

    private function futureAction(array $row): string
    {
        if (! $this->hasValue($row['normalized']['supplier_sku'] ?? null)) {
            return 'would_skip_row';
        }

        if ($this->hasDuplicateSupplierSkuIssue($row)) {
            return 'would_skip_row';
        }

        if (! $this->hasValue($row['normalized']['name'] ?? null) || ! $this->hasValue($row['normalized']['price'] ?? null)) {
            return 'would_skip_row';
        }

        if (! $this->hasValue($row['normalized']['stock'] ?? null) && ! $this->hasValue($row['normalized']['availability'] ?? null)) {
            return 'would_skip_row';
        }

        return is_array($row['same_supplier_sku_match'] ?? null)
            ? 'would_update_supplier_product'
            : 'would_create_supplier_product';
    }

    private function isEligibleForApply(array $row): bool
    {
        return in_array($row['future_staging_action'], ['would_create_supplier_product', 'would_update_supplier_product'], true);
    }

    private function needsManualReview(array $row): bool
    {
        return collect($row['issues'] ?? [])
            ->reject(fn (string $issue): bool => in_array($issue, [
                'missing_supplier_sku',
                'missing_name',
                'missing_price',
                'missing_stock_availability',
                'duplicate_supplier_sku_in_source',
            ], true))
            ->isNotEmpty();
    }

    private function skipReason(array $row): string
    {
        if (! $this->hasValue($row['normalized']['supplier_sku'] ?? null)) {
            return 'missing_supplier_sku';
        }

        if ($this->hasDuplicateSupplierSkuIssue($row)) {
            return 'duplicate_supplier_sku';
        }

        if (! $this->hasValue($row['normalized']['name'] ?? null)) {
            return 'missing_name';
        }

        if (! $this->hasValue($row['normalized']['price'] ?? null)) {
            return 'missing_price';
        }

        if (! $this->hasValue($row['normalized']['stock'] ?? null) && ! $this->hasValue($row['normalized']['availability'] ?? null)) {
            return 'missing_stock_availability';
        }

        return 'not_eligible';
    }

    private function hasDuplicateSupplierSkuIssue(array $row): bool
    {
        return in_array('duplicate_supplier_sku_in_source', $row['issues'] ?? [], true);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function markDuplicateIssues(Collection $rows): Collection
    {
        $duplicateSupplierSkus = $rows
            ->map(fn (array $row): ?string => $this->normalizedIdentifier($row['normalized']['supplier_sku'] ?? null))
            ->filter()
            ->countBy()
            ->filter(fn (int $count): bool => $count > 1)
            ->keys()
            ->map(fn (mixed $value): string => (string) $value)
            ->all();

        $duplicateEans = $this->duplicateIdentifiers($rows, 'ean_gtin');
        $duplicateMpns = $this->duplicateIdentifiers($rows, 'mpn');

        return $rows->map(function (array $row) use ($duplicateSupplierSkus, $duplicateEans, $duplicateMpns): array {
            $sku = $this->normalizedIdentifier($row['normalized']['supplier_sku'] ?? null);
            $ean = $this->normalizedIdentifier($row['normalized']['ean_gtin'] ?? null);
            $mpn = $this->normalizedIdentifier($row['normalized']['mpn'] ?? null);

            if ($sku !== null && in_array($sku, $duplicateSupplierSkus, true)) {
                $row['issues'][] = 'duplicate_supplier_sku_in_source';
            }

            if ($ean !== null && in_array($ean, $duplicateEans, true)) {
                $row['issues'][] = 'duplicate_ean_gtin_in_source';
            }

            if ($mpn !== null && in_array($mpn, $duplicateMpns, true)) {
                $row['issues'][] = 'duplicate_mpn_in_source';
            }

            $row['issues'] = array_values(array_unique($row['issues']));

            return $row;
        });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, string>
     */
    private function duplicateIdentifiers(Collection $rows, string $field): array
    {
        return $rows
            ->map(fn (array $row): ?string => $this->normalizedIdentifier($row['normalized'][$field] ?? null))
            ->filter()
            ->countBy()
            ->filter(fn (int $count): bool => $count > 1)
            ->keys()
            ->map(fn (mixed $value): string => (string) $value)
            ->all();
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
            ->select(['id', 'supplier_id', 'supplier_sku', 'ean', 'mpn', 'brand_name', 'name'])
            ->get();
    }

    private function matchExistingSupplierProduct(SupplierProduct $product, array $normalized, Supplier $supplier): ?array
    {
        $sameSupplier = (int) $product->supplier_id === (int) $supplier->id;

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

        return null;
    }

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
     * @return array<string, mixed>
     */
    private function summary(Supplier $supplier, array $source, Collection $rows, int $limit, int $maxRows, bool $apply): array
    {
        $validRows = $rows->where('eligible_for_apply', true);

        return [
            'supplier_id' => $supplier->id,
            'supplier_key' => $this->supplierKey($supplier),
            'supplier_name' => $supplier->company_name,
            'source_type' => $source['source_type'],
            'source_label' => $source['label'],
            'mode' => $apply ? 'apply' : 'dry_run',
            'rows_scanned' => $rows->count(),
            'rows_valid' => $validRows->count(),
            'rows_skipped' => $rows->where('eligible_for_apply', false)->count(),
            'rows_returned' => min($limit, $rows->count()),
            'display_limit' => $limit,
            'max_rows' => $maxRows,
            'would_create' => $rows->where('future_staging_action', 'would_create_supplier_product')->count(),
            'would_update' => $rows->where('future_staging_action', 'would_update_supplier_product')->count(),
            'created' => 0,
            'updated' => 0,
            'skipped' => $rows->where('eligible_for_apply', false)->count(),
            'manual_review' => $rows->where('needs_manual_review', true)->count(),
            'duplicate_rows' => $rows->filter(fn (array $row): bool => collect($row['issues'])->contains(fn (string $issue): bool => str_contains($issue, 'duplicate_')))->count(),
            'cross_supplier_matches' => collect($this->overlaps($rows, self::MAX_ROWS))->where('scope', 'cross_supplier')->count(),
            'catalog_sync_changed' => 0,
            'safety_status' => $apply ? 'ready_to_apply_supplier_products_only' : 'dry_run_no_writes',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function displayRows(Collection $rows, int $limit): array
    {
        return $rows
            ->take($limit)
            ->map(fn (array $row): array => [
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
                'future_staging_action' => $row['future_staging_action'],
                'eligible_for_apply' => $row['eligible_for_apply'],
                'needs_manual_review' => $row['needs_manual_review'],
                'skip_reason' => $row['skip_reason'],
                'issues' => $row['issues'],
                'overlap_count' => count($row['overlaps'] ?? []),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function overlaps(Collection $rows, int $limit): array
    {
        return $rows
            ->flatMap(fn (array $row): array => collect($row['overlaps'] ?? [])
                ->map(fn (array $overlap): array => [
                    'row_index' => $row['row_index'],
                    ...$overlap,
                ])
                ->all())
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function issues(Collection $rows): array
    {
        return $rows
            ->flatMap(fn (array $row): array => collect($row['issues'])
                ->map(fn (string $issue): array => [
                    'type' => 'row',
                    'row_index' => $row['row_index'],
                    'reason' => $issue,
                ])
                ->all())
            ->values()
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function recordsChanged(): array
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
            ->where(function ($query) use ($normalized): void {
                $query
                    ->whereRaw('LOWER(slug) = ?', [$normalized])
                    ->orWhereRaw('LOWER(company_name) = ?', [$normalized]);
            })
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveSource(string $source, string $fixture, string $sourceType): array
    {
        $candidate = $fixture !== '' ? $fixture : $source;

        if ($this->isRemoteSource($candidate)) {
            return [
                'success' => false,
                'issue' => 'remote_source_disabled',
                'message' => 'Remote feed fetching is disabled for controlled staging import. Provide a local file path.',
                'source_type' => $sourceType === 'auto' ? $this->inferSourceType($candidate) : $sourceType,
                'label' => $this->safeSourceLabel($candidate),
            ];
        }

        $path = $this->absolutePath($candidate);

        if (! is_file($path)) {
            return [
                'success' => false,
                'issue' => 'source_file_missing',
                'message' => 'Source file was not found.',
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
                'label' => $this->safeSourceLabel($candidate),
            ];
        }

        return [
            'success' => true,
            'path' => $path,
            'source_type' => $resolvedType,
            'label' => $this->safeSourceLabel($path),
        ];
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
        $keys = $rawRows
            ->flatMap(fn (array $row): array => array_keys($row))
            ->unique()
            ->values();
        $normalizedKeys = $keys->mapWithKeys(fn (string $key): array => [$this->normalizeKey($key) => $key]);

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

    private function hasValue(mixed $value): bool
    {
        if (is_string($value)) {
            return trim($value) !== '';
        }

        return $value !== null;
    }

    private function normalizedIdentifier(mixed $value): ?string
    {
        if (! $this->hasValue($value)) {
            return null;
        }

        return Str::of((string) $value)->ascii()->lower()->trim()->toString();
    }

    private function sameIdentifier(mixed $left, mixed $right): bool
    {
        return $this->normalizedIdentifier($left) !== null
            && $this->normalizedIdentifier($left) === $this->normalizedIdentifier($right);
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

    private function supplierKey(Supplier $supplier): string
    {
        return Str::lower($supplier->slug ?: Str::slug($supplier->company_name));
    }

    /**
     * @return array<string, mixed>
     */
    private function supplierPayload(?Supplier $supplier): array
    {
        return [
            'id' => $supplier?->id,
            'key' => $supplier instanceof Supplier ? $this->supplierKey($supplier) : null,
            'name' => $supplier?->company_name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sourcePayload(array $source): array
    {
        return [
            'type' => $source['source_type'] ?? 'unknown',
            'label' => $source['label'] ?? '-',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failure(string $reason, string $message, int $limit, bool $apply, ?Supplier $supplier = null, array $source = []): array
    {
        return [
            'success' => false,
            'mode' => $apply ? 'apply' : 'dry_run',
            'supplier' => $this->supplierPayload($supplier),
            'source' => $this->sourcePayload($source),
            'summary' => [
                'supplier_id' => $supplier?->id,
                'supplier_key' => $supplier instanceof Supplier ? $this->supplierKey($supplier) : null,
                'supplier_name' => $supplier?->company_name,
                'source_type' => $source['source_type'] ?? 'unknown',
                'source_label' => $source['label'] ?? '-',
                'mode' => $apply ? 'apply' : 'dry_run',
                'rows_scanned' => 0,
                'rows_valid' => 0,
                'rows_skipped' => 0,
                'rows_returned' => 0,
                'display_limit' => $limit,
                'max_rows' => self::MAX_ROWS,
                'would_create' => 0,
                'would_update' => 0,
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'manual_review' => 0,
                'duplicate_rows' => 0,
                'cross_supplier_matches' => 0,
                'catalog_sync_changed' => 0,
                'safety_status' => 'failed_no_writes',
            ],
            'preview_rows' => [],
            'applied_rows' => [],
            'overlaps' => [],
            'issues' => [[
                'type' => 'command',
                'reason' => $reason,
                'message' => $message,
            ]],
            'records_changed' => $this->recordsChanged(),
        ];
    }
}

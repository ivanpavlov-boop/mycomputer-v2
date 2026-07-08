<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;
use App\Models\SupplierCategoryMapping;
use App\Models\SupplierProduct;
use App\Services\Taxonomy\SupplierCategoryDiscoveryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MultiSupplierImportDiscoveryService
{
    public function __construct(private readonly SupplierCategoryDiscoveryService $categoryDiscovery) {}

    /**
     * @return array<string, mixed>
     */
    public function audit(
        ?string $supplier = null,
        int $limit = 50,
        bool $onlyWithIssues = false,
        bool $includeEmpty = false,
        bool $showCategories = false,
        bool $showIdentifiers = false,
        bool $showOverlaps = false,
    ): array {
        $limit = max(1, min($limit, 5000));
        $products = $this->stagedProducts($supplier);
        $suppliers = $this->suppliers($supplier, $includeEmpty || filled($supplier), $products);
        $categoryRows = $this->categoryRows($supplier, $includeEmpty);
        $overlapRows = $this->overlapRows($products);

        $allSupplierRows = $this->supplierRows($suppliers, $products, $categoryRows, $overlapRows);
        $returnedSupplierRows = $onlyWithIssues
            ? $allSupplierRows->filter(fn (array $row): bool => $row['readiness_status'] !== 'ready_for_mapping_review')->values()
            : $allSupplierRows;

        $limitedSupplierRows = $returnedSupplierRows->take($limit)->values();
        $filteredCategoryRows = $onlyWithIssues
            ? $categoryRows->filter(fn (array $row): bool => $row['mapping_status'] !== 'approved')->values()
            : $categoryRows;
        $limitedCategoryRows = $showCategories ? $filteredCategoryRows->take($limit)->values() : collect();
        $limitedOverlapRows = $showOverlaps ? $overlapRows->take($limit)->values() : collect();
        $recordsChanged = $this->recordsChanged();

        return [
            'summary' => [
                'suppliers_checked' => $allSupplierRows->count(),
                'suppliers_returned' => $returnedSupplierRows->count(),
                'staged_supplier_products' => $products->count(),
                'suppliers_with_staging' => $allSupplierRows->where('staged_supplier_products_count', '>', 0)->count(),
                'suppliers_without_staging' => $allSupplierRows->where('staged_supplier_products_count', 0)->count(),
                'ready_for_mapping_review' => $allSupplierRows->where('readiness_status', 'ready_for_mapping_review')->count(),
                'needs_identifier_cleanup' => $allSupplierRows->where('readiness_status', 'needs_identifier_cleanup')->count(),
                'needs_category_data' => $allSupplierRows->where('readiness_status', 'needs_category_data')->count(),
                'needs_manual_supplier_setup' => $allSupplierRows->where('readiness_status', 'needs_manual_supplier_setup')->count(),
                'no_staging_data' => $allSupplierRows->where('readiness_status', 'no_staging_data')->count(),
                'display_limit' => $limit,
                'records_changed' => $recordsChanged,
            ],
            'suppliers' => $limitedSupplierRows->all(),
            'category_summary' => $this->categorySummary($categoryRows),
            'categories' => $limitedCategoryRows->all(),
            'identifier_summary' => $this->identifierSummary($allSupplierRows, $overlapRows, $showIdentifiers),
            'overlap_summary' => $this->overlapSummary($overlapRows, $showOverlaps),
            'overlaps' => $limitedOverlapRows->all(),
            'issues' => $this->issues($allSupplierRows, $overlapRows)->take($limit)->values()->all(),
            'records_changed' => $recordsChanged,
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
            'supplier_category_mappings' => 0,
            'canonical_product_families' => 0,
            'category_product_attributes' => 0,
            'product_attributes' => 0,
            'attribute_values' => 0,
            'product_attribute_values' => 0,
        ];
    }

    /**
     * @return Collection<int, SupplierProduct>
     */
    private function stagedProducts(?string $supplier): Collection
    {
        if (! Schema::hasTable('supplier_products')) {
            return collect();
        }

        $query = SupplierProduct::query()
            ->select($this->supplierProductColumns())
            ->with('supplier');

        if (filled($supplier)) {
            $this->applySupplierProductFilter($query, (string) $supplier);
        }

        return $query
            ->orderBy('supplier_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, SupplierProduct>  $products
     * @return Collection<int, Supplier>
     */
    private function suppliers(?string $supplier, bool $includeEmpty, Collection $products): Collection
    {
        if (! Schema::hasTable('suppliers')) {
            return collect();
        }

        $query = Supplier::query()->orderBy('company_name')->orderBy('id');

        if (filled($supplier)) {
            $this->applySupplierFilter($query, (string) $supplier);
        }

        $suppliers = $query->get();

        if ($includeEmpty) {
            return $suppliers;
        }

        $supplierIdsWithProducts = $products
            ->pluck('supplier_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->all();

        return $suppliers
            ->filter(fn (Supplier $supplier): bool => in_array((int) $supplier->id, $supplierIdsWithProducts, true))
            ->values();
    }

    /**
     * @return array<int, string>
     */
    private function supplierProductColumns(): array
    {
        $preferredColumns = [
            'id',
            'supplier_id',
            'product_id',
            'supplier_sku',
            'ean',
            'mpn',
            'name',
            'brand_name',
            'category_name',
            'price',
            'supplier_price_raw',
            'quantity',
            'external_availability_status',
            'external_availability_label',
            'availability_status_id',
            'status',
        ];

        return collect($preferredColumns)
            ->filter(fn (string $column): bool => Schema::hasColumn('supplier_products', $column))
            ->values()
            ->all();
    }

    /**
     * @param  Builder<Supplier>  $query
     */
    private function applySupplierFilter(Builder $query, string $supplier): void
    {
        if (is_numeric($supplier)) {
            $query->where('id', (int) $supplier);

            return;
        }

        $normalized = Str::lower(trim($supplier));

        $query
            ->whereRaw('LOWER(slug) = ?', [$normalized])
            ->orWhereRaw('LOWER(company_name) = ?', [$normalized]);
    }

    /**
     * @param  Builder<SupplierProduct>  $query
     */
    private function applySupplierProductFilter(Builder $query, string $supplier): void
    {
        if (is_numeric($supplier)) {
            $query->where('supplier_id', (int) $supplier);

            return;
        }

        $normalized = Str::lower(trim($supplier));

        $query->whereHas('supplier', function (Builder $supplierQuery) use ($normalized): void {
            $supplierQuery
                ->whereRaw('LOWER(slug) = ?', [$normalized])
                ->orWhereRaw('LOWER(company_name) = ?', [$normalized]);
        });
    }

    /**
     * @param  Collection<int, Supplier>  $suppliers
     * @param  Collection<int, SupplierProduct>  $products
     * @param  Collection<int, array<string, mixed>>  $categoryRows
     * @param  Collection<int, array<string, mixed>>  $overlapRows
     * @return Collection<int, array<string, mixed>>
     */
    private function supplierRows(Collection $suppliers, Collection $products, Collection $categoryRows, Collection $overlapRows): Collection
    {
        $productsBySupplier = $products->groupBy(fn (SupplierProduct $product): int => (int) $product->supplier_id);
        $categoriesBySupplier = $categoryRows->groupBy(fn (array $row): int => (int) ($row['supplier_id'] ?? 0));
        $overlapValues = $this->overlapValueSets($overlapRows);

        return $suppliers
            ->map(function (Supplier $supplier) use ($productsBySupplier, $categoriesBySupplier, $overlapValues): array {
                $supplierProducts = $productsBySupplier->get((int) $supplier->id, collect())->values();
                $supplierCategories = $categoriesBySupplier->get((int) $supplier->id, collect())->values();
                $mappingCounts = $this->mappingStatusCounts($supplierCategories);
                $duplicateSupplierSkus = $this->duplicateValueCount($supplierProducts, 'supplier_sku');
                $duplicateEans = $this->duplicateValueCount($supplierProducts, 'ean');
                $duplicateMpns = $this->duplicateValueCount($supplierProducts, 'mpn');
                $productsMissingAllIdentifiers = $supplierProducts
                    ->filter(fn (SupplierProduct $product): bool => ! $this->hasAnyValue($product, ['supplier_sku', 'ean', 'mpn']))
                    ->count();
                $overlappingEans = $this->overlapCountForSupplier($supplierProducts, 'ean', $overlapValues['ean_gtin']);
                $overlappingMpns = $this->overlapCountForSupplier($supplierProducts, 'mpn', $overlapValues['mpn']);
                $row = [
                    'supplier_id' => (int) $supplier->id,
                    'supplier_key' => $this->supplierKey($supplier),
                    'supplier_name' => (string) $supplier->company_name,
                    'supplier_status' => $supplier->status ?? null,
                    'import_enabled' => Schema::hasColumn('suppliers', 'import_enabled') ? (bool) $supplier->import_enabled : null,
                    'schedule_enabled' => Schema::hasColumn('suppliers', 'schedule_enabled') ? (bool) $supplier->schedule_enabled : null,
                    'staged_supplier_products_count' => $supplierProducts->count(),
                    'products_with_supplier_sku' => $this->filledCount($supplierProducts, ['supplier_sku']),
                    'products_missing_supplier_sku' => $this->missingCount($supplierProducts, ['supplier_sku']),
                    'products_with_manufacturer_sku_mpn' => $this->filledCount($supplierProducts, ['mpn']),
                    'products_missing_mpn' => $this->missingCount($supplierProducts, ['mpn']),
                    'products_with_ean_gtin_barcode' => $this->filledCount($supplierProducts, ['ean']),
                    'products_missing_ean_gtin' => $this->missingCount($supplierProducts, ['ean']),
                    'products_with_brand_manufacturer' => $this->filledCount($supplierProducts, ['brand_name']),
                    'products_missing_brand' => $this->missingCount($supplierProducts, ['brand_name']),
                    'products_with_category_data' => $this->filledCount($supplierProducts, ['category_name']),
                    'products_without_category_data' => $this->missingCount($supplierProducts, ['category_name']),
                    'products_with_price' => $this->filledCount($supplierProducts, ['price', 'supplier_price_raw']),
                    'products_without_price' => $this->missingCount($supplierProducts, ['price', 'supplier_price_raw']),
                    'products_with_stock_availability' => $this->filledCount($supplierProducts, ['quantity', 'availability_status_id', 'external_availability_status', 'external_availability_label']),
                    'products_without_stock_availability' => $this->missingCount($supplierProducts, ['quantity', 'availability_status_id', 'external_availability_status', 'external_availability_label']),
                    'products_missing_all_primary_identifiers' => $productsMissingAllIdentifiers,
                    'distinct_supplier_categories_count' => $supplierProducts
                        ->map(fn (SupplierProduct $product): ?string => $this->normalizedValue($product->category_name ?? null))
                        ->filter()
                        ->unique()
                        ->count(),
                    'distinct_brands_count' => $supplierProducts
                        ->map(fn (SupplierProduct $product): ?string => $this->normalizedValue($product->brand_name ?? null))
                        ->filter()
                        ->unique()
                        ->count(),
                    'duplicate_supplier_sku_count_inside_supplier' => $duplicateSupplierSkus,
                    'duplicate_ean_gtin_count_inside_supplier' => $duplicateEans,
                    'duplicate_mpn_count_inside_supplier' => $duplicateMpns,
                    'overlapping_ean_gtin_with_other_suppliers' => $overlappingEans,
                    'overlapping_mpn_with_other_suppliers' => $overlappingMpns,
                    'category_mapping_status_counts' => $mappingCounts,
                ];

                $row['readiness_status'] = $this->readinessStatus(
                    $row,
                    $duplicateSupplierSkus,
                    $duplicateEans,
                    $duplicateMpns,
                    $productsMissingAllIdentifiers,
                );

                return $row;
            })
            ->values();
    }

    private function supplierKey(Supplier $supplier): ?string
    {
        if (filled($supplier->slug)) {
            return (string) $supplier->slug;
        }

        return $supplier->id !== null ? (string) $supplier->id : null;
    }

    /**
     * @param  Collection<int, SupplierProduct>  $products
     * @param  array<int, string>  $fields
     */
    private function filledCount(Collection $products, array $fields): int
    {
        return $products
            ->filter(fn (SupplierProduct $product): bool => $this->hasAnyValue($product, $fields))
            ->count();
    }

    /**
     * @param  Collection<int, SupplierProduct>  $products
     * @param  array<int, string>  $fields
     */
    private function missingCount(Collection $products, array $fields): int
    {
        return $products->count() - $this->filledCount($products, $fields);
    }

    /**
     * @param  array<int, string>  $fields
     */
    private function hasAnyValue(SupplierProduct $product, array $fields): bool
    {
        foreach ($fields as $field) {
            if ($this->hasValue($product->{$field} ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function hasValue(mixed $value): bool
    {
        if (is_string($value)) {
            return trim($value) !== '';
        }

        return $value !== null;
    }

    /**
     * @param  Collection<int, SupplierProduct>  $products
     */
    private function duplicateValueCount(Collection $products, string $field): int
    {
        return $products
            ->map(fn (SupplierProduct $product): ?string => $this->normalizedValue($product->{$field} ?? null))
            ->filter()
            ->countBy()
            ->filter(fn (int $count): bool => $count > 1)
            ->count();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function readinessStatus(array $row, int $duplicateSupplierSkus, int $duplicateEans, int $duplicateMpns, int $productsMissingAllIdentifiers): string
    {
        if ((int) $row['staged_supplier_products_count'] === 0) {
            return 'no_staging_data';
        }

        if (($row['supplier_status'] !== null && $row['supplier_status'] !== 'active') || $row['import_enabled'] === false) {
            return 'needs_manual_supplier_setup';
        }

        if ((int) $row['products_with_category_data'] === 0) {
            return 'needs_category_data';
        }

        if ($duplicateSupplierSkus > 0 || $duplicateEans > 0 || $duplicateMpns > 0 || $productsMissingAllIdentifiers > 0) {
            return 'needs_identifier_cleanup';
        }

        return 'ready_for_mapping_review';
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function categoryRows(?string $supplier, bool $includeEmpty): Collection
    {
        if (! Schema::hasTable('supplier_products')) {
            return collect();
        }

        return $this->categoryDiscovery
            ->candidates(
                supplier: filled($supplier) ? $supplier : null,
                includeEmpty: $includeEmpty,
            )
            ->map(function (array $row): array {
                $mappingStatus = $row['mapping_status'] ?? 'unmapped';

                return [
                    'supplier_id' => $row['supplier_id'],
                    'supplier_key' => $row['supplier_key'],
                    'supplier_name' => $row['supplier_name'],
                    'supplier_category_name' => $row['supplier_category_name'],
                    'supplier_category_path' => $row['supplier_category_path'],
                    'staged_products_count' => $row['product_count'],
                    'mapping_id' => $row['mapping_id'],
                    'mapping_status' => $mappingStatus,
                    'canonical_product_family' => $row['suggested_canonical_family'],
                    'confidence' => $row['confidence'],
                    'reviewed_status' => $this->reviewedStatus($mappingStatus),
                    'next_action' => $this->categoryNextAction($mappingStatus, $row['supplier_category_name']),
                ];
            })
            ->values();
    }

    private function reviewedStatus(string $mappingStatus): string
    {
        return in_array($mappingStatus, [
            SupplierCategoryMapping::STATUS_APPROVED,
            SupplierCategoryMapping::STATUS_REJECTED,
            SupplierCategoryMapping::STATUS_IGNORED,
        ], true) ? 'reviewed' : 'pending_review';
    }

    private function categoryNextAction(string $mappingStatus, string $categoryName): string
    {
        if ($categoryName === '(empty)') {
            return 'needs manual classification';
        }

        return match ($mappingStatus) {
            SupplierCategoryMapping::STATUS_PENDING_REVIEW => 'review pending mapping',
            SupplierCategoryMapping::STATUS_APPROVED => 'approve later',
            SupplierCategoryMapping::STATUS_REJECTED => 'needs manual classification',
            SupplierCategoryMapping::STATUS_IGNORED => 'ignored/rejected',
            default => 'create mapping candidate',
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $categoryRows
     * @return array<string, mixed>
     */
    private function categorySummary(Collection $categoryRows): array
    {
        return [
            'distinct_supplier_categories' => $categoryRows->count(),
            'mapping_status_counts' => $this->mappingStatusCounts($categoryRows),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $categoryRows
     * @return array<string, int>
     */
    private function mappingStatusCounts(Collection $categoryRows): array
    {
        $statuses = [
            SupplierCategoryMapping::STATUS_APPROVED,
            SupplierCategoryMapping::STATUS_PENDING_REVIEW,
            SupplierCategoryMapping::STATUS_REJECTED,
            SupplierCategoryMapping::STATUS_IGNORED,
            'unmapped',
        ];

        return collect($statuses)
            ->mapWithKeys(fn (string $status): array => [
                $status => $categoryRows->where('mapping_status', $status)->count(),
            ])
            ->all();
    }

    /**
     * @param  Collection<int, SupplierProduct>  $products
     * @return Collection<int, array<string, mixed>>
     */
    private function overlapRows(Collection $products): Collection
    {
        return collect()
            ->merge($this->identifierOverlapRows($products, 'ean_gtin', 'ean', 'high', 'future offer grouping review'))
            ->merge($this->identifierOverlapRows($products, 'mpn', 'mpn', 'medium', 'needs manual review'))
            ->merge($this->brandMpnOverlapRows($products))
            ->merge($this->nameOverlapRows($products))
            ->sortBy([
                ['confidence_rank', 'asc'],
                ['identifier_type', 'asc'],
                ['supplier_products_count', 'desc'],
                ['identifier_value', 'asc'],
            ])
            ->map(function (array $row): array {
                unset($row['confidence_rank']);

                return $row;
            })
            ->values();
    }

    /**
     * @param  Collection<int, SupplierProduct>  $products
     * @return Collection<int, array<string, mixed>>
     */
    private function identifierOverlapRows(Collection $products, string $type, string $field, string $confidence, string $nextAction): Collection
    {
        return $products
            ->filter(fn (SupplierProduct $product): bool => $this->hasValue($product->{$field} ?? null))
            ->groupBy(fn (SupplierProduct $product): string => (string) $this->normalizedValue($product->{$field} ?? null))
            ->filter(fn (Collection $group): bool => $group->pluck('supplier_id')->unique()->count() > 1)
            ->map(fn (Collection $group): array => $this->overlapRow($type, (string) $group->first()->{$field}, $group, $confidence, $nextAction))
            ->values();
    }

    /**
     * @param  Collection<int, SupplierProduct>  $products
     * @return Collection<int, array<string, mixed>>
     */
    private function brandMpnOverlapRows(Collection $products): Collection
    {
        return $products
            ->filter(fn (SupplierProduct $product): bool => $this->hasValue($product->brand_name ?? null) && $this->hasValue($product->mpn ?? null))
            ->groupBy(fn (SupplierProduct $product): string => $this->normalizedValue($product->brand_name).'|'.$this->normalizedValue($product->mpn))
            ->filter(fn (Collection $group): bool => $group->pluck('supplier_id')->unique()->count() > 1)
            ->map(fn (Collection $group): array => $this->overlapRow(
                'brand_mpn',
                trim((string) $group->first()->brand_name).' + '.trim((string) $group->first()->mpn),
                $group,
                'medium',
                'future offer grouping review',
            ))
            ->values();
    }

    /**
     * @param  Collection<int, SupplierProduct>  $products
     * @return Collection<int, array<string, mixed>>
     */
    private function nameOverlapRows(Collection $products): Collection
    {
        return $products
            ->filter(fn (SupplierProduct $product): bool => filled($this->normalizedName($product->name ?? null)))
            ->groupBy(fn (SupplierProduct $product): string => (string) $this->normalizedName($product->name ?? null))
            ->filter(fn (Collection $group): bool => $group->pluck('supplier_id')->unique()->count() > 1)
            ->map(fn (Collection $group): array => $this->overlapRow(
                'normalized_name',
                (string) $group->first()->name,
                $group,
                'low',
                'ignore weak match',
            ))
            ->values();
    }

    /**
     * @param  Collection<int, SupplierProduct>  $group
     * @return array<string, mixed>
     */
    private function overlapRow(string $type, string $value, Collection $group, string $confidence, string $nextAction): array
    {
        return [
            'identifier_type' => $type,
            'identifier_value' => $value,
            'suppliers_involved' => $group
                ->map(fn (SupplierProduct $product): string => $product->supplier?->company_name ?? 'Supplier #'.((string) $product->supplier_id))
                ->unique()
                ->values()
                ->all(),
            'supplier_products_count' => $group->count(),
            'sample_supplier_products' => $group
                ->take(3)
                ->map(fn (SupplierProduct $product): array => [
                    'supplier_product_id' => (int) $product->id,
                    'supplier_name' => $product->supplier?->company_name,
                    'supplier_sku' => $product->supplier_sku,
                    'name' => $product->name,
                ])
                ->values()
                ->all(),
            'confidence' => $confidence,
            'next_action' => $nextAction,
            'confidence_rank' => match ($confidence) {
                'high' => 1,
                'medium' => 2,
                default => 3,
            },
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $overlapRows
     * @return array<string, array<int, string>>
     */
    private function overlapValueSets(Collection $overlapRows): array
    {
        return [
            'ean_gtin' => $overlapRows
                ->where('identifier_type', 'ean_gtin')
                ->pluck('identifier_value')
                ->map(fn (mixed $value): ?string => $this->normalizedValue($value))
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'mpn' => $overlapRows
                ->where('identifier_type', 'mpn')
                ->pluck('identifier_value')
                ->map(fn (mixed $value): ?string => $this->normalizedValue($value))
                ->filter()
                ->unique()
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, SupplierProduct>  $products
     * @param  array<int, string>  $overlapValues
     */
    private function overlapCountForSupplier(Collection $products, string $field, array $overlapValues): int
    {
        return $products
            ->map(fn (SupplierProduct $product): ?string => $this->normalizedValue($product->{$field} ?? null))
            ->filter()
            ->unique()
            ->filter(fn (string $value): bool => in_array($value, $overlapValues, true))
            ->count();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $supplierRows
     * @param  Collection<int, array<string, mixed>>  $overlapRows
     * @return array<string, mixed>
     */
    private function identifierSummary(Collection $supplierRows, Collection $overlapRows, bool $showIdentifiers): array
    {
        $summary = [
            'total_staged_products' => (int) $supplierRows->sum('staged_supplier_products_count'),
            'products_with_supplier_sku' => (int) $supplierRows->sum('products_with_supplier_sku'),
            'products_missing_supplier_sku' => (int) $supplierRows->sum('products_missing_supplier_sku'),
            'products_with_ean_gtin' => (int) $supplierRows->sum('products_with_ean_gtin_barcode'),
            'products_missing_ean_gtin' => (int) $supplierRows->sum('products_missing_ean_gtin'),
            'products_with_mpn' => (int) $supplierRows->sum('products_with_manufacturer_sku_mpn'),
            'products_missing_mpn' => (int) $supplierRows->sum('products_missing_mpn'),
            'products_with_brand' => (int) $supplierRows->sum('products_with_brand_manufacturer'),
            'products_missing_brand' => (int) $supplierRows->sum('products_missing_brand'),
            'duplicate_supplier_sku_within_supplier' => (int) $supplierRows->sum('duplicate_supplier_sku_count_inside_supplier'),
            'duplicate_ean_gtin_within_supplier' => (int) $supplierRows->sum('duplicate_ean_gtin_count_inside_supplier'),
            'duplicate_mpn_within_supplier' => (int) $supplierRows->sum('duplicate_mpn_count_inside_supplier'),
            'same_ean_gtin_across_suppliers' => $overlapRows->where('identifier_type', 'ean_gtin')->count(),
            'same_mpn_across_suppliers' => $overlapRows->where('identifier_type', 'mpn')->count(),
            'same_brand_mpn_across_suppliers' => $overlapRows->where('identifier_type', 'brand_mpn')->count(),
            'same_normalized_name_across_suppliers' => $overlapRows->where('identifier_type', 'normalized_name')->count(),
        ];

        if (! $showIdentifiers) {
            return $summary;
        }

        $summary['per_supplier'] = $supplierRows
            ->map(fn (array $row): array => [
                'supplier_id' => $row['supplier_id'],
                'supplier_name' => $row['supplier_name'],
                'total_staged_products' => $row['staged_supplier_products_count'],
                'products_with_supplier_sku' => $row['products_with_supplier_sku'],
                'products_missing_supplier_sku' => $row['products_missing_supplier_sku'],
                'products_with_ean_gtin' => $row['products_with_ean_gtin_barcode'],
                'products_missing_ean_gtin' => $row['products_missing_ean_gtin'],
                'products_with_mpn' => $row['products_with_manufacturer_sku_mpn'],
                'products_missing_mpn' => $row['products_missing_mpn'],
                'products_with_brand' => $row['products_with_brand_manufacturer'],
                'products_missing_brand' => $row['products_missing_brand'],
                'potential_duplicate_supplier_sku_within_supplier' => $row['duplicate_supplier_sku_count_inside_supplier'],
                'potential_duplicate_ean_gtin_within_supplier' => $row['duplicate_ean_gtin_count_inside_supplier'],
                'potential_duplicate_mpn_within_supplier' => $row['duplicate_mpn_count_inside_supplier'],
            ])
            ->values()
            ->all();

        return $summary;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $overlapRows
     * @return array<string, mixed>
     */
    private function overlapSummary(Collection $overlapRows, bool $showOverlaps): array
    {
        $summary = [
            'total_overlap_groups' => $overlapRows->count(),
            'ean_gtin' => $overlapRows->where('identifier_type', 'ean_gtin')->count(),
            'mpn' => $overlapRows->where('identifier_type', 'mpn')->count(),
            'brand_mpn' => $overlapRows->where('identifier_type', 'brand_mpn')->count(),
            'normalized_name_low_confidence' => $overlapRows->where('identifier_type', 'normalized_name')->count(),
        ];

        if ($showOverlaps) {
            $summary['confidence_counts'] = [
                'high' => $overlapRows->where('confidence', 'high')->count(),
                'medium' => $overlapRows->where('confidence', 'medium')->count(),
                'low' => $overlapRows->where('confidence', 'low')->count(),
            ];
        }

        return $summary;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $supplierRows
     * @param  Collection<int, array<string, mixed>>  $overlapRows
     * @return Collection<int, array<string, mixed>>
     */
    private function issues(Collection $supplierRows, Collection $overlapRows): Collection
    {
        $supplierIssues = $supplierRows
            ->filter(fn (array $row): bool => $row['readiness_status'] !== 'ready_for_mapping_review')
            ->map(fn (array $row): array => [
                'type' => 'supplier_readiness',
                'supplier_id' => $row['supplier_id'],
                'supplier_name' => $row['supplier_name'],
                'reason' => $row['readiness_status'],
            ]);

        $overlapIssues = $overlapRows
            ->map(fn (array $row): array => [
                'type' => 'supplier_overlap',
                'identifier_type' => $row['identifier_type'],
                'identifier_value' => $row['identifier_value'],
                'confidence' => $row['confidence'],
                'next_action' => $row['next_action'],
            ]);

        return $supplierIssues
            ->merge($overlapIssues)
            ->values();
    }

    private function normalizedValue(mixed $value): ?string
    {
        if (! $this->hasValue($value)) {
            return null;
        }

        return Str::of((string) $value)
            ->ascii()
            ->lower()
            ->trim()
            ->replaceMatches('/\s+/', ' ')
            ->toString();
    }

    private function normalizedName(mixed $value): ?string
    {
        if (! $this->hasValue($value)) {
            return null;
        }

        $normalized = Str::of((string) $value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->toString();

        return Str::length($normalized) >= 8 ? $normalized : null;
    }
}

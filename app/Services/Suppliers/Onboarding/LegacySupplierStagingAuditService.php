<?php

namespace App\Services\Suppliers\Onboarding;

use App\Data\Suppliers\Onboarding\SupplierLegacyStagingAuditReport;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class LegacySupplierStagingAuditService
{
    private const TABLES = [
        'suppliers',
        'supplier_products',
        'products',
        'categories',
        'supplier_category_mappings',
        'canonical_product_families',
        'category_product_attributes',
        'product_attributes',
        'attribute_values',
        'product_attribute_values',
        'catalog_sync_batches',
        'catalog_sync_logs',
    ];

    /** @var array<string, bool> */
    private array $globalFlags;

    public function __construct()
    {
        $this->globalFlags = [
            'catalog_sync_create_enabled' => (bool) config('catalog_sync.create_enabled', true),
            'catalog_sync_update_enabled' => (bool) config('catalog_sync.update_enabled', false),
            'catalog_sync_sync_all_enabled' => (bool) config('catalog_sync.sync_all_enabled', false),
            'catalog_sync_auto_enabled' => (bool) config('catalog_sync.auto_enabled', false),
        ];
    }

    /** @param array<string, mixed> $options */
    public function audit(array $options): SupplierLegacyStagingAuditReport
    {
        $startedAt = microtime(true);
        $supplierInput = trim((string) ($options['supplier'] ?? ''));

        if ($supplierInput === '') {
            throw new InvalidArgumentException('supplier_required');
        }

        if (! $this->safeConfiguration($this->globalFlags)) {
            return $this->failureReport($supplierInput, 'unsafe_configuration', ['unsafe_configuration'], $startedAt);
        }

        $supplier = $this->resolveSupplier($supplierInput);
        $before = $this->tableCounts();
        $rows = $this->stagingRows((int) $supplier->id);
        $configuration = $this->configuration($supplier, $rows->count());
        $staging = $this->stagingInventory($supplier, $rows);
        $identifiers = (bool) ($options['include_identifier_diagnostics'] ?? false)
            ? $this->identifierDiagnostics($rows, (int) ($options['sample_limit'] ?? 20))
            : ['included' => false];
        $linked = (bool) ($options['include_linked_analysis'] ?? false)
            ? $this->linkedStateAnalysis($rows, $supplier)
            : ['included' => false];
        $comparison = (bool) ($options['include_catalog_comparison'] ?? false)
            ? $this->catalogComparison($rows, $supplier)
            : ['included' => false, 'equality_does_not_prove_overwrite' => true];
        $isolation = (bool) ($options['include_catalog_comparison'] ?? false)
            ? $this->catalogContentIsolation($rows)
            : ['included' => false];
        $mapping = (bool) ($options['include_mapping_analysis'] ?? false)
            ? $this->mappingState($rows, $supplier)
            : ['included' => false];
        $history = (bool) ($options['include_import_history'] ?? false)
            ? $this->importHistory($supplier)
            : ['included' => false];
        $schedule = $this->scheduleSafety($supplier, $rows->count(), $rows->whereNotNull('product_id')->count());
        $after = $this->tableCounts();
        $recordsChanged = $this->recordsChanged($before, $after);

        $blockers = [];
        $warnings = [];

        if ($schedule['schedule_must_be_frozen']) {
            $blockers[] = 'schedule_must_be_frozen';
        }

        if ($rows->count() > 0) {
            $warnings[] = 'staging_present_without_verification';
        }

        if ($linked['included'] && $linked['historical_causation_unknown']) {
            $warnings[] = 'historical_causation_unknown';
        }

        if (array_sum($recordsChanged) > 0) {
            $blockers[] = 'unexpected_mutation_detected';
        }

        $blockers = array_values(array_unique($blockers));
        $warnings = array_values(array_unique($warnings));
        $verdict = $blockers !== []
            ? (in_array('unsafe_configuration', $blockers, true) ? 'unsafe_configuration' : (in_array('schedule_must_be_frozen', $blockers, true) ? 'schedule_must_be_frozen' : 'audit_failed'))
            : ($rows->count() > 0 || $linked['included'] ? 'legacy_state_requires_review' : 'ready_for_source_profiling');

        return new SupplierLegacyStagingAuditReport(
            mode: 'legacy_staging_audit',
            supplier: [
                'id' => (int) $supplier->id,
                'key' => (string) ($supplier->slug ?: $supplier->id),
                'name' => (string) $supplier->company_name,
                'role' => Str::lower((string) $supplier->slug) === 'apcom' ? 'supplier_1_historically_integrated' : 'existing_supplier',
            ],
            configuration: $configuration,
            globalSafetyFlags: $this->globalFlags,
            stagingInventory: $staging,
            identifierDiagnostics: $identifiers,
            linkedStateAnalysis: $linked,
            catalogComparison: $comparison,
            catalogContentIsolation: $isolation,
            mappingState: $mapping,
            importHistory: $history,
            scheduleSafety: $schedule,
            verdict: $verdict,
            blockers: $blockers,
            warnings: $warnings,
            issueCounts: ['blockers' => count($blockers), 'warnings' => count($warnings)],
            issues: $this->issues($blockers, $warnings),
            recordsBefore: $before,
            recordsAfter: $after,
            recordsChanged: $recordsChanged,
            elapsedSeconds: $this->elapsedSeconds($startedAt),
            peakMemoryBytes: memory_get_peak_usage(true),
        );
    }

    /** @param array<string, bool> $flags */
    private function safeConfiguration(array $flags): bool
    {
        return $flags === [
            'catalog_sync_create_enabled' => true,
            'catalog_sync_update_enabled' => false,
            'catalog_sync_sync_all_enabled' => false,
            'catalog_sync_auto_enabled' => false,
        ];
    }

    private function resolveSupplier(string $value): object
    {
        if (! Schema::hasTable('suppliers')) {
            throw new InvalidArgumentException('invalid_supplier');
        }

        $columns = collect([
            'id', 'company_name', 'slug', 'status', 'import_enabled', 'schedule_enabled', 'schedule_type',
            'last_import_at', 'next_import_at',
        ])->filter(fn (string $column): bool => Schema::hasColumn('suppliers', $column))->values()->all();
        $query = DB::table('suppliers')->select($columns);

        if (is_numeric($value)) {
            $query->where('id', (int) $value);
        } else {
            $normalized = Str::lower($value);
            $query->where(function (Builder $supplier) use ($normalized): void {
                $supplier
                    ->whereRaw('LOWER(slug) = ?', [$normalized])
                    ->orWhereRaw('LOWER(company_name) = ?', [$normalized]);
            });
        }

        $supplier = $query->first();

        if (! $supplier) {
            throw new InvalidArgumentException('invalid_supplier');
        }

        return $supplier;
    }

    /** @return Collection<int, object> */
    private function stagingRows(int $supplierId): Collection
    {
        if (! Schema::hasTable('supplier_products')) {
            return collect();
        }

        $columns = collect([
            'id', 'supplier_id', 'supplier_feed_id', 'product_id', 'supplier_sku', 'ean', 'mpn', 'name',
            'brand_name', 'category_name', 'price', 'supplier_price_raw', 'quantity',
            'external_availability_status', 'external_availability_label', 'availability_status_id',
            'currency', 'payload_hash', 'received_at', 'synced_at', 'status', 'created_at', 'updated_at',
        ])->filter(fn (string $column): bool => Schema::hasColumn('supplier_products', $column))->values()->all();

        return DB::table('supplier_products')
            ->where('supplier_id', $supplierId)
            ->select($columns)
            ->orderBy('id')
            ->get();
    }

    /** @return array<string, mixed> */
    private function configuration(object $supplier, int $stagingCount): array
    {
        $feed = null;
        $feedTable = Schema::hasTable('supplier_feeds') ? DB::table('supplier_feeds')->where('supplier_id', $supplier->id)->select(['feed_type', 'feed_url'])->first() : null;
        $sourceConfigured = $feedTable !== null && filled($feedTable->feed_url ?? null);
        $authConfigured = false;
        $authKnown = false;

        if ($feedTable !== null && Schema::hasTable('supplier_feeds')) {
            $authKnown = true;
            $authConfigured = DB::table('supplier_feeds')
                ->where('supplier_id', $supplier->id)
                ->whereNotNull('username')
                ->where('username', '!=', '')
                ->whereNotNull('password')
                ->where('password', '!=', '')
                ->exists();
        }

        $sourceFormat = $feedTable?->feed_type ?: 'unknown';

        return [
            'supplier_key' => (string) ($supplier->slug ?: $supplier->id),
            'supplier_name' => (string) $supplier->company_name,
            'supplier_slug' => $supplier->slug,
            'active' => (string) ($supplier->status ?? '') === 'active',
            'import_enabled' => Schema::hasColumn('suppliers', 'import_enabled') ? (bool) $supplier->import_enabled : null,
            'schedule_enabled' => Schema::hasColumn('suppliers', 'schedule_enabled') ? (bool) $supplier->schedule_enabled : null,
            'schedule_type' => Schema::hasColumn('suppliers', 'schedule_type') ? $supplier->schedule_type : null,
            'source_format' => (string) $sourceFormat,
            'source_configured' => $sourceConfigured,
            'authentication_required' => $authKnown ? $authConfigured : null,
            'authentication_configured' => $authKnown ? $authConfigured : null,
            'driver_key' => $sourceFormat === 'xml' ? 'XmlImportEngine' : null,
            'last_import_at' => Schema::hasColumn('suppliers', 'last_import_at') ? $supplier->last_import_at : null,
            'next_import_at' => Schema::hasColumn('suppliers', 'next_import_at') ? $supplier->next_import_at : null,
            'staging_row_count' => $stagingCount,
            'linked_staging_row_count' => $this->countStagingWhere($supplier->id, 'product_id', 'not_null'),
            'unlinked_staging_row_count' => $this->countStagingWhere($supplier->id, 'product_id', 'null'),
        ];
    }

    /** @return array<string, mixed> */
    private function stagingInventory(object $supplier, Collection $rows): array
    {
        $query = DB::table('supplier_products')->where('supplier_id', $supplier->id);

        return [
            'total_rows' => $rows->count(),
            'linked_rows' => $rows->whereNotNull('product_id')->count(),
            'unlinked_rows' => $rows->whereNull('product_id')->count(),
            'null_product_id_count' => $rows->whereNull('product_id')->count(),
            'non_null_product_id_count' => $rows->whereNotNull('product_id')->count(),
            'status_counts' => $this->distribution($rows, 'status'),
            'supplier_feed_id_counts' => $this->nullCounts($rows, 'supplier_feed_id'),
            'synced_at_counts' => $this->nullCounts($rows, 'synced_at'),
            'received_at_range' => $this->range($rows, 'received_at'),
            'created_at_range' => $this->range($rows, 'created_at'),
            'updated_at_range' => $this->range($rows, 'updated_at'),
            'currency_counts' => $this->distribution($rows, 'currency'),
            'availability_status_counts' => $this->distribution($rows, 'availability_status_id'),
            'external_availability_status_counts' => $this->distribution($rows, 'external_availability_status'),
            'quantity' => $this->numericCounts($rows, 'quantity'),
            'price' => $this->numericCounts($rows, 'price'),
            'raw_data_counts' => [
                'with_raw_data' => Schema::hasColumn('supplier_products', 'raw_data') ? (clone $query)->whereNotNull('raw_data')->count() : 0,
                'without_raw_data' => Schema::hasColumn('supplier_products', 'raw_data') ? (clone $query)->whereNull('raw_data')->count() : $rows->count(),
            ],
            'payload_hash_counts' => $this->nullCounts($rows, 'payload_hash'),
        ];
    }

    /** @return array<string, mixed> */
    private function identifierDiagnostics(Collection $rows, int $sampleLimit): array
    {
        $sampleLimit = max(1, min($sampleLimit, 100));

        return [
            'supplier_sku' => $this->identifierField($rows, 'supplier_sku', $sampleLimit),
            'ean' => array_merge($this->identifierField($rows, 'ean', $sampleLimit), [
                'invalid_format_count' => $rows->filter(fn (object $row): bool => $this->filled($row->ean ?? null) && preg_match('/^\d{8,14}$/', trim((string) $row->ean)) !== 1)->count(),
            ]),
            'mpn' => $this->identifierField($rows, 'mpn', $sampleLimit),
            'brand_mpn_duplicate_groups' => $this->duplicateGroups($rows, ['brand_name', 'mpn'], $sampleLimit),
            'length_distributions' => [
                'supplier_sku' => $this->lengthDistribution($rows, 'supplier_sku'),
                'ean' => $this->lengthDistribution($rows, 'ean'),
                'mpn' => $this->lengthDistribution($rows, 'mpn'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function linkedStateAnalysis(Collection $rows, object $supplier): array
    {
        $linkedRows = $rows->filter(fn (object $row): bool => $this->filled($row->product_id ?? null))->values();
        $productIds = $linkedRows->pluck('product_id')->map(fn (mixed $id): int => (int) $id)->unique()->values();
        $products = $this->products($productIds->all());
        $productById = $products->keyBy('id');
        $orphanIds = $productIds->reject(fn (int $id): bool => $productById->has($id));
        $multiRows = $linkedRows->groupBy('product_id')->filter(fn (Collection $group): bool => $group->count() > 1);
        $softDeleted = $products->filter(fn (object $product): bool => filled($product->deleted_at ?? null))->count();
        $unexpectedStatuses = $linkedRows->filter(fn (object $row): bool => ! in_array(Str::lower(trim((string) ($row->status ?? ''))), ['new', 'staged', 'imported', 'synced', 'updated', 'active', 'processed', 'ready', 'pending'], true));
        $multiSupplierOffers = $this->multiSupplierOfferCount($productIds->all());
        $timingIncomplete = $linkedRows->filter(function (object $row) use ($productById): bool {
            $product = $productById->get((int) $row->product_id);

            return ! $product || blank($row->received_at ?? null) || blank($product->created_at ?? null) || blank($product->updated_at ?? null);
        })->count();

        return [
            'included' => true,
            'linked_staging_row_count' => $linkedRows->count(),
            'distinct_linked_catalog_product_count' => $productIds->count(),
            'orphan_product_id_reference_count' => $orphanIds->count(),
            'orphan_product_id_samples' => $this->hashSamples($orphanIds->all(), 20),
            'multiple_apcom_rows_linked_to_one_product_count' => $multiRows->count(),
            'catalog_products_with_multiple_supplier_offers_count' => $multiSupplierOffers,
            'links_to_soft_deleted_products_count' => $softDeleted,
            'linked_rows_with_synced_at' => $linkedRows->filter(fn (object $row): bool => filled($row->synced_at ?? null))->count(),
            'linked_rows_with_unexpected_status' => $this->distribution($unexpectedStatuses, 'status'),
            'catalog_product_status_distribution' => $this->distribution($products, 'product_status'),
            'publication_state_distribution' => [
                'workflow_status' => $this->distribution($products, 'workflow_status'),
                'active' => $this->distribution($products, 'active'),
                'published_at' => $this->nullCounts($products, 'published_at'),
            ],
            'linked_product_created_at_range' => $this->range($products, 'created_at'),
            'linked_product_updated_at_range' => $this->range($products, 'updated_at'),
            'staging_received_at_range' => $this->range($linkedRows, 'received_at'),
            'links_with_incomplete_timing' => $timingIncomplete,
            'historical_causation_unknown' => $timingIncomplete > 0 || $linkedRows->contains(fn (object $row): bool => blank($row->synced_at ?? null)),
            'supplier_key' => (string) ($supplier->slug ?: $supplier->id),
        ];
    }

    /** @return array<string, mixed> */
    private function catalogComparison(Collection $rows, object $supplier): array
    {
        $linkedRows = $rows->filter(fn (object $row): bool => $this->filled($row->product_id ?? null))->values();
        $products = $this->products($linkedRows->pluck('product_id')->map(fn (mixed $id): int => (int) $id)->unique()->all());
        $brands = $this->brandNames($products);
        $byId = $products->keyBy('id');
        $counts = [
            'exact_product_name_equality' => 0,
            'normalized_product_name_equality' => 0,
            'exact_brand_equality' => 0,
            'normalized_brand_equality' => 0,
            'matching_ean' => 0,
            'matching_mpn' => 0,
            'catalog_product_missing_ean_while_supplier_has_ean' => 0,
            'catalog_product_missing_mpn_while_supplier_has_mpn' => 0,
            'comparable_price_equality' => 0,
            'comparable_price_difference' => 0,
            'price_comparison_not_semantically_valid' => 0,
        ];

        foreach ($linkedRows as $row) {
            $product = $byId->get((int) $row->product_id);

            if (! $product) {
                continue;
            }

            $supplierName = trim((string) ($row->name ?? ''));
            $catalogName = trim((string) ($product->name ?? ''));
            $supplierBrand = trim((string) ($row->brand_name ?? ''));
            $catalogBrand = trim((string) ($brands[(int) ($product->brand_id ?? 0)] ?? ''));

            if ($supplierName !== '' && $supplierName === $catalogName) {
                $counts['exact_product_name_equality']++;
            }
            if ($supplierName !== '' && $this->normalized($supplierName) === $this->normalized($catalogName)) {
                $counts['normalized_product_name_equality']++;
            }
            if ($supplierBrand !== '' && $supplierBrand === $catalogBrand) {
                $counts['exact_brand_equality']++;
            }
            if ($supplierBrand !== '' && $this->normalized($supplierBrand) === $this->normalized($catalogBrand)) {
                $counts['normalized_brand_equality']++;
            }
            if ($this->filled($row->ean ?? null) && (string) $row->ean === (string) ($product->ean ?? '')) {
                $counts['matching_ean']++;
            }
            if ($this->filled($row->mpn ?? null) && (string) $row->mpn === (string) ($product->mpn ?? '')) {
                $counts['matching_mpn']++;
            }
            if ($this->filled($row->ean ?? null) && blank($product->ean ?? null)) {
                $counts['catalog_product_missing_ean_while_supplier_has_ean']++;
            }
            if ($this->filled($row->mpn ?? null) && blank($product->mpn ?? null)) {
                $counts['catalog_product_missing_mpn_while_supplier_has_mpn']++;
            }

            $supplierPrice = is_numeric($row->price ?? null) ? (float) $row->price : null;
            $catalogPrice = is_numeric($product->price ?? null) ? (float) $product->price : null;

            if ($supplierPrice === null || $catalogPrice === null || $supplierPrice <= 0 || $catalogPrice <= 0) {
                $counts['price_comparison_not_semantically_valid']++;
            } elseif (abs($supplierPrice - $catalogPrice) < 0.005) {
                $counts['comparable_price_equality']++;
            } else {
                $counts['comparable_price_difference']++;
            }
        }

        return [
            'included' => true,
            'linked_rows_compared' => $linkedRows->count(),
            'indicators' => $counts,
            'equality_does_not_prove_overwrite' => true,
            'interpretation' => 'Exact or normalized equality does not prove that supplier content overwrote catalog content.',
            'supplier_key' => (string) ($supplier->slug ?: $supplier->id),
        ];
    }

    /** @return array<string, mixed> */
    private function catalogContentIsolation(Collection $rows): array
    {
        $linkedIds = $rows->whereNotNull('product_id')->pluck('product_id')->map(fn (mixed $id): int => (int) $id)->unique()->values();
        $products = $this->products($linkedIds->all());
        $total = $linkedIds->count();
        $fields = [
            'name' => $products->filter(fn (object $product): bool => $this->filled($product->name ?? null))->count(),
            'slug' => $products->filter(fn (object $product): bool => $this->filled($product->slug ?? null))->count(),
            'description' => $products->filter(fn (object $product): bool => $this->filled($product->description ?? null))->count(),
            'short_description' => $products->filter(fn (object $product): bool => $this->filled($product->short_description ?? null))->count(),
            'seo_title' => $products->filter(fn (object $product): bool => $this->filled($product->meta_title ?? null))->count(),
            'seo_description' => $products->filter(fn (object $product): bool => $this->filled($product->meta_description ?? null))->count(),
            'categories' => $products->filter(fn (object $product): bool => $this->filled($product->category_id ?? null))->count(),
            'brand' => $products->filter(fn (object $product): bool => $this->filled($product->brand_id ?? null))->count(),
            'ean' => $products->filter(fn (object $product): bool => $this->filled($product->ean ?? null))->count(),
            'mpn' => $products->filter(fn (object $product): bool => $this->filled($product->mpn ?? null))->count(),
        ];
        $imageIds = $this->relatedProductIds('product_images', 'product_id', $linkedIds->all());
        $attributeIds = $this->relatedProductIds('product_attribute_values', 'product_id', $linkedIds->all());
        $fields['images'] = count($imageIds);
        $fields['attributes'] = count($attributeIds);

        return [
            'included' => true,
            'linked_catalog_products' => $total,
            'present_counts' => $fields,
            'missing_counts' => collect($fields)->map(fn (int $count): int => max(0, $total - $count))->all(),
            'supplier_content_copied' => false,
            'catalog_owned_fields_modified' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function mappingState(Collection $rows, object $supplier): array
    {
        $mappings = Schema::hasTable('supplier_category_mappings')
            ? DB::table('supplier_category_mappings')->where('supplier_id', $supplier->id)->select(['status', 'canonical_product_family_id', 'supplier_category_name'])->get()
            : collect();
        $supplierCategories = $rows->pluck('category_name')->filter(fn (mixed $value): bool => $this->filled($value))->map(fn (mixed $value): string => $this->normalized((string) $value))->unique();
        $mappedCategories = $mappings->pluck('supplier_category_name')->filter(fn (mixed $value): bool => $this->filled($value))->map(fn (mixed $value): string => $this->normalized((string) $value))->unique();
        $linkedIds = $rows->whereNotNull('product_id')->pluck('product_id')->map(fn (mixed $id): int => (int) $id)->unique()->values();
        $productRows = $this->products($linkedIds->all());
        $categoryIds = $productRows->pluck('category_id')->filter()->map(fn (mixed $id): int => (int) $id)->unique()->all();
        $productAttributeRows = $this->rowsForIds('product_attribute_values', 'product_id', $linkedIds->all(), ['id', 'product_attribute_id', 'attribute_value_id']);
        $categoryAttributeCount = $this->countForIds('category_product_attributes', 'category_id', $categoryIds);

        return [
            'included' => true,
            'supplier_category_mapping_count' => $mappings->count(),
            'mapping_status_counts' => $this->distribution($mappings, 'status'),
            'approved_mapping_count' => $mappings->where('status', 'approved')->count(),
            'pending_mapping_count' => $mappings->where('status', 'pending_review')->count(),
            'rejected_mapping_count' => $mappings->where('status', 'rejected')->count(),
            'unmapped_distinct_supplier_category_count' => $supplierCategories->reject(fn (string $category): bool => $mappedCategories->contains($category))->count(),
            'canonical_product_family_count' => $mappings->pluck('canonical_product_family_id')->filter()->unique()->count(),
            'category_product_attribute_count' => $categoryAttributeCount,
            'product_attribute_count' => $productAttributeRows->pluck('product_attribute_id')->filter()->unique()->count(),
            'product_attribute_value_count' => $productAttributeRows->count(),
            'attribute_value_count' => $productAttributeRows->pluck('attribute_value_id')->filter()->unique()->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function importHistory(object $supplier): array
    {
        $runs = Schema::hasTable('supplier_import_runs')
            ? DB::table('supplier_import_runs')->where('supplier_id', $supplier->id)->select(['status', 'started_at', 'finished_at', 'products_seen', 'products_created', 'products_updated', 'products_skipped', 'products_failed', 'created_at'])->orderByDesc('created_at')->get()
            : collect();
        $recent = $runs->filter(fn (object $run): bool => filled($run->created_at ?? null) && (string) $run->created_at >= now()->subDays(30)->toDateTimeString());
        $lastSuccessful = $runs->first(fn (object $run): bool => in_array($run->status, ['completed', 'completed_with_warnings'], true));
        $lastFailed = $runs->first(fn (object $run): bool => $run->status === 'failed');
        $historyEvents = Schema::hasTable('import_histories') ? DB::table('import_histories')->where('supplier_id', $supplier->id)->count() : 0;
        $catalogLogCount = Schema::hasTable('catalog_sync_logs') ? DB::table('catalog_sync_logs')->where('supplier_id', $supplier->id)->count() : 0;
        $catalogBatchCount = Schema::hasTable('catalog_sync_batches') ? DB::table('catalog_sync_batches')->where('supplier_id', $supplier->id)->count() : 0;

        return [
            'included' => true,
            'recent_window_days' => 30,
            'recent_import_run_count' => $recent->count(),
            'recent_successful_run_count' => $recent->whereIn('status', ['completed', 'completed_with_warnings'])->count(),
            'recent_failed_run_count' => $recent->where('status', 'failed')->count(),
            'recent_partial_run_count' => $recent->where('status', 'completed_with_warnings')->count(),
            'last_known_successful_run' => $lastSuccessful ? ['status' => $lastSuccessful->status, 'started_at' => $lastSuccessful->started_at, 'finished_at' => $lastSuccessful->finished_at] : null,
            'last_known_failed_run' => $lastFailed ? ['status' => $lastFailed->status, 'started_at' => $lastFailed->started_at, 'finished_at' => $lastFailed->finished_at] : null,
            'rows_affected_totals' => [
                'products_seen' => (int) $runs->sum('products_seen'),
                'products_created' => (int) $runs->sum('products_created'),
                'products_updated' => (int) $runs->sum('products_updated'),
                'products_skipped' => (int) $runs->sum('products_skipped'),
                'products_failed' => (int) $runs->sum('products_failed'),
            ],
            'import_history_event_count' => $historyEvents,
            'catalog_sync_batch_count' => $catalogBatchCount,
            'catalog_sync_log_count' => $catalogLogCount,
            'historical_provenance_complete' => $runs->count() > 0 && $runs->every(fn (object $run): bool => filled($run->status)),
            'historical_provenance_unknown' => $runs->count() === 0 || $historyEvents === 0,
        ];
    }

    /** @return array<string, mixed> */
    private function scheduleSafety(object $supplier, int $stagingCount, int $linkedCount): array
    {
        $enabled = Schema::hasColumn('suppliers', 'schedule_enabled') ? (bool) $supplier->schedule_enabled : false;
        $importEnabled = Schema::hasColumn('suppliers', 'import_enabled') ? (bool) $supplier->import_enabled : null;
        $unverified = $stagingCount > 0;
        $linked = $linkedCount > 0;
        $freeze = $enabled && $unverified && $linked;

        return [
            'schedule_enabled' => $enabled,
            'schedule_type' => Schema::hasColumn('suppliers', 'schedule_type') ? $supplier->schedule_type : null,
            'import_enabled' => $importEnabled,
            'unverified_staging_exists' => $unverified,
            'linked_staging_exists' => $linked,
            'schedule_can_change_supplier_products_during_audit' => $enabled && $importEnabled !== false,
            'next_reported_run' => Schema::hasColumn('suppliers', 'next_import_at') ? $supplier->next_import_at : null,
            'last_scheduled_import_at' => Schema::hasColumn('suppliers', 'last_import_at') ? $supplier->last_import_at : null,
            'required_recommendation' => $freeze ? 'freeze_schedule_before_production_audit' : null,
            'schedule_must_be_frozen' => $freeze,
            'schedule_was_modified' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function products(array $ids): Collection
    {
        if ($ids === [] || ! Schema::hasTable('products')) {
            return collect();
        }

        $columns = collect([
            'id', 'brand_id', 'category_id', 'name', 'slug', 'ean', 'mpn', 'price', 'product_status',
            'workflow_status', 'active', 'published_at', 'description', 'short_description', 'meta_title',
            'meta_description', 'created_at', 'updated_at', 'deleted_at',
        ])->filter(fn (string $column): bool => Schema::hasColumn('products', $column))->values()->all();

        return DB::table('products')->whereIn('id', $ids)->select($columns)->get();
    }

    /** @return array<int, string> */
    private function brandNames(Collection $products): array
    {
        if (! Schema::hasTable('brands')) {
            return [];
        }

        $ids = $products->pluck('brand_id')->filter()->map(fn (mixed $id): int => (int) $id)->unique()->all();

        if ($ids === []) {
            return [];
        }

        return DB::table('brands')->whereIn('id', $ids)->pluck('name', 'id')->map(fn (mixed $name): string => (string) $name)->all();
    }

    /** @return array<int, string> */
    private function relatedProductIds(string $table, string $column, array $ids): array
    {
        if ($ids === [] || ! Schema::hasTable($table)) {
            return [];
        }

        return DB::table($table)->whereIn($column, $ids)->distinct()->pluck($column)->map(fn (mixed $id): int => (int) $id)->all();
    }

    /** @return Collection<int, object> */
    private function rowsForIds(string $table, string $column, array $ids, array $select): Collection
    {
        if ($ids === [] || ! Schema::hasTable($table)) {
            return collect();
        }

        $select = collect($select)->filter(fn (string $field): bool => Schema::hasColumn($table, $field))->values()->all();

        return $select === [] ? collect() : DB::table($table)->whereIn($column, $ids)->select($select)->get();
    }

    private function countForIds(string $table, string $column, array $ids): int
    {
        return $ids === [] || ! Schema::hasTable($table) ? 0 : DB::table($table)->whereIn($column, $ids)->count();
    }

    private function multiSupplierOfferCount(array $productIds): int
    {
        if ($productIds === [] || ! Schema::hasTable('product_supplier_offers')) {
            return 0;
        }

        return DB::table('product_supplier_offers')
            ->whereIn('product_id', $productIds)
            ->select('product_id')
            ->groupBy('product_id')
            ->havingRaw('COUNT(DISTINCT supplier_id) > 1')
            ->get()
            ->count();
    }

    /** @return array<string, int> */
    private function tableCounts(): array
    {
        $counts = [];

        foreach (self::TABLES as $table) {
            $counts[$table] = Schema::hasTable($table) ? (int) DB::table($table)->count() : 0;
        }

        $counts['catalog_sync'] = 0;

        return $counts;
    }

    /** @param array<string, int> $before @param array<string, int> $after @return array<string, int> */
    private function recordsChanged(array $before, array $after): array
    {
        $changed = [];

        foreach ($before as $table => $count) {
            $changed[$table] = abs((int) ($after[$table] ?? 0) - $count);
        }

        return $changed;
    }

    private function countStagingWhere(int $supplierId, string $column, string $mode): int
    {
        if (! Schema::hasTable('supplier_products') || ! Schema::hasColumn('supplier_products', $column)) {
            return 0;
        }

        $query = DB::table('supplier_products')->where('supplier_id', $supplierId);

        return $mode === 'null' ? $query->whereNull($column)->count() : $query->whereNotNull($column)->count();
    }

    /** @return array<string, int> */
    private function nullCounts(Collection $rows, string $column): array
    {
        $null = $rows->filter(fn (object $row): bool => blank($row->{$column} ?? null))->count();

        return ['null_or_blank' => $null, 'non_null_or_non_blank' => $rows->count() - $null];
    }

    /** @return array<string, int> */
    private function numericCounts(Collection $rows, string $column): array
    {
        $values = $rows->pluck($column)->filter(fn (mixed $value): bool => $value !== null && $value !== '');

        return [
            'null' => $rows->count() - $values->count(),
            'zero' => $values->filter(fn (mixed $value): bool => (float) $value === 0.0)->count(),
            'positive' => $values->filter(fn (mixed $value): bool => (float) $value > 0)->count(),
            'negative' => $values->filter(fn (mixed $value): bool => (float) $value < 0)->count(),
        ];
    }

    /** @return array<string, int> */
    private function distribution(Collection $rows, string $column): array
    {
        return $rows->map(function (object $row) use ($column): string {
            $value = $row->{$column} ?? null;

            return $this->filled($value) ? $this->safeLabel($value) : '(null_or_blank)';
        })->countBy()->sortKeys()->take(100)->all();
    }

    /** @return array{minimum: ?string, maximum: ?string} */
    private function range(Collection $rows, string $column): array
    {
        $values = $rows->pluck($column)->filter(fn (mixed $value): bool => filled($value))->map(fn (mixed $value): string => (string) $value)->sort()->values();

        return ['minimum' => $values->first(), 'maximum' => $values->last()];
    }

    /** @return array<string, mixed> */
    private function identifierField(Collection $rows, string $column, int $sampleLimit): array
    {
        return [
            'null_count' => $rows->filter(fn (object $row): bool => ($row->{$column} ?? null) === null)->count(),
            'blank_count' => $rows->filter(fn (object $row): bool => $row->{$column} !== null && trim((string) $row->{$column}) === '')->count(),
            'duplicate_groups' => $this->duplicateGroups($rows, [$column], $sampleLimit),
            'case_normalized_duplicate_groups' => $this->duplicateGroups($rows, [$column], $sampleLimit, true, false),
            'whitespace_normalized_duplicate_groups' => $this->duplicateGroups($rows, [$column], $sampleLimit, true, true),
            'maximum_length' => $rows->map(fn (object $row): int => strlen(trim((string) ($row->{$column} ?? ''))))->max() ?? 0,
        ];
    }

    /** @param array<int, string> $columns @return array<string, mixed> */
    private function duplicateGroups(Collection $rows, array $columns, int $sampleLimit, bool $lower = false, bool $collapseWhitespace = false): array
    {
        $groups = $rows->filter(function (object $row) use ($columns): bool {
            foreach ($columns as $column) {
                if (! $this->filled($row->{$column} ?? null)) {
                    return false;
                }
            }

            return true;
        })->groupBy(function (object $row) use ($columns, $lower, $collapseWhitespace): string {
            $values = [];
            foreach ($columns as $column) {
                $value = trim((string) $row->{$column});
                if ($collapseWhitespace) {
                    $value = preg_replace('/\s+/', ' ', $value) ?: $value;
                }
                $values[] = $lower ? Str::lower($value) : $value;
            }

            return implode('|', $values);
        });
        $duplicates = $groups->filter(fn (Collection $group): bool => $group->count() > 1);

        return [
            'group_count' => $duplicates->count(),
            'duplicate_row_count' => $duplicates->sum(fn (Collection $group): int => $group->count()),
            'hashed_samples' => $duplicates->keys()->take($sampleLimit)->map(fn (string $key): string => hash('sha256', $key))->values()->all(),
        ];
    }

    /** @return array<string, int> */
    private function lengthDistribution(Collection $rows, string $column): array
    {
        $buckets = ['0' => 0, '1-10' => 0, '11-20' => 0, '21-50' => 0, '51+' => 0];

        foreach ($rows as $row) {
            $length = strlen(trim((string) ($row->{$column} ?? '')));
            $bucket = $length === 0 ? '0' : ($length <= 10 ? '1-10' : ($length <= 20 ? '11-20' : ($length <= 50 ? '21-50' : '51+')));
            $buckets[$bucket]++;
        }

        return $buckets;
    }

    /** @param array<int, mixed> $values @return array<int, string> */
    private function hashSamples(array $values, int $limit): array
    {
        return collect($values)->take(max(1, min($limit, 100)))->map(fn (mixed $value): string => hash('sha256', (string) $value))->values()->all();
    }

    private function normalized(string $value): string
    {
        return Str::of($value)->ascii()->lower()->trim()->replaceMatches('/\s+/', ' ')->toString();
    }

    private function safeLabel(mixed $value): string
    {
        return Str::limit(trim((string) $value), 100, '...');
    }

    private function filled(mixed $value): bool
    {
        return is_string($value) ? trim($value) !== '' : $value !== null;
    }

    /** @param array<int, string> $blockers @param array<int, string> $warnings @return array<int, array<string, string>> */
    private function issues(array $blockers, array $warnings): array
    {
        return array_merge(
            array_map(fn (string $code): array => ['code' => $code, 'severity' => 'blocker'], $blockers),
            array_map(fn (string $code): array => ['code' => $code, 'severity' => 'warning'], $warnings),
        );
    }

    private function failureReport(string $supplier, string $verdict, array $blockers, float $startedAt): SupplierLegacyStagingAuditReport
    {
        return new SupplierLegacyStagingAuditReport(
            mode: 'legacy_staging_audit',
            supplier: ['key' => $supplier, 'name' => null, 'role' => null],
            configuration: [],
            globalSafetyFlags: $this->globalFlags,
            stagingInventory: [],
            identifierDiagnostics: [],
            linkedStateAnalysis: [],
            catalogComparison: [],
            catalogContentIsolation: [],
            mappingState: [],
            importHistory: [],
            scheduleSafety: ['schedule_was_modified' => false],
            verdict: $verdict,
            blockers: $blockers,
            warnings: [],
            issueCounts: ['blockers' => count($blockers), 'warnings' => 0],
            issues: $this->issues($blockers, []),
            recordsBefore: array_fill_keys([...self::TABLES, 'catalog_sync'], 0),
            recordsAfter: array_fill_keys([...self::TABLES, 'catalog_sync'], 0),
            recordsChanged: array_fill_keys([...self::TABLES, 'catalog_sync'], 0),
            elapsedSeconds: $this->elapsedSeconds($startedAt),
            peakMemoryBytes: memory_get_peak_usage(true),
        );
    }

    private function elapsedSeconds(float $startedAt): float
    {
        return round(max(0.0001, microtime(true) - $startedAt), 6);
    }
}

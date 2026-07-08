<?php

namespace App\Services\Suppliers;

use App\Jobs\ProcessSupplierImportRunJob;
use App\Jobs\ProcessXmlSupplierFeed;
use App\Jobs\RunSupplierImportJob;
use App\Models\Supplier;
use App\Models\SupplierFeed;
use App\Models\SupplierImportRun;
use App\Models\SupplierProduct;
use App\Models\XmlMappingTemplate;
use App\Services\Imports\XmlImportEngine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SupplierImportCapabilityAuditService
{
    private const SENSITIVE_KEYS = [
        'api_key',
        'apikey',
        'auth',
        'bearer',
        'key',
        'pass',
        'password',
        'secret',
        'signature',
        'token',
    ];

    public function __construct(private readonly SupplierImportScheduleService $schedule) {}

    /**
     * @return array<string, mixed>
     */
    public function audit(
        ?string $supplier = null,
        int $limit = 50,
        bool $includeDisabled = false,
        bool $onlyWithIssues = false,
        bool $showDrivers = false,
        bool $showSchedules = false,
        bool $showConfig = false,
        bool $showChecklist = false,
    ): array {
        $limit = max(1, min($limit, 5000));
        $suppliers = $this->suppliers($supplier, $includeDisabled);
        $supplierIds = $suppliers->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $feeds = $this->feeds($supplierIds);
        $runs = $this->latestRuns($supplierIds);
        $staging = $this->stagingSummary($supplierIds);

        $allSupplierRows = $this->supplierRows($suppliers, $feeds, $runs, $staging);
        $returnedSupplierRows = $onlyWithIssues
            ? $allSupplierRows->filter(fn (array $row): bool => $row['issues'] !== [])->values()
            : $allSupplierRows;

        $recordsChanged = $this->recordsChanged();

        return [
            'summary' => [
                'suppliers_checked' => $allSupplierRows->count(),
                'suppliers_returned' => $returnedSupplierRows->count(),
                'active_suppliers' => $allSupplierRows->where('supplier_status', 'active')->count(),
                'disabled_suppliers' => $allSupplierRows->filter(fn (array $row): bool => ! $row['is_active'] || ! $row['import_enabled'])->count(),
                'suppliers_with_feed' => $allSupplierRows->where('feed_configured', true)->count(),
                'suppliers_missing_feed_url' => $allSupplierRows->filter(fn (array $row): bool => in_array('missing_feed_url', $row['issues'], true))->count(),
                'suppliers_missing_import_driver' => $allSupplierRows->filter(fn (array $row): bool => in_array('missing_import_driver', $row['issues'], true))->count(),
                'suppliers_with_schedule_enabled' => $allSupplierRows->where('schedule_enabled', true)->count(),
                'staged_supplier_products' => (int) $allSupplierRows->sum('staged_supplier_products_count'),
                'display_limit' => $limit,
                'records_changed' => $recordsChanged,
            ],
            'suppliers' => $returnedSupplierRows->take($limit)->values()->all(),
            'drivers' => $showDrivers ? $this->driverCapabilities() : [],
            'schedules' => $showSchedules ? $this->scheduleRows($allSupplierRows)->take($limit)->values()->all() : [],
            'config' => $showConfig ? $this->configRows($returnedSupplierRows)->take($limit)->values()->all() : [],
            'checklist' => $showChecklist ? $this->checklist($allSupplierRows) : [],
            'issues' => $this->issues($allSupplierRows)->take($limit)->values()->all(),
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
            'suppliers' => 0,
            'supplier_category_mappings' => 0,
            'canonical_product_families' => 0,
            'category_product_attributes' => 0,
            'product_attributes' => 0,
            'attribute_values' => 0,
            'product_attribute_values' => 0,
        ];
    }

    /**
     * @return Collection<int, Supplier>
     */
    private function suppliers(?string $supplier, bool $includeDisabled): Collection
    {
        if (! Schema::hasTable('suppliers')) {
            return collect();
        }

        $query = Supplier::query()->orderBy('company_name')->orderBy('id');

        if (! $includeDisabled && Schema::hasColumn('suppliers', 'status')) {
            $query->where('status', 'active');
        }

        if (! $includeDisabled && Schema::hasColumn('suppliers', 'import_enabled')) {
            $query->where('import_enabled', true);
        }

        if (filled($supplier)) {
            $this->applySupplierFilter($query, (string) $supplier);
        }

        return $query->get();
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

        $query->where(function (Builder $query) use ($normalized): void {
            $query
                ->whereRaw('LOWER(slug) = ?', [$normalized])
                ->orWhereRaw('LOWER(company_name) = ?', [$normalized]);
        });
    }

    /**
     * @param  array<int, int>  $supplierIds
     * @return Collection<int, SupplierFeed>
     */
    private function feeds(array $supplierIds): Collection
    {
        if ($supplierIds === [] || ! Schema::hasTable('supplier_feeds')) {
            return collect();
        }

        return SupplierFeed::query()
            ->whereIn('supplier_id', $supplierIds)
            ->orderByRaw("case status when 'active' then 1 else 2 end")
            ->orderByRaw("case feed_type when 'xml' then 1 when 'csv' then 2 else 3 end")
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<int, int>  $supplierIds
     * @return Collection<int, SupplierImportRun>
     */
    private function latestRuns(array $supplierIds): Collection
    {
        if ($supplierIds === [] || ! Schema::hasTable('supplier_import_runs')) {
            return collect();
        }

        return SupplierImportRun::query()
            ->whereIn('supplier_id', $supplierIds)
            ->latest('started_at')
            ->latest('created_at')
            ->get()
            ->unique('supplier_id')
            ->values();
    }

    /**
     * @param  array<int, int>  $supplierIds
     * @return Collection<int, array<string, mixed>>
     */
    private function stagingSummary(array $supplierIds): Collection
    {
        if ($supplierIds === [] || ! Schema::hasTable('supplier_products')) {
            return collect();
        }

        return SupplierProduct::query()
            ->whereIn('supplier_id', $supplierIds)
            ->select($this->supplierProductColumns())
            ->get()
            ->groupBy(fn (SupplierProduct $product): int => (int) $product->supplier_id)
            ->map(fn (Collection $products, int $supplierId): array => [
                'supplier_id' => $supplierId,
                'staged_supplier_products_count' => $products->count(),
                'distinct_categories_count' => $products
                    ->map(fn (SupplierProduct $product): ?string => $this->normalizedValue($product->category_name ?? null))
                    ->filter()
                    ->unique()
                    ->count(),
                'products_with_supplier_sku' => $this->filledCount($products, ['supplier_sku']),
                'products_with_ean_gtin' => $this->filledCount($products, ['ean']),
                'products_with_mpn' => $this->filledCount($products, ['mpn']),
                'products_with_brand' => $this->filledCount($products, ['brand_name']),
            ])
            ->values();
    }

    /**
     * @return array<int, string>
     */
    private function supplierProductColumns(): array
    {
        return collect([
            'id',
            'supplier_id',
            'supplier_sku',
            'ean',
            'mpn',
            'brand_name',
            'category_name',
        ])
            ->filter(fn (string $column): bool => Schema::hasColumn('supplier_products', $column))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Supplier>  $suppliers
     * @param  Collection<int, SupplierFeed>  $feeds
     * @param  Collection<int, SupplierImportRun>  $runs
     * @param  Collection<int, array<string, mixed>>  $staging
     * @return Collection<int, array<string, mixed>>
     */
    private function supplierRows(Collection $suppliers, Collection $feeds, Collection $runs, Collection $staging): Collection
    {
        $feedsBySupplier = $feeds->groupBy(fn (SupplierFeed $feed): int => (int) $feed->supplier_id);
        $runsBySupplier = $runs->keyBy(fn (SupplierImportRun $run): int => (int) $run->supplier_id);
        $stagingBySupplier = $staging->keyBy('supplier_id');

        return $suppliers
            ->map(function (Supplier $supplier) use ($feedsBySupplier, $runsBySupplier, $stagingBySupplier): array {
                $supplierFeeds = $feedsBySupplier->get((int) $supplier->id, collect())->values();
                $primaryFeed = $supplierFeeds->first();
                $latestRun = $runsBySupplier->get((int) $supplier->id);
                $staged = $stagingBySupplier->get((int) $supplier->id, $this->emptyStagingSummary((int) $supplier->id));
                $feedAudit = $primaryFeed instanceof SupplierFeed
                    ? $this->feedAudit($primaryFeed)
                    : $this->emptyFeedAudit();
                $status = $this->status($supplier);
                $issues = $this->supplierIssues($supplier, $primaryFeed, $feedAudit, $staged, $latestRun);

                return [
                    'supplier_id' => (int) $supplier->id,
                    'supplier_key' => $this->supplierKey($supplier),
                    'supplier_name' => (string) $supplier->company_name,
                    'supplier_status' => $status,
                    'is_active' => $status === 'active',
                    'import_enabled' => Schema::hasColumn('suppliers', 'import_enabled') ? (bool) $supplier->import_enabled : null,
                    'schedule_enabled' => Schema::hasColumn('suppliers', 'schedule_enabled') ? (bool) $supplier->schedule_enabled : null,
                    'schedule_type' => Schema::hasColumn('suppliers', 'schedule_type') ? (string) $supplier->schedule_type : null,
                    'next_import_at' => Schema::hasColumn('suppliers', 'next_import_at') ? optional($supplier->next_import_at)->toISOString() : null,
                    'last_import_at' => Schema::hasColumn('suppliers', 'last_import_at') ? optional($supplier->last_import_at)->toISOString() : null,
                    'feed_count' => $supplierFeeds->count(),
                    'active_feed_count' => $supplierFeeds->where('status', 'active')->count(),
                    'configured_feed_types' => $supplierFeeds->pluck('feed_type')->filter()->unique()->values()->all(),
                    'primary_feed_id' => $primaryFeed?->id,
                    'primary_feed_name' => $primaryFeed?->feed_name,
                    'primary_feed_status' => $primaryFeed?->status,
                    'feed_type' => $feedAudit['feed_type'],
                    'feed_configured' => $feedAudit['feed_configured'],
                    'feed_url_host' => $feedAudit['feed_url_host'],
                    'feed_url_redacted' => $feedAudit['feed_url_redacted'],
                    'driver' => $feedAudit['driver'],
                    'parser' => $feedAudit['parser'],
                    'driver_status' => $feedAudit['driver_status'],
                    'auth' => $feedAudit['auth'],
                    'auth_configured' => $feedAudit['auth_configured'],
                    'mapping_configured' => $feedAudit['mapping_configured'],
                    'xml_mapping_template_configured' => $feedAudit['xml_mapping_template_configured'],
                    'last_feed_sync_at' => optional($primaryFeed?->last_sync_at)->toISOString(),
                    'last_feed_error_present' => filled($primaryFeed?->last_error),
                    'last_import_run_status' => $latestRun?->status,
                    'last_import_run_started_at' => optional($latestRun?->started_at)->toISOString(),
                    'last_import_run_finished_at' => optional($latestRun?->finished_at)->toISOString(),
                    'last_import_error_count' => $latestRun?->error_count,
                    'last_import_warning_count' => $latestRun?->warning_count,
                    'staged_supplier_products_count' => $staged['staged_supplier_products_count'],
                    'distinct_categories_count' => $staged['distinct_categories_count'],
                    'identifier_completeness' => [
                        'supplier_sku' => $staged['products_with_supplier_sku'],
                        'ean_gtin' => $staged['products_with_ean_gtin'],
                        'mpn' => $staged['products_with_mpn'],
                        'brand' => $staged['products_with_brand'],
                    ],
                    'can_run_manual_staging_import' => $this->canRunManualStagingImport($supplier, $feedAudit),
                    'schedule_due_now' => $this->scheduleDue($supplier),
                    'schedule_risk_status' => $this->scheduleRiskStatus($supplier, $feedAudit),
                    'readiness_status' => $this->readinessStatus($supplier, $feedAudit, $issues),
                    'issues' => $issues,
                ];
            })
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function feedAudit(SupplierFeed $feed): array
    {
        $feedType = Str::lower((string) $feed->feed_type);
        $driver = $this->driverForFeedType($feedType);
        $auth = $this->authPresence($feed);

        return [
            'feed_type' => $feedType ?: 'unknown',
            'feed_configured' => filled($feed->feed_url),
            'feed_url_host' => $this->urlHost($feed->feed_url),
            'feed_url_redacted' => $this->redactedUrl($feed->feed_url),
            'driver' => $driver['driver'],
            'parser' => $driver['parser'],
            'driver_status' => $this->driverStatus($feed, $driver),
            'auth' => $auth,
            'auth_configured' => in_array(true, $auth, true),
            'mapping_configured' => is_array($feed->mapping) && $feed->mapping !== [],
            'xml_mapping_template_configured' => $feedType === 'xml' ? $this->hasXmlMappingTemplate($feed) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyFeedAudit(): array
    {
        return [
            'feed_type' => 'unknown',
            'feed_configured' => false,
            'feed_url_host' => null,
            'feed_url_redacted' => null,
            'driver' => null,
            'parser' => null,
            'driver_status' => 'missing',
            'auth' => [
                'has_username' => false,
                'has_password' => false,
                'has_token' => false,
                'has_secret' => false,
                'has_api_key' => false,
                'has_headers' => false,
            ],
            'auth_configured' => false,
            'mapping_configured' => false,
            'xml_mapping_template_configured' => null,
        ];
    }

    /**
     * @return array{driver: ?string, parser: ?string, supported: bool}
     */
    private function driverForFeedType(string $feedType): array
    {
        return match ($feedType) {
            'xml' => [
                'driver' => XmlImportEngine::class,
                'parser' => 'SimpleXML + XmlMappingTemplate',
                'supported' => true,
            ],
            'csv' => [
                'driver' => SupplierCsvFeedImportService::class,
                'parser' => 'CsvMappingService',
                'supported' => true,
            ],
            default => [
                'driver' => null,
                'parser' => null,
                'supported' => false,
            ],
        };
    }

    /**
     * @param  array{driver: ?string, parser: ?string, supported: bool}  $driver
     */
    private function driverStatus(SupplierFeed $feed, array $driver): string
    {
        if (! $driver['supported']) {
            return 'unsupported';
        }

        if (Str::lower((string) $feed->feed_type) === 'xml' && ! $this->hasXmlMappingTemplate($feed)) {
            return 'missing_xml_mapping_template';
        }

        return 'configured';
    }

    private function hasXmlMappingTemplate(SupplierFeed $feed): bool
    {
        if (! Schema::hasTable('xml_mapping_templates')) {
            return false;
        }

        return XmlMappingTemplate::query()
            ->where(function (Builder $query) use ($feed): void {
                $query->where('supplier_id', $feed->supplier_id)->orWhereNull('supplier_id');
            })
            ->where('is_active', true)
            ->exists();
    }

    /**
     * @return array<string, bool>
     */
    private function authPresence(SupplierFeed $feed): array
    {
        $rawPassword = $feed->getRawOriginal('password');
        $source = [
            'url' => (string) $feed->feed_url,
            'mapping' => $feed->mapping ?? [],
        ];

        return [
            'has_username' => filled($feed->username),
            'has_password' => filled($rawPassword),
            'has_token' => $this->hasSensitiveMarker($source, ['token', 'bearer']),
            'has_secret' => $this->hasSensitiveMarker($source, ['secret', 'signature']),
            'has_api_key' => $this->hasSensitiveMarker($source, ['api_key', 'apikey', 'key']),
            'has_headers' => $this->hasSensitiveMarker($source, ['header', 'headers', 'authorization']),
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<int, string>  $needles
     */
    private function hasSensitiveMarker(array $source, array $needles): bool
    {
        $text = Str::lower(json_encode($source, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');

        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $feedAudit
     * @param  array<string, mixed>  $staged
     * @return array<int, string>
     */
    private function supplierIssues(Supplier $supplier, ?SupplierFeed $feed, array $feedAudit, array $staged, ?SupplierImportRun $latestRun): array
    {
        $issues = [];

        if ($this->status($supplier) !== 'active' || (Schema::hasColumn('suppliers', 'import_enabled') && ! $supplier->import_enabled)) {
            $issues[] = 'disabled_supplier';
        }

        if (! $feed instanceof SupplierFeed || ! $feedAudit['feed_configured']) {
            $issues[] = 'missing_feed_url';
        }

        if (($feedAudit['driver_status'] ?? 'missing') !== 'configured') {
            $issues[] = 'missing_import_driver';
        }

        if (($feedAudit['auth']['has_username'] ?? false) !== ($feedAudit['auth']['has_password'] ?? false)) {
            $issues[] = 'missing_auth_config';
        }

        if (Schema::hasColumn('suppliers', 'schedule_enabled') && (! $supplier->schedule_enabled || $supplier->schedule_type === 'manual_only')) {
            $issues[] = 'schedule_disabled';
        }

        if ((int) $staged['staged_supplier_products_count'] === 0) {
            $issues[] = 'no_staging_data';
        }

        if (filled($feed?->last_error) || in_array($latestRun?->status, ['failed', 'completed_with_warnings'], true)) {
            $issues[] = 'needs_manual_review';
        }

        return array_values(array_unique($issues));
    }

    /**
     * @param  array<string, mixed>  $feedAudit
     * @param  array<int, string>  $issues
     */
    private function readinessStatus(Supplier $supplier, array $feedAudit, array $issues): string
    {
        foreach ([
            'disabled_supplier',
            'missing_feed_url',
            'missing_import_driver',
            'missing_auth_config',
            'schedule_disabled',
            'no_staging_data',
            'needs_manual_review',
        ] as $status) {
            if (in_array($status, $issues, true)) {
                return $status;
            }
        }

        return $this->canRunManualStagingImport($supplier, $feedAudit)
            ? 'ready_for_staging_import'
            : 'unknown';
    }

    /**
     * @param  array<string, mixed>  $feedAudit
     */
    private function canRunManualStagingImport(Supplier $supplier, array $feedAudit): bool
    {
        return $this->status($supplier) === 'active'
            && (! Schema::hasColumn('suppliers', 'import_enabled') || (bool) $supplier->import_enabled)
            && (bool) $feedAudit['feed_configured']
            && ($feedAudit['driver_status'] ?? null) === 'configured'
            && (($feedAudit['auth']['has_username'] ?? false) === ($feedAudit['auth']['has_password'] ?? false));
    }

    /**
     * @param  array<string, mixed>  $feedAudit
     */
    private function scheduleRiskStatus(Supplier $supplier, array $feedAudit): string
    {
        if ($this->status($supplier) !== 'active' || (Schema::hasColumn('suppliers', 'import_enabled') && ! $supplier->import_enabled)) {
            return 'disabled';
        }

        if (! Schema::hasColumn('suppliers', 'schedule_enabled') || ! $supplier->schedule_enabled || $supplier->schedule_type === 'manual_only') {
            return 'disabled';
        }

        return $this->canRunManualStagingImport($supplier, $feedAudit)
            ? 'safe_staging_only'
            : 'needs_review';
    }

    private function scheduleDue(Supplier $supplier): ?bool
    {
        if (! Schema::hasColumn('suppliers', 'schedule_enabled')) {
            return null;
        }

        return $this->schedule->isDue($supplier);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $supplierRows
     * @return Collection<int, array<string, mixed>>
     */
    private function scheduleRows(Collection $supplierRows): Collection
    {
        return $supplierRows
            ->map(fn (array $row): array => [
                'supplier_id' => $row['supplier_id'],
                'supplier_name' => $row['supplier_name'],
                'import_enabled' => $row['import_enabled'],
                'schedule_enabled' => $row['schedule_enabled'],
                'schedule_type' => $row['schedule_type'],
                'next_import_at' => $row['next_import_at'],
                'last_import_at' => $row['last_import_at'],
                'due_now' => $row['schedule_due_now'],
                'queue_job_configured' => class_exists(RunSupplierImportJob::class),
                'risk_status' => $row['schedule_risk_status'],
            ])
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $supplierRows
     * @return Collection<int, array<string, mixed>>
     */
    private function configRows(Collection $supplierRows): Collection
    {
        return $supplierRows
            ->map(fn (array $row): array => [
                'supplier_id' => $row['supplier_id'],
                'supplier_name' => $row['supplier_name'],
                'feed_type' => $row['feed_type'],
                'feed_url_host' => $row['feed_url_host'],
                'feed_url_redacted' => $row['feed_url_redacted'],
                'auth' => $row['auth'],
                'mapping_configured' => $row['mapping_configured'],
                'xml_mapping_template_configured' => $row['xml_mapping_template_configured'],
            ])
            ->values();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function driverCapabilities(): array
    {
        return [
            [
                'format' => 'xml',
                'supported' => class_exists(XmlImportEngine::class),
                'importer_class' => XmlImportEngine::class,
                'parser' => 'SimpleXML + XmlMappingTemplate',
                'scheduled_import_supported' => true,
                'writes_to_supplier_products_staging' => true,
                'catalog_product_write_detected' => false,
            ],
            [
                'format' => 'csv',
                'supported' => class_exists(SupplierCsvFeedImportService::class),
                'importer_class' => SupplierCsvFeedImportService::class,
                'parser' => 'CsvMappingService',
                'scheduled_import_supported' => true,
                'writes_to_supplier_products_staging' => true,
                'catalog_product_write_detected' => false,
            ],
            [
                'format' => 'json',
                'supported' => false,
                'importer_class' => null,
                'parser' => null,
                'scheduled_import_supported' => false,
                'writes_to_supplier_products_staging' => null,
                'catalog_product_write_detected' => false,
            ],
            [
                'format' => 'api',
                'supported' => false,
                'importer_class' => null,
                'parser' => null,
                'scheduled_import_supported' => false,
                'writes_to_supplier_products_staging' => null,
                'catalog_product_write_detected' => false,
            ],
            [
                'format' => 'manual',
                'supported' => false,
                'importer_class' => null,
                'parser' => null,
                'scheduled_import_supported' => false,
                'writes_to_supplier_products_staging' => null,
                'catalog_product_write_detected' => false,
            ],
            [
                'section' => 'commands',
                'commands' => [
                    'suppliers:audit-import-capabilities',
                    'suppliers:audit-discovery',
                    'suppliers:run-scheduled-imports',
                    'suppliers:sync-due-feeds',
                ],
                'runtime_import_executed' => false,
            ],
            [
                'section' => 'jobs',
                'jobs' => [
                    RunSupplierImportJob::class,
                    ProcessSupplierImportRunJob::class,
                    ProcessXmlSupplierFeed::class,
                ],
                'runtime_job_dispatched' => false,
            ],
            [
                'section' => 'inspection_note',
                'message' => 'Driver inspection limited; no runtime import executed.',
            ],
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $supplierRows
     * @return array<int, array<string, mixed>>
     */
    private function checklist(Collection $supplierRows): array
    {
        return [
            [
                'check' => 'active_import_enabled_suppliers',
                'status' => $supplierRows->filter(fn (array $row): bool => $row['is_active'] && (bool) $row['import_enabled'])->count(),
            ],
            [
                'check' => 'suppliers_with_feed_url',
                'status' => $supplierRows->where('feed_configured', true)->count(),
            ],
            [
                'check' => 'suppliers_with_supported_driver',
                'status' => $supplierRows->where('driver_status', 'configured')->count(),
            ],
            [
                'check' => 'suppliers_ready_for_manual_staging_import',
                'status' => $supplierRows->where('can_run_manual_staging_import', true)->count(),
            ],
            [
                'check' => 'audit_safety',
                'status' => 'read_only_no_remote_feed_fetch_no_jobs_no_catalog_sync',
            ],
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $supplierRows
     * @return Collection<int, array<string, mixed>>
     */
    private function issues(Collection $supplierRows): Collection
    {
        return $supplierRows
            ->flatMap(fn (array $row): array => collect($row['issues'])
                ->map(fn (string $issue): array => [
                    'type' => 'supplier_import_capability',
                    'supplier_id' => $row['supplier_id'],
                    'supplier_name' => $row['supplier_name'],
                    'reason' => $issue,
                ])
                ->all())
            ->values();
    }

    private function supplierKey(Supplier $supplier): ?string
    {
        if (filled($supplier->slug)) {
            return (string) $supplier->slug;
        }

        return $supplier->id !== null ? (string) $supplier->id : null;
    }

    private function status(Supplier $supplier): ?string
    {
        return Schema::hasColumn('suppliers', 'status') ? (string) $supplier->status : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyStagingSummary(int $supplierId): array
    {
        return [
            'supplier_id' => $supplierId,
            'staged_supplier_products_count' => 0,
            'distinct_categories_count' => 0,
            'products_with_supplier_sku' => 0,
            'products_with_ean_gtin' => 0,
            'products_with_mpn' => 0,
            'products_with_brand' => 0,
        ];
    }

    /**
     * @param  Collection<int, SupplierProduct>  $products
     * @param  array<int, string>  $fields
     */
    private function filledCount(Collection $products, array $fields): int
    {
        return $products
            ->filter(function (SupplierProduct $product) use ($fields): bool {
                foreach ($fields as $field) {
                    if ($this->hasValue($product->{$field} ?? null)) {
                        return true;
                    }
                }

                return false;
            })
            ->count();
    }

    private function hasValue(mixed $value): bool
    {
        if (is_string($value)) {
            return trim($value) !== '';
        }

        return $value !== null;
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

    private function urlHost(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }

        $host = parse_url((string) $url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }

    private function redactedUrl(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }

        $parts = parse_url((string) $url);

        if (! is_array($parts)) {
            return '[redacted-url]';
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'].'://' : '';
        $host = (string) ($parts['host'] ?? '');
        $path = $this->redactedPath((string) ($parts['path'] ?? ''));
        $query = $this->redactedQuery((string) ($parts['query'] ?? ''));

        return $scheme.$host.$path.$query;
    }

    private function redactedPath(string $path): string
    {
        if ($path === '') {
            return '';
        }

        $segments = explode('/', $path);
        $redactNext = false;

        foreach ($segments as $index => $segment) {
            $lower = Str::lower(rawurldecode($segment));

            if ($redactNext || $this->isSensitiveKey($lower)) {
                if ($segment !== '') {
                    $segments[$index] = 'REDACTED';
                }

                $redactNext = false;

                if ($this->isSensitiveKey($lower)) {
                    $redactNext = true;
                }
            }
        }

        return implode('/', $segments);
    }

    private function redactedQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        parse_str($query, $params);

        if (! is_array($params) || $params === []) {
            return '?REDACTED';
        }

        $redacted = [];

        foreach (array_keys($params) as $key) {
            $redacted[] = rawurlencode((string) $key).'=REDACTED';
        }

        return '?'.implode('&', $redacted);
    }

    private function isSensitiveKey(string $value): bool
    {
        foreach (self::SENSITIVE_KEYS as $key) {
            if ($value === $key || str_contains($value, $key)) {
                return true;
            }
        }

        return false;
    }
}

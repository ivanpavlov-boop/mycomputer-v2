<?php

namespace App\Filament\Pages;

use App\Models\Brand;
use App\Models\Category;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductSupplierOffer;
use App\Models\ProductSyncLog;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Availability\AvailabilityStatusMapper;
use App\Services\Pricing\PricingEngine;
use App\Services\Products\CatalogSyncPreviewService;
use App\Services\Suppliers\SupplierExclusionService;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use UnitEnum;

class CatalogSyncPreview extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEye;

    protected static ?string $navigationLabel = 'Catalog Sync Preview';

    protected static string|UnitEnum|null $navigationGroup = 'Suppliers';

    protected string $view = 'filament.pages.catalog-sync-preview';

    protected const int CREATE_CANDIDATE_SCAN_LIMIT = 1000;

    protected const int CREATE_CANDIDATE_RESULT_LIMIT = 100;

    /**
     * @var array<string, mixed>
     */
    public array $filters = [
        'limit' => 50,
        'supplier_id' => null,
        'action' => null,
        'discovery_mode' => 'batch',
        'quick_filter' => null,
        'stock_status' => null,
        'category' => null,
        'brand' => null,
        'search' => null,
    ];

    /**
     * @var array<int, int|string>
     */
    public array $selectedSupplierProductIds = [];

    /**
     * @var array{created: int, skipped: int, failed: int, messages: array<int, string>}|null
     */
    public ?array $lastManualSyncResult = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('manage suppliers');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('filters')
            ->components([
                Grid::make(4)->schema([
                    Select::make('limit')
                        ->label('Batch')
                        ->options([
                            50 => 'First 50 products',
                            100 => 'First 100 products',
                            'all' => 'Full supplier feed',
                        ])
                        ->default(50),
                    Select::make('supplier_id')
                        ->label('Supplier')
                        ->options(fn (): array => Supplier::query()->orderBy('company_name')->pluck('company_name', 'id')->all())
                        ->searchable(),
                    Select::make('action')
                        ->label('Catalog action')
                        ->options([
                            'CREATE' => 'Create',
                            'UPDATE' => 'Update',
                            'SKIP' => 'Skip',
                            'CONFLICT' => 'Conflict',
                            'ERROR' => 'Error',
                        ])
                        ->live(),
                    Select::make('discovery_mode')
                        ->label('Discovery')
                        ->options([
                            'batch' => 'Current batch',
                            'create_candidates' => 'Find CREATE candidates',
                        ])
                        ->default('batch')
                        ->live(),
                    Select::make('quick_filter')
                        ->label('Quick filter')
                        ->options([
                            'apcom' => 'APCOM only',
                            'missing_ean' => 'Missing EAN',
                            'zero_stock' => 'Zero Stock',
                            'missing_images' => 'Missing Images',
                        ]),
                    Select::make('stock_status')
                        ->label('Stock')
                        ->options([
                            'in_stock' => 'In stock',
                            'out_of_stock' => 'Out of stock',
                        ]),
                    TextInput::make('category')->label('Category contains'),
                    TextInput::make('brand')->label('Brand contains'),
                    TextInput::make('search')->label('Name / SKU / EAN / MPN')->columnSpan(2),
                ]),
            ]);
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, error: string|null, limit: int|string, summary: array{total: int, included: int, excluded: int, matched: int, unmatched: int, match_errors: int, create_rows: int, update_rows: int, skip_rows: int, conflict_rows: int, error_rows: int}, discovery: array<string, mixed>}
     */
    public function queryOnlySupplierProducts(): array
    {
        try {
            if ((bool) config('services.catalog_sync_preview.force_query_only_failure')) {
                throw new RuntimeException('Forced query-only failure.');
            }

            $limit = $this->filters['limit'] ?? 50;
            $query = SupplierProduct::query()
                ->with(['supplier:id,company_name', 'availabilityStatus:id,name,code'])
                ->select([
                    'id',
                    'product_id',
                    'supplier_id',
                    'supplier_sku',
                    'ean',
                    'mpn',
                    'name',
                    'brand_name',
                    'category_name',
                    'price',
                    'quantity',
                    'external_availability_status',
                    'external_availability_label',
                    'availability_status_id',
                    'status',
                    'updated_at',
                ])
                ->orderBy('id');

            $this->applyQueryOnlyFilters($query);

            if ($this->isCreateCandidateDiscoveryMode()) {
                return $this->queryCreateCandidateRows($query, $limit);
            }

            if ($limit !== 'all') {
                $query->limit((int) $limit);
            }

            $rows = $query
                ->get()
                ->map(fn (SupplierProduct $supplierProduct): array => $this->previewRowForSupplierProduct($supplierProduct))
                ->all();
            $filteredRows = $this->applyCatalogActionFilter($rows);

            return [
                'rows' => $filteredRows,
                'error' => null,
                'limit' => $limit,
                'summary' => $this->queryOnlySummary($rows),
                'discovery' => $this->emptyCreateCandidateDiscovery(),
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'rows' => [],
                'error' => $exception->getMessage(),
                'limit' => $this->filters['limit'] ?? 50,
                'summary' => [
                    'total' => 0,
                    'included' => 0,
                    'excluded' => 0,
                    'matched' => 0,
                    'unmatched' => 0,
                    'match_errors' => 0,
                    'create_rows' => 0,
                    'update_rows' => 0,
                    'skip_rows' => 0,
                    'conflict_rows' => 0,
                    'error_rows' => 0,
                ],
                'discovery' => $this->emptyCreateCandidateDiscovery(),
            ];
        }
    }

    /**
     * @param  Builder<SupplierProduct>  $query
     * @return array{rows: array<int, array<string, mixed>>, error: string|null, limit: int|string, summary: array{total: int, included: int, excluded: int, matched: int, unmatched: int, match_errors: int, create_rows: int, update_rows: int, skip_rows: int, conflict_rows: int, error_rows: int}, discovery: array<string, mixed>}
     */
    protected function queryCreateCandidateRows(Builder $query, int|string $limit): array
    {
        $resultLimit = $this->createCandidateResultLimit($limit);
        $evaluatedRows = [];
        $candidateRows = [];

        $query
            ->limit(self::CREATE_CANDIDATE_SCAN_LIMIT)
            ->get()
            ->each(function (SupplierProduct $supplierProduct) use (&$evaluatedRows, &$candidateRows, $resultLimit): void {
                $row = $this->previewRowForSupplierProduct($supplierProduct);
                $evaluatedRows[] = $row;

                if (($row['sync_action'] ?? null) === 'CREATE' && count($candidateRows) < $resultLimit) {
                    $candidateRows[] = $row;
                }
            });

        $summary = $this->queryOnlySummary($evaluatedRows);
        $diagnostics = $this->createCandidateDiagnostics($evaluatedRows);

        return [
            'rows' => $candidateRows,
            'error' => null,
            'limit' => $limit,
            'summary' => $summary,
            'discovery' => [
                'enabled' => true,
                'scan_limit' => self::CREATE_CANDIDATE_SCAN_LIMIT,
                'result_limit' => $resultLimit,
                'scanned_rows' => count($evaluatedRows),
                'create_candidates_found' => $summary['create_rows'],
                'displayed_create_candidates' => count($candidateRows),
                'skipped_rows' => $summary['skip_rows'],
                'matched_update_rows' => $summary['update_rows'],
                'excluded_rows' => $summary['excluded'],
                'unmatched_not_create_reasons' => $diagnostics['unmatched_not_create_reasons'],
                'skip_reason_summary' => $diagnostics['skip_reason_summary'],
                'match_type_summary' => $diagnostics['match_type_summary'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function emptyCreateCandidateDiscovery(): array
    {
        return [
            'enabled' => false,
            'scan_limit' => self::CREATE_CANDIDATE_SCAN_LIMIT,
            'result_limit' => 0,
            'scanned_rows' => 0,
            'create_candidates_found' => 0,
            'displayed_create_candidates' => 0,
            'skipped_rows' => 0,
            'matched_update_rows' => 0,
            'excluded_rows' => 0,
            'unmatched_not_create_reasons' => $this->emptyUnmatchedNotCreateReasons(),
            'skip_reason_summary' => $this->emptySkipReasonSummary(),
            'match_type_summary' => $this->emptyMatchTypeSummary(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{unmatched_not_create_reasons: array<string, int>, skip_reason_summary: array<string, int>, match_type_summary: array<string, int>}
     */
    protected function createCandidateDiagnostics(array $rows): array
    {
        $unmatchedNotCreateReasons = $this->emptyUnmatchedNotCreateReasons();
        $skipReasonSummary = $this->emptySkipReasonSummary();
        $matchTypeSummary = $this->emptyMatchTypeSummary();

        foreach ($rows as $row) {
            $syncAction = (string) ($row['sync_action'] ?? '');
            $syncReason = (string) ($row['sync_reason'] ?? '');
            $matchType = (string) ($row['match_type'] ?? '');

            match ($matchType) {
                'ean' => $matchTypeSummary['exact_ean_match']++,
                'supplier_sku' => $matchTypeSummary['supplier_sku_match']++,
                'mpn_brand' => $matchTypeSummary['mpn_brand_match']++,
                'name_similarity_warning' => $matchTypeSummary['name_similarity_only']++,
                'no_match' => $matchTypeSummary['no_exact_match']++,
                'error' => $matchTypeSummary['match_errors']++,
                default => $matchTypeSummary['other']++,
            };

            if ((bool) ($row['excluded'] ?? false)) {
                $skipReasonSummary['excluded']++;
            }

            if (filled($row['matched_product_id'] ?? null)) {
                $skipReasonSummary['matched_existing_product']++;
            }

            if ($syncReason === 'no_meaningful_changes') {
                $skipReasonSummary['no_meaningful_changes']++;
            } elseif ($syncReason === 'missing_required_data') {
                $skipReasonSummary['missing_required_data']++;
            } elseif ($syncAction === 'CONFLICT') {
                $skipReasonSummary['conflict']++;
            } elseif ($syncAction !== 'CREATE' && ! (bool) ($row['excluded'] ?? false) && blank($row['matched_product_id'] ?? null)) {
                $skipReasonSummary['other']++;
            }

            if (filled($row['matched_product_id'] ?? null) || $syncAction === 'CREATE') {
                continue;
            }

            $this->incrementUnmatchedNotCreateReasons($unmatchedNotCreateReasons, $row);
        }

        return [
            'unmatched_not_create_reasons' => $unmatchedNotCreateReasons,
            'skip_reason_summary' => $skipReasonSummary,
            'match_type_summary' => $matchTypeSummary,
        ];
    }

    /**
     * @return array<string, int>
     */
    protected function emptyUnmatchedNotCreateReasons(): array
    {
        return [
            'excluded' => 0,
            'missing_required_data' => 0,
            'missing_ean' => 0,
            'missing_name' => 0,
            'missing_supplier_sku' => 0,
            'missing_price' => 0,
            'missing_stock_availability' => 0,
            'conflict' => 0,
            'not_eligible' => 0,
            'other' => 0,
        ];
    }

    /**
     * @return array<string, int>
     */
    protected function emptySkipReasonSummary(): array
    {
        return [
            'excluded' => 0,
            'matched_existing_product' => 0,
            'no_meaningful_changes' => 0,
            'missing_required_data' => 0,
            'conflict' => 0,
            'other' => 0,
        ];
    }

    /**
     * @return array<string, int>
     */
    protected function emptyMatchTypeSummary(): array
    {
        return [
            'exact_ean_match' => 0,
            'supplier_sku_match' => 0,
            'mpn_brand_match' => 0,
            'name_similarity_only' => 0,
            'no_exact_match' => 0,
            'match_errors' => 0,
            'other' => 0,
        ];
    }

    /**
     * @param  array<string, int>  $reasons
     * @param  array<string, mixed>  $row
     */
    protected function incrementUnmatchedNotCreateReasons(array &$reasons, array $row): void
    {
        $matchedSpecificReason = false;

        if ((bool) ($row['excluded'] ?? false)) {
            $reasons['excluded']++;
            $matchedSpecificReason = true;
        }

        if (($row['sync_reason'] ?? null) === 'missing_required_data') {
            $reasons['missing_required_data']++;
            $matchedSpecificReason = true;
        }

        if ($this->previewValueIsBlank($row['ean'] ?? null)) {
            $reasons['missing_ean']++;
        }

        if ($this->previewValueIsBlank($row['name'] ?? null)) {
            $reasons['missing_name']++;
            $matchedSpecificReason = true;
        }

        if ($this->previewValueIsBlank($row['supplier_sku'] ?? null)) {
            $reasons['missing_supplier_sku']++;
        }

        if (($row['price'] ?? null) === null) {
            $reasons['missing_price']++;
            $matchedSpecificReason = true;
        }

        if (($row['quantity'] ?? null) === null && $this->previewValueIsBlank($row['availability'] ?? null)) {
            $reasons['missing_stock_availability']++;
            $matchedSpecificReason = true;
        }

        if (($row['sync_action'] ?? null) === 'CONFLICT') {
            $reasons['conflict']++;
            $matchedSpecificReason = true;
        }

        if (($row['sync_action'] ?? null) !== 'CREATE') {
            $reasons['not_eligible']++;
        }

        if (! $matchedSpecificReason && ($row['sync_action'] ?? null) !== 'CREATE') {
            $reasons['other']++;
        }
    }

    protected function previewValueIsBlank(mixed $value): bool
    {
        return blank($value) || $value === '-';
    }

    protected function isCreateCandidateDiscoveryMode(): bool
    {
        return ($this->filters['discovery_mode'] ?? 'batch') === 'create_candidates';
    }

    protected function createCandidateResultLimit(int|string $limit): int
    {
        if ($limit === 'all') {
            return self::CREATE_CANDIDATE_RESULT_LIMIT;
        }

        return min(max((int) $limit, 1), self::CREATE_CANDIDATE_RESULT_LIMIT);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function applyCatalogActionFilter(array $rows): array
    {
        $action = $this->filters['action'] ?? null;

        if (blank($action)) {
            return $rows;
        }

        $normalizedAction = Str::upper(trim((string) $action));

        return array_values(array_filter(
            $rows,
            fn (array $row): bool => Str::upper(trim((string) ($row['sync_action'] ?? ''))) === $normalizedAction,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    protected function previewRowForSupplierProduct(SupplierProduct $supplierProduct): array
    {
        $row = array_merge(
            $this->basePreviewRowForSupplierProduct($supplierProduct),
            $this->pricingPreviewForSupplierProduct($supplierProduct),
            $this->exclusionPreviewForSupplierProduct($supplierProduct),
            $this->matchingPreviewForSupplierProduct($supplierProduct),
        );

        return array_merge($row, $this->syncActionPreviewForSupplierProduct($supplierProduct, $row));
    }

    /**
     * @param  Builder<SupplierProduct>  $query
     */
    protected function applyQueryOnlyFilters(Builder $query): void
    {
        $supplierId = $this->filters['supplier_id'] ?? null;

        if (filled($supplierId)) {
            $query->where('supplier_id', $supplierId);
        }

        if (($this->filters['quick_filter'] ?? null) === 'apcom') {
            $query->whereHas('supplier', fn (Builder $supplierQuery): Builder => $supplierQuery
                ->where('slug', 'apcom')
                ->orWhere('company_name', 'APCOM'));
        }

        if (($this->filters['quick_filter'] ?? null) === 'missing_ean') {
            $query->where(fn (Builder $query): Builder => $query
                ->whereNull('ean')
                ->orWhere('ean', ''));
        }

        if (($this->filters['quick_filter'] ?? null) === 'zero_stock') {
            $query->where(fn (Builder $query): Builder => $query
                ->whereNull('quantity')
                ->orWhere('quantity', '<=', 0));
        }

        if (($this->filters['stock_status'] ?? null) === 'in_stock') {
            $query->where('quantity', '>', 0);
        }

        if (($this->filters['stock_status'] ?? null) === 'out_of_stock') {
            $query->where(fn (Builder $query): Builder => $query
                ->whereNull('quantity')
                ->orWhere('quantity', '<=', 0));
        }

        if (filled($this->filters['category'] ?? null)) {
            $query->where('category_name', 'like', '%'.$this->filters['category'].'%');
        }

        if (filled($this->filters['brand'] ?? null)) {
            $query->where('brand_name', 'like', '%'.$this->filters['brand'].'%');
        }

        if (filled($this->filters['search'] ?? null)) {
            $search = $this->filters['search'];

            $query->where(fn (Builder $query): Builder => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('supplier_sku', 'like', "%{$search}%")
                ->orWhere('ean', 'like', "%{$search}%")
                ->orWhere('mpn', 'like', "%{$search}%"));
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function pricingPreviewForSupplierProduct(SupplierProduct $supplierProduct): array
    {
        try {
            if ((int) config('services.catalog_sync_preview.force_pricing_failure_supplier_product_id') === $supplierProduct->id) {
                throw new RuntimeException('Forced pricing failure.');
            }

            $supplierProduct->loadMissing('supplier');

            $brand = $this->findExistingBrand($supplierProduct->brand_name);
            $category = $this->findExistingCategory($supplierProduct->category_name);
            $pricingProduct = new Product;
            $pricingProduct->brand_id = $brand?->id;
            $pricingProduct->category_id = $category?->id;
            $pricingProduct->source = Product::SOURCE_SUPPLIER_IMPORT;

            $pricing = app(PricingEngine::class)->calculateForSupplierProduct($supplierProduct, $pricingProduct, $category);
            $rule = $pricing['rule_id'] ? PricingRule::query()->find($pricing['rule_id']) : null;

            return [
                'supplier_cost' => $pricing['normalized_purchase_cost'] ?? null,
                'pricing_rule_used' => $this->displayPricingRule($rule, $supplierProduct),
                'margin_type' => $rule?->margin_type,
                'margin_value' => $rule?->formattedMarginValue(),
                'calculated_price' => $pricing['final_selling_price'] ?? null,
                'pricing_error' => null,
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'supplier_cost' => null,
                'pricing_rule_used' => 'Pricing Error',
                'margin_type' => null,
                'margin_value' => null,
                'calculated_price' => null,
                'pricing_error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function matchingPreviewForSupplierProduct(SupplierProduct $supplierProduct): array
    {
        try {
            if ((int) config('services.catalog_sync_preview.force_matching_failure_supplier_product_id') === $supplierProduct->id) {
                throw new RuntimeException('Forced matching failure.');
            }

            return app(CatalogSyncPreviewService::class)->matchingVisibility($supplierProduct);
        } catch (Throwable $exception) {
            report($exception);

            return [
                'matched_product_id' => null,
                'matched_product_name' => null,
                'match_type' => 'error',
                'match_confidence' => 'matching_check_failed: '.$exception->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function exclusionPreviewForSupplierProduct(SupplierProduct $supplierProduct): array
    {
        try {
            if ((int) config('services.catalog_sync_preview.force_exclusion_failure_supplier_product_id') === $supplierProduct->id) {
                throw new RuntimeException('Forced exclusion failure.');
            }

            $exclusion = app(SupplierExclusionService::class)->evaluate($supplierProduct);

            return [
                'excluded' => (bool) $exclusion['excluded'],
                'exclusion_reason' => $exclusion['excluded']
                    ? ($exclusion['label'] ?: $exclusion['reason'] ?: 'Excluded by rule')
                    : '-',
                'exclusion_error' => null,
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'excluded' => false,
                'exclusion_reason' => 'Exclusion Error',
                'exclusion_error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{sync_action: string, sync_reason: string}
     */
    protected function syncActionPreviewForSupplierProduct(SupplierProduct $supplierProduct, array $row): array
    {
        try {
            if ((int) config('services.catalog_sync_preview.force_action_failure_supplier_product_id') === $supplierProduct->id) {
                throw new RuntimeException('Forced sync action preview failure.');
            }

            if ((bool) ($row['excluded'] ?? false)) {
                return [
                    'sync_action' => 'SKIP',
                    'sync_reason' => 'excluded_by_rule',
                ];
            }

            if (filled($row['pricing_error'] ?? null)) {
                return [
                    'sync_action' => 'ERROR',
                    'sync_reason' => 'pricing_preview_failed',
                ];
            }

            if (filled($row['exclusion_error'] ?? null)) {
                return [
                    'sync_action' => 'ERROR',
                    'sync_reason' => 'exclusion_check_failed',
                ];
            }

            if (($row['match_type'] ?? null) === 'error') {
                return [
                    'sync_action' => 'ERROR',
                    'sync_reason' => 'matching_check_failed',
                ];
            }

            if (($row['match_confidence'] ?? null) === 'multiple_matches') {
                return [
                    'sync_action' => 'CONFLICT',
                    'sync_reason' => 'multiple_possible_matches',
                ];
            }

            if (($row['match_type'] ?? null) === 'name_similarity_warning') {
                return [
                    'sync_action' => 'CONFLICT',
                    'sync_reason' => 'name_similarity_requires_review',
                ];
            }

            if (! $this->hasMinimumSyncData($supplierProduct)) {
                return [
                    'sync_action' => 'SKIP',
                    'sync_reason' => 'missing_required_data',
                ];
            }

            if (filled($row['matched_product_id'] ?? null)) {
                return $this->matchedProductHasMeaningfulChanges((int) $row['matched_product_id'], $supplierProduct, $row)
                    ? [
                        'sync_action' => 'UPDATE',
                        'sync_reason' => 'matched_catalog_product_can_be_updated',
                    ]
                    : [
                        'sync_action' => 'SKIP',
                        'sync_reason' => 'no_meaningful_changes',
                    ];
            }

            return [
                'sync_action' => 'CREATE',
                'sync_reason' => 'new_catalog_product',
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'sync_action' => 'ERROR',
                'sync_reason' => 'action_preview_failed',
            ];
        }
    }

    public function syncSelectedCreateProducts(): void
    {
        $result = [
            'created' => 0,
            'skipped' => 0,
            'failed' => 0,
            'messages' => [],
        ];

        foreach (array_values(array_unique(array_map('intval', $this->selectedSupplierProductIds))) as $supplierProductId) {
            try {
                $supplierProduct = SupplierProduct::query()
                    ->with(['supplier:id,company_name,priority'])
                    ->find($supplierProductId);

                if (! $supplierProduct) {
                    $result['skipped']++;
                    $result['messages'][] = "Supplier product {$supplierProductId} skipped: row no longer exists.";

                    continue;
                }

                if ((int) config('services.catalog_sync_preview.force_manual_create_failure_supplier_product_id') === $supplierProduct->id) {
                    throw new RuntimeException('Forced manual create sync failure.');
                }

                $row = array_merge(
                    $this->basePreviewRowForSupplierProduct($supplierProduct),
                    $this->pricingPreviewForSupplierProduct($supplierProduct),
                    $this->exclusionPreviewForSupplierProduct($supplierProduct),
                    $this->matchingPreviewForSupplierProduct($supplierProduct),
                );
                $row = array_merge($row, $this->syncActionPreviewForSupplierProduct($supplierProduct, $row));

                if (! $this->isEligibleForManualCreateSync($supplierProduct, $row)) {
                    $result['skipped']++;
                    $result['messages'][] = "Supplier product {$supplierProduct->id} skipped: {$row['sync_reason']}.";

                    continue;
                }

                $product = DB::transaction(fn (): Product => $this->createCatalogProductFromSupplierProduct($supplierProduct, $row));

                $result['created']++;
                $result['messages'][] = "Supplier product {$supplierProduct->id} created catalog product {$product->id}.";
            } catch (Throwable $exception) {
                report($exception);

                $result['failed']++;
                $result['messages'][] = "Supplier product {$supplierProductId} failed: ".$exception->getMessage();
            }
        }

        $this->selectedSupplierProductIds = [];
        $this->lastManualSyncResult = $result;

        Notification::make()
            ->title('Selected CREATE products sync completed')
            ->body("Created {$result['created']}, skipped {$result['skipped']}, failed {$result['failed']}.")
            ->success()
            ->send();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{total: int, included: int, excluded: int, matched: int, unmatched: int, match_errors: int, create_rows: int, update_rows: int, skip_rows: int, conflict_rows: int, error_rows: int}
     */
    protected function queryOnlySummary(array $rows): array
    {
        $excluded = collect($rows)->where('excluded', true)->count();
        $matchErrors = collect($rows)->where('match_type', 'error')->count();
        $matched = collect($rows)
            ->filter(fn (array $row): bool => filled($row['matched_product_id'] ?? null))
            ->count();

        return [
            'total' => count($rows),
            'included' => count($rows) - $excluded,
            'excluded' => $excluded,
            'matched' => $matched,
            'unmatched' => count($rows) - $matched - $matchErrors,
            'match_errors' => $matchErrors,
            'create_rows' => collect($rows)->where('sync_action', 'CREATE')->count(),
            'update_rows' => collect($rows)->where('sync_action', 'UPDATE')->count(),
            'skip_rows' => collect($rows)->where('sync_action', 'SKIP')->count(),
            'conflict_rows' => collect($rows)->where('sync_action', 'CONFLICT')->count(),
            'error_rows' => collect($rows)->where('sync_action', 'ERROR')->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function basePreviewRowForSupplierProduct(SupplierProduct $supplierProduct): array
    {
        $supplierProduct->loadMissing(['supplier:id,company_name', 'availabilityStatus:id,name,code']);

        return [
            'supplier_product_id' => $supplierProduct->id,
            'supplier' => $supplierProduct->supplier?->company_name ?? '-',
            'supplier_sku' => $supplierProduct->supplier_sku ?: '-',
            'ean' => $supplierProduct->ean ?: '-',
            'mpn' => $supplierProduct->mpn ?: '-',
            'name' => $supplierProduct->name ?: '-',
            'price' => $supplierProduct->price,
            'quantity' => $supplierProduct->quantity ?? 0,
            'availability' => $supplierProduct->availabilityStatus?->name
                ?? $supplierProduct->external_availability_label
                ?? $supplierProduct->external_availability_status
                ?? $supplierProduct->status
                ?? '-',
            'status' => $supplierProduct->status ?: '-',
            'updated_at' => $supplierProduct->updated_at?->format('Y-m-d H:i'),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function isEligibleForManualCreateSync(SupplierProduct $supplierProduct, array $row): bool
    {
        return ($row['sync_action'] ?? null) === 'CREATE'
            && ! (bool) ($row['excluded'] ?? false)
            && blank($row['matched_product_id'] ?? null)
            && $this->hasMinimumSyncData($supplierProduct);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function createCatalogProductFromSupplierProduct(SupplierProduct $supplierProduct, array $row): Product
    {
        $supplierProduct->loadMissing('supplier');

        $name = $supplierProduct->name ?: $supplierProduct->supplier_sku ?: 'Supplier Product '.$supplierProduct->id;
        $sku = $supplierProduct->supplier_sku ?: $supplierProduct->ean ?: $supplierProduct->mpn ?: 'SP-'.$supplierProduct->id;
        $brand = $this->findExistingBrand($supplierProduct->brand_name);
        $category = $this->findExistingCategory($supplierProduct->category_name);
        $availability = app(AvailabilityStatusMapper::class)->mapWithFallback(
            'supplier',
            $supplierProduct->supplier?->company_name,
            $supplierProduct->external_availability_status,
            $supplierProduct->quantity,
        );
        $calculatedPrice = $row['calculated_price'] ?? $supplierProduct->price ?? 0;

        $product = Product::query()->create([
            'supplier_id' => $supplierProduct->supplier_id,
            'brand_id' => $brand?->id,
            'category_id' => $category?->id,
            'supplier_sku' => $supplierProduct->supplier_sku,
            'sku' => $this->uniqueSku((string) $sku),
            'ean' => $supplierProduct->ean,
            'mpn' => $supplierProduct->mpn,
            'name' => $name,
            'slug' => $this->uniqueSlug($name),
            'short_description' => null,
            'description' => null,
            'purchase_price' => $supplierProduct->price,
            'supplier_price_raw' => $supplierProduct->price,
            'recommended_price' => $supplierProduct->recommended_price,
            'final_selling_price' => $calculatedPrice,
            'regular_price' => $calculatedPrice,
            'source' => Product::SOURCE_SUPPLIER_IMPORT,
            'apply_pricing_rules' => false,
            'price_source' => Product::PRICE_SOURCE_SUPPLIER_IMPORT,
            'price' => $calculatedPrice,
            'sale_price' => null,
            'sale_price_starts_at' => null,
            'sale_price_ends_at' => null,
            'sale_price_source' => null,
            'quantity' => $supplierProduct->quantity ?? 0,
            'reserved_quantity' => 0,
            'availability_status_id' => $availability?->id,
            'stock_status' => $availability?->code ?? (($supplierProduct->quantity ?? 0) > 0 ? 'in_stock' : 'out_of_stock'),
            'product_status' => 'draft',
            'external_availability_status' => $supplierProduct->external_availability_status,
            'external_availability_label' => $supplierProduct->external_availability_label,
            'active' => false,
            'new_product' => true,
            'source_payload' => [
                'created_from_catalog_sync_preview' => true,
                'created_from_supplier_product_id' => $supplierProduct->id,
                'needs_enrichment' => true,
                'needs_category_mapping' => $category === null,
                'needs_brand_mapping' => $brand === null,
            ],
        ]);

        $offer = ProductSupplierOffer::query()->create([
            'product_id' => $product->id,
            'supplier_id' => $supplierProduct->supplier_id,
            'supplier_product_id' => $supplierProduct->id,
            'supplier_sku' => $supplierProduct->supplier_sku,
            'price' => $supplierProduct->price,
            'quantity' => $supplierProduct->quantity ?? 0,
            'currency' => $supplierProduct->currency ?: Product::CATALOG_CURRENCY,
            'supplier_priority' => $supplierProduct->supplier?->priority ?? 100,
            'is_preferred' => true,
            'last_seen_at' => now(),
        ]);

        $supplierProduct->update([
            'product_id' => $product->id,
            'status' => 'synced',
            'synced_at' => now(),
            'mapping_notes' => 'Created catalog product via Catalog Sync Preview selected CREATE action. Images and attributes were not imported.',
        ]);

        ProductSyncLog::query()->create([
            'product_id' => $product->id,
            'supplier_id' => $supplierProduct->supplier_id,
            'supplier_product_id' => $supplierProduct->id,
            'match_type' => 'created',
            'strategy' => 'manual_selected_create',
            'action' => 'created',
            'status' => 'synced',
            'message' => 'Created catalog product via Catalog Sync Preview selected CREATE action.',
            'before_data' => null,
            'after_data' => $product->fresh()->only(['id', 'sku', 'ean', 'mpn', 'price', 'quantity', 'supplier_id', 'supplier_sku']),
            'context' => [
                'supplier_offer_id' => $offer->id,
                'sync_action_preview' => $row['sync_action'] ?? 'CREATE',
                'sync_reason_preview' => $row['sync_reason'] ?? 'new_catalog_product',
                'images_imported' => 0,
                'attributes_imported' => 0,
            ],
        ]);

        return $product;
    }

    protected function uniqueSku(string $sku): string
    {
        $base = Str::upper(Str::slug($sku, '-')) ?: 'PRODUCT';
        $candidate = $base;
        $counter = 2;

        while (Product::query()->where('sku', $candidate)->exists()) {
            $candidate = "{$base}-{$counter}";
            $counter++;
        }

        return $candidate;
    }

    protected function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'product';
        $candidate = $base;
        $counter = 2;

        while (Product::query()->where('slug', $candidate)->exists()) {
            $candidate = "{$base}-{$counter}";
            $counter++;
        }

        return $candidate;
    }

    protected function hasMinimumSyncData(SupplierProduct $supplierProduct): bool
    {
        return filled($supplierProduct->name)
            && $supplierProduct->price !== null
            && (
                filled($supplierProduct->ean)
                || filled($supplierProduct->mpn)
                || filled($supplierProduct->supplier_sku)
            );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function matchedProductHasMeaningfulChanges(int $productId, SupplierProduct $supplierProduct, array $row): bool
    {
        $product = Product::query()
            ->select(['id', 'price', 'quantity', 'stock_status', 'supplier_id', 'supplier_sku'])
            ->find($productId);

        if (! $product) {
            return true;
        }

        return (float) ($product->price ?? 0) !== (float) ($row['calculated_price'] ?? $supplierProduct->price ?? 0)
            || (int) ($product->quantity ?? 0) !== (int) ($supplierProduct->quantity ?? 0)
            || (string) ($product->supplier_sku ?? '') !== (string) ($supplierProduct->supplier_sku ?? '')
            || (int) ($product->supplier_id ?? 0) !== (int) ($supplierProduct->supplier_id ?? 0);
    }

    protected function findExistingBrand(?string $name): ?Brand
    {
        if (blank($name)) {
            return null;
        }

        return Brand::query()->where('slug', Str::slug($name))->first();
    }

    protected function findExistingCategory(?string $categoryPath): ?Category
    {
        if (blank($categoryPath)) {
            return null;
        }

        $segments = preg_split('/\s*(?:>|\/|\|)\s*/', trim((string) $categoryPath)) ?: [];
        $lastSegment = collect($segments)
            ->map(fn (string $segment): string => trim($segment))
            ->filter()
            ->last();

        return $lastSegment ? Category::query()->where('slug', Str::slug($lastSegment))->first() : null;
    }

    protected function displayPricingRule(?PricingRule $rule, SupplierProduct $supplierProduct): string
    {
        if (! $rule) {
            return '-';
        }

        return match ($rule->scope_type) {
            PricingRule::SCOPE_PRODUCT => 'Product '.$rule->product?->name,
            PricingRule::SCOPE_CATEGORY_BRAND_SUPPLIER => trim(($rule->category?->name ?? 'Category').' + '.($rule->brand?->name ?? 'Brand').' + Supplier '.($rule->supplier?->company_name ?? $supplierProduct->supplier?->company_name)),
            PricingRule::SCOPE_CATEGORY_BRAND => trim(($rule->category?->name ?? 'Category').' + '.($rule->brand?->name ?? 'Brand')),
            PricingRule::SCOPE_CATEGORY_SUPPLIER => trim(($rule->category?->name ?? 'Category').' + Supplier '.($rule->supplier?->company_name ?? $supplierProduct->supplier?->company_name)),
            PricingRule::SCOPE_CATEGORY => $rule->category?->name ?? 'Category',
            PricingRule::SCOPE_BRAND => $rule->brand?->name ?? 'Brand',
            PricingRule::SCOPE_SUPPLIER => 'Supplier '.($rule->supplier?->company_name ?? $supplierProduct->supplier?->company_name),
            PricingRule::SCOPE_PRICE_RANGE => 'Price Range',
            PricingRule::SCOPE_GLOBAL => 'Global Default',
            default => $rule->name,
        };
    }
}

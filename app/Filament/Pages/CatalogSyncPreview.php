<?php

namespace App\Filament\Pages;

use App\Models\Brand;
use App\Models\Category;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Pricing\PricingEngine;
use App\Services\Products\CatalogSyncPreviewService;
use App\Services\Suppliers\SupplierExclusionService;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * @var array<string, mixed>
     */
    public array $filters = [
        'limit' => 50,
        'supplier_id' => null,
        'action' => null,
        'quick_filter' => null,
        'stock_status' => null,
        'category' => null,
        'brand' => null,
        'search' => null,
    ];

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
                            'create' => 'Create',
                            'update' => 'Update',
                            'skip' => 'Skip',
                            'conflict' => 'Conflict',
                        ]),
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
     * @return array{rows: array<int, array<string, mixed>>, error: string|null, limit: int|string, summary: array{total: int, included: int, excluded: int, matched: int, unmatched: int, match_errors: int}}
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

            if ($limit !== 'all') {
                $query->limit((int) $limit);
            }

            $rows = $query
                ->get()
                ->map(fn (SupplierProduct $supplierProduct): array => array_merge([
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
                ], $this->pricingPreviewForSupplierProduct($supplierProduct), $this->exclusionPreviewForSupplierProduct($supplierProduct), $this->matchingPreviewForSupplierProduct($supplierProduct)))
                ->all();

            return [
                'rows' => $rows,
                'error' => null,
                'limit' => $limit,
                'summary' => $this->queryOnlySummary($rows),
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
                ],
            ];
        }
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
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{total: int, included: int, excluded: int, matched: int, unmatched: int, match_errors: int}
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
        ];
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

<?php

namespace App\Filament\Pages;

use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Products\CatalogSyncPreviewService;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use UnitEnum;

class CatalogSyncPreview extends Page implements HasSchemas
{
    use InteractsWithSchemas;

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
        'category' => null,
        'brand' => null,
        'stock_status' => null,
        'action' => null,
        'quick_filter' => null,
        'search' => null,
        'sort_column' => null,
        'sort_direction' => 'asc',
    ];

    /**
     * @var array{summary: array<string, int|float>, rows: array<int, array<string, mixed>>, error?: string}
     */
    public array $previewPayload = [
        'summary' => [],
        'rows' => [],
    ];

    public bool $diagnosticsOnly = false;

    public ?string $diagnosticStep = null;

    /**
     * @var array<string, mixed>
     */
    public array $diagnosticReport = [];

    /**
     * @var array<int, string>
     */
    public array $diagnosticSteps = [
        'static',
        'suppliers',
        'filters',
        'selected_supplier',
        'query_rows',
        'preview_one',
        'preview_50',
    ];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('manage suppliers');
    }

    public function mount(): void
    {
        $this->diagnosticStep = $this->requestedDiagnosticStep();
        $this->diagnosticsOnly = $this->diagnosticStep !== null;

        if ($this->diagnosticsOnly) {
            $this->previewPayload = [
                'summary' => $this->emptySummary(),
                'rows' => [],
            ];
            $this->diagnosticReport = $this->runDiagnosticStep($this->diagnosticStep ?? 'static');

            return;
        }

        $this->filters['supplier_id'] ??= $this->defaultSupplierId();

        $this->refreshPreview();
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

    public function updatedFilters(mixed $value = null, ?string $key = null): void
    {
        if (! $this->diagnosticsOnly) {
            $this->refreshPreview();
        }
    }

    public function applyQuickFilter(?string $filter): void
    {
        $this->filters['quick_filter'] = blank($filter) ? null : $filter;

        if (in_array($filter, ['create', 'update', 'conflict'], true)) {
            $this->filters['action'] = $filter;
            $this->refreshPreview();

            return;
        }

        $this->filters['action'] = null;
        $this->refreshPreview();
    }

    public function sortBy(string $column): void
    {
        if (($this->filters['sort_column'] ?? null) === $column) {
            $this->filters['sort_direction'] = ($this->filters['sort_direction'] ?? 'asc') === 'asc' ? 'desc' : 'asc';
            $this->refreshPreview();

            return;
        }

        $this->filters['sort_column'] = $column;
        $this->filters['sort_direction'] = 'asc';
        $this->refreshPreview();
    }

    /**
     * @return array{summary: array<string, int|float>, rows: array<int, array<string, mixed>>, error?: string}
     */
    public function preview(): array
    {
        $this->refreshPreview();

        return $this->previewPayload;
    }

    protected function refreshPreview(): void
    {
        try {
            $this->previewPayload = app(CatalogSyncPreviewService::class)->preview($this->filters, $this->filters['limit'] ?? 50);
        } catch (Throwable $exception) {
            Log::error('Catalog Sync Preview page failed to render preview.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'filters' => $this->filters,
            ]);

            $this->previewPayload = [
                'summary' => $this->emptySummary(),
                'rows' => [],
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, int|float>
     */
    protected function emptySummary(): array
    {
        return [
            'total_staged_products' => 0,
            'to_create' => 0,
            'to_update' => 0,
            'to_skip' => 0,
            'conflicts' => 0,
            'missing_categories' => 0,
            'missing_images' => 0,
            'missing_ean' => 0,
            'excluded' => 0,
            'average_margin' => 0.0,
            'estimated_revenue' => 0.0,
            'estimated_profit' => 0.0,
        ];
    }

    protected function requestedDiagnosticStep(): ?string
    {
        if ((bool) config('services.catalog_sync_preview.diagnostics')
            || request()->boolean('diagnostics')
            || request()->boolean('catalog_sync_preview_diagnostics')) {
            return 'static';
        }

        $step = request()->string('diagnostic_step')->toString()
            ?: (string) config('services.catalog_sync_preview.diagnostic_step');

        if (blank($step)) {
            return null;
        }

        $step = Str::snake($step);

        return in_array($step, $this->diagnosticSteps, true) ? $step : 'static';
    }

    /**
     * @return array<string, mixed>
     */
    protected function runDiagnosticStep(string $step): array
    {
        $startedAt = microtime(true);

        try {
            $report = match ($step) {
                'suppliers' => $this->diagnoseSuppliers(),
                'filters' => $this->diagnoseFilters(),
                'selected_supplier' => $this->diagnoseSelectedSupplier(),
                'query_rows' => $this->diagnoseQueryRows(),
                'preview_one' => $this->diagnosePreviewOne(),
                'preview_50' => $this->diagnosePreviewLimited(),
                default => [
                    'message' => 'Static Filament page render completed without loading filters, suppliers, or preview services.',
                ],
            };

            return array_merge([
                'step' => $step,
                'status' => 'ok',
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ], $report);
        } catch (Throwable $exception) {
            Log::error('Catalog Sync Preview diagnostic step failed.', [
                'step' => $step,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return [
                'step' => $step,
                'status' => 'failed',
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function diagnoseSuppliers(): array
    {
        $suppliers = Supplier::query()
            ->orderBy('company_name')
            ->limit(10)
            ->get(['id', 'company_name', 'slug', 'status']);

        return [
            'message' => 'Supplier lookup completed.',
            'supplier_count' => Supplier::query()->count(),
            'sample_suppliers' => $suppliers
                ->map(fn (Supplier $supplier): string => "{$supplier->id}: {$supplier->company_name} ({$supplier->status})")
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function diagnoseFilters(): array
    {
        return [
            'message' => 'Filter form diagnostic selected. The Filament form will render below this report.',
            'filter_keys' => array_keys($this->filters),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function diagnoseSelectedSupplier(): array
    {
        $supplierId = $this->defaultSupplierId();
        $supplier = $supplierId ? Supplier::query()->find($supplierId) : null;

        return [
            'message' => 'Selected supplier lookup completed.',
            'selected_supplier_id' => $supplierId,
            'selected_supplier' => $supplier?->company_name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function diagnoseQueryRows(): array
    {
        $supplierId = $this->defaultSupplierId();
        $rows = SupplierProduct::query()
            ->with('supplier')
            ->when($supplierId, fn ($query) => $query->where('supplier_id', $supplierId))
            ->orderBy('id')
            ->limit(50)
            ->get(['id', 'supplier_id', 'supplier_sku', 'ean', 'mpn', 'name', 'price', 'quantity']);

        return [
            'message' => 'Supplier product row query completed without preview generation.',
            'selected_supplier_id' => $supplierId,
            'rows_found' => $rows->count(),
            'first_supplier_product_id' => $rows->first()?->id,
            'first_supplier_sku' => $rows->first()?->supplier_sku,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function diagnosePreviewOne(): array
    {
        $supplierId = $this->defaultSupplierId();
        $supplierProduct = SupplierProduct::query()
            ->with('supplier')
            ->when($supplierId, fn ($query) => $query->where('supplier_id', $supplierId))
            ->orderBy('id')
            ->first();

        if (! $supplierProduct) {
            return [
                'message' => 'No supplier products found for one-row preview.',
                'selected_supplier_id' => $supplierId,
            ];
        }

        $row = app(CatalogSyncPreviewService::class)->previewSupplierProduct($supplierProduct);

        return [
            'message' => 'One supplier product preview completed.',
            'selected_supplier_id' => $supplierId,
            'supplier_product_id' => $supplierProduct->id,
            'action' => $row['target_catalog_action'] ?? null,
            'product_name' => $row['product_name'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function diagnosePreviewLimited(): array
    {
        $supplierId = $this->defaultSupplierId();
        $payload = app(CatalogSyncPreviewService::class)->preview([
            'supplier_id' => $supplierId,
            'limit' => 50,
        ], 50);

        return [
            'message' => 'Limited 50-row preview completed.',
            'selected_supplier_id' => $supplierId,
            'rows_rendered' => count($payload['rows']),
            'summary' => $payload['summary'],
        ];
    }

    protected function defaultSupplierId(): ?int
    {
        return Supplier::query()
            ->where('slug', 'apcom')
            ->orWhere('company_name', 'APCOM')
            ->value('id');
    }
}

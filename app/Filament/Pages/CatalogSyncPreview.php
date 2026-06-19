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
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use UnitEnum;

class CatalogSyncPreview extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEye;

    protected static ?string $navigationLabel = 'Catalog Sync Preview';

    protected static string|UnitEnum|null $navigationGroup = 'Suppliers';

    protected string $view = 'filament.pages.catalog-sync-preview';

    protected const DIAGNOSTIC_STEPS = [
        'ping',
        'static',
        'suppliers',
        'filters',
        'selected_supplier',
        'query_rows',
        'query_first_5',
        'preview_one',
        'preview_row',
        'preview_trace',
        'preview_first_id',
        'preview_5',
        'preview_10',
        'preview_25',
        'preview_50',
    ];

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

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('manage suppliers');
    }

    /**
     * @return array<int, string>
     */
    public function diagnosticSteps(): array
    {
        return self::DIAGNOSTIC_STEPS;
    }

    public function mount(): void
    {
        Log::info('Catalog Sync Preview lifecycle: mount starting.', [
            'diagnostic_step_query' => request()->query('diagnostic_step'),
            'supplier_product_id_query' => request()->query('supplier_product_id'),
        ]);

        $this->diagnosticStep = $this->requestedDiagnosticStep();
        $this->diagnosticsOnly = $this->diagnosticStep !== null;

        Log::info('Catalog Sync Preview lifecycle: diagnostic step resolved.', [
            'diagnostic_step' => $this->diagnosticStep,
            'diagnostics_only' => $this->diagnosticsOnly,
        ]);

        if ($this->diagnosticsOnly) {
            if ($this->diagnosticStep === 'ping') {
                Log::info('Catalog Sync Preview lifecycle: ping diagnostic selected.');

                $this->diagnosticReport = [
                    'step' => 'ping',
                    'status' => 'ok',
                    'message' => 'Catalog Sync Preview ping OK',
                ];

                return;
            }

            $this->previewPayload = [
                'summary' => $this->emptySummary(),
                'rows' => [],
            ];

            Log::info('Catalog Sync Preview lifecycle: before diagnostic step dispatch.', [
                'diagnostic_step' => $this->diagnosticStep,
            ]);

            $this->diagnosticReport = $this->runDiagnosticStep($this->diagnosticStep ?? 'static');

            Log::info('Catalog Sync Preview lifecycle: after diagnostic step dispatch.', [
                'diagnostic_step' => $this->diagnosticStep,
                'diagnostic_status' => $this->diagnosticReport['status'] ?? null,
            ]);

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

        return in_array($step, self::DIAGNOSTIC_STEPS, true) ? $step : 'static';
    }

    /**
     * @return array<string, mixed>
     */
    protected function runDiagnosticStep(string $step): array
    {
        $startedAt = microtime(true);

        Log::info('Catalog Sync Preview lifecycle: runDiagnosticStep entered.', [
            'diagnostic_step' => $step,
        ]);

        try {
            $report = match ($step) {
                'suppliers' => $this->diagnoseSuppliers(),
                'filters' => $this->diagnoseFilters(),
                'selected_supplier' => $this->diagnoseSelectedSupplier(),
                'query_rows' => $this->diagnoseQueryRows(),
                'query_first_5' => $this->diagnoseQueryFirstRows(5),
                'preview_one' => $this->diagnosePreviewOne(),
                'preview_row' => $this->diagnosePreviewRow(),
                'preview_trace' => $this->diagnosePreviewTrace(),
                'preview_first_id' => $this->diagnosePreviewFirstId(),
                'preview_5' => $this->diagnosePreviewLimited(5),
                'preview_10' => $this->diagnosePreviewLimited(10),
                'preview_25' => $this->diagnosePreviewLimited(25),
                'preview_50' => $this->diagnosePreviewLimited(50),
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
        return array_merge([
            'message' => 'Supplier product row query completed without preview generation.',
        ], $this->querySupplierProductDiagnostics(50));
    }

    /**
     * @return array<string, mixed>
     */
    protected function diagnoseQueryFirstRows(int $limit): array
    {
        return array_merge([
            'message' => "First {$limit} supplier products queried without preview generation.",
        ], $this->querySupplierProductDiagnostics($limit));
    }

    /**
     * @return array<string, mixed>
     */
    protected function querySupplierProductDiagnostics(int $limit): array
    {
        $supplierId = $this->defaultSupplierId();
        $rows = SupplierProduct::query()
            ->with('supplier')
            ->when($supplierId, fn ($query) => $query->where('supplier_id', $supplierId))
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'supplier_id', 'supplier_sku', 'ean', 'mpn', 'name', 'price', 'quantity']);

        return [
            'selected_supplier_id' => $supplierId,
            'rows_found' => $rows->count(),
            'first_supplier_product_id' => $rows->first()?->id,
            'first_supplier_sku' => $rows->first()?->supplier_sku,
            'rows' => $rows
                ->map(fn (SupplierProduct $supplierProduct): array => $this->compactSupplierProduct($supplierProduct))
                ->all(),
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
            'row' => $this->compactPreviewRow($row, $supplierProduct, 1),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function diagnosePreviewRow(): array
    {
        $supplierProductId = request()->integer('supplier_product_id');

        if ($supplierProductId <= 0) {
            return [
                'message' => 'Missing supplier_product_id query parameter.',
                'supplier_product_id' => null,
            ];
        }

        $supplierProduct = SupplierProduct::query()
            ->with('supplier')
            ->find($supplierProductId);

        if (! $supplierProduct) {
            return [
                'message' => 'Supplier product was not found.',
                'supplier_product_id' => $supplierProductId,
            ];
        }

        return $this->previewSingleDiagnosticRow($supplierProduct, 1, 'preview_row');
    }

    /**
     * @return array<string, mixed>
     */
    protected function diagnosePreviewTrace(): array
    {
        Log::info('Catalog Sync Preview lifecycle: before preview_trace handler.', [
            'supplier_product_id_query' => request()->query('supplier_product_id'),
        ]);

        $supplierProductId = request()->integer('supplier_product_id');

        if ($supplierProductId <= 0) {
            Log::info('Catalog Sync Preview lifecycle: preview_trace missing supplier_product_id.');

            return [
                'message' => 'Missing supplier_product_id query parameter.',
                'supplier_product_id' => null,
            ];
        }

        $report = app(CatalogSyncPreviewService::class)->traceSupplierProductPreview($supplierProductId);

        Log::info('Catalog Sync Preview lifecycle: after preview_trace handler.', [
            'supplier_product_id' => $supplierProductId,
            'status' => $report['status'] ?? null,
            'last_successful_step' => $report['last_successful_step'] ?? null,
            'failing_step' => $report['failing_step'] ?? null,
        ]);

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    protected function diagnosePreviewFirstId(): array
    {
        $supplierId = $this->defaultSupplierId();
        $supplierProduct = SupplierProduct::query()
            ->with('supplier')
            ->when($supplierId, fn ($query) => $query->where('supplier_id', $supplierId))
            ->orderBy('id')
            ->first();

        if (! $supplierProduct) {
            return [
                'message' => 'No supplier products found for first-row preview.',
                'selected_supplier_id' => $supplierId,
            ];
        }

        return array_merge([
            'selected_supplier_id' => $supplierId,
        ], $this->previewSingleDiagnosticRow($supplierProduct, 1, 'preview_first_id'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function previewSingleDiagnosticRow(SupplierProduct $supplierProduct, int $rowNumber, string $step): array
    {
        Log::info('Catalog Sync Preview diagnostic row preview starting.', [
            'diagnostic_step' => $step,
            'row_index' => $rowNumber,
            'supplier_product_id' => $supplierProduct->id,
            'supplier_id' => $supplierProduct->supplier_id,
            'supplier_sku' => $supplierProduct->supplier_sku,
        ]);

        try {
            $row = app(CatalogSyncPreviewService::class)->previewSupplierProduct($supplierProduct);

            Log::info('Catalog Sync Preview diagnostic row preview completed.', [
                'diagnostic_step' => $step,
                'row_index' => $rowNumber,
                'supplier_product_id' => $supplierProduct->id,
                'supplier_sku' => $supplierProduct->supplier_sku,
                'target_catalog_action' => $row['target_catalog_action'] ?? null,
            ]);

            return [
                'message' => 'Single supplier product preview completed.',
                'row' => $this->compactPreviewRow($row, $supplierProduct, $rowNumber),
            ];
        } catch (Throwable $exception) {
            Log::warning('Catalog Sync Preview diagnostic row preview failed.', [
                'diagnostic_step' => $step,
                'row_index' => $rowNumber,
                'supplier_product_id' => $supplierProduct->id,
                'supplier_id' => $supplierProduct->supplier_id,
                'supplier_sku' => $supplierProduct->supplier_sku,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return [
                'message' => 'Single supplier product preview failed.',
                'row' => $this->failedDiagnosticPreviewRow($supplierProduct, $rowNumber, $exception),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function diagnosePreviewLimited(int $limit): array
    {
        $supplierId = $this->defaultSupplierId();
        $service = app(CatalogSyncPreviewService::class);
        $supplierProducts = SupplierProduct::query()
            ->with('supplier')
            ->when($supplierId, fn ($query) => $query->where('supplier_id', $supplierId))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $rows = [];
        $failedRows = [];

        foreach ($supplierProducts as $index => $supplierProduct) {
            $rowNumber = $index + 1;

            Log::info('Catalog Sync Preview diagnostic row preview starting.', [
                'diagnostic_step' => "preview_{$limit}",
                'row_index' => $rowNumber,
                'supplier_product_id' => $supplierProduct->id,
                'supplier_id' => $supplierProduct->supplier_id,
                'supplier_sku' => $supplierProduct->supplier_sku,
            ]);

            try {
                $row = $service->previewSupplierProduct($supplierProduct);

                Log::info('Catalog Sync Preview diagnostic row preview completed.', [
                    'diagnostic_step' => "preview_{$limit}",
                    'row_index' => $rowNumber,
                    'supplier_product_id' => $supplierProduct->id,
                    'supplier_sku' => $supplierProduct->supplier_sku,
                    'target_catalog_action' => $row['target_catalog_action'] ?? null,
                ]);

                $rows[] = $this->compactPreviewRow($row, $supplierProduct, $rowNumber);
            } catch (Throwable $exception) {
                Log::warning('Catalog Sync Preview diagnostic row preview failed.', [
                    'diagnostic_step' => "preview_{$limit}",
                    'row_index' => $rowNumber,
                    'supplier_product_id' => $supplierProduct->id,
                    'supplier_id' => $supplierProduct->supplier_id,
                    'supplier_sku' => $supplierProduct->supplier_sku,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);

                $failedRow = $this->failedDiagnosticPreviewRow($supplierProduct, $rowNumber, $exception);
                $rows[] = $failedRow;
                $failedRows[] = $failedRow;
            }
        }

        return [
            'message' => "Limited {$limit}-row preview completed.",
            'selected_supplier_id' => $supplierId,
            'rows_selected' => $supplierProducts->count(),
            'rows_rendered' => count($rows),
            'failed_rows' => count($failedRows),
            'first_failed_row' => $failedRows[0] ?? null,
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function compactPreviewRow(array $row, SupplierProduct $supplierProduct, int $rowNumber): array
    {
        return [
            'row_index' => $rowNumber,
            'supplier_product_id' => $supplierProduct->id,
            'supplier_sku' => $supplierProduct->supplier_sku,
            'ean' => $supplierProduct->ean,
            'product_name' => $row['product_name'] ?? $supplierProduct->name,
            'target_catalog_action' => $row['target_catalog_action'] ?? 'conflict',
            'reason' => $row['reason'] ?? null,
            'result' => $row['result'] ?? null,
            'exception' => null,
            'conflict_reasons' => $row['conflict_reasons'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function compactSupplierProduct(SupplierProduct $supplierProduct): array
    {
        return [
            'supplier_product_id' => $supplierProduct->id,
            'supplier_id' => $supplierProduct->supplier_id,
            'supplier' => $supplierProduct->supplier?->company_name,
            'supplier_sku' => $supplierProduct->supplier_sku,
            'ean' => $supplierProduct->ean,
            'mpn' => $supplierProduct->mpn,
            'name' => Str::limit((string) $supplierProduct->name, 120, '...'),
            'price' => $supplierProduct->price,
            'quantity' => $supplierProduct->quantity,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function failedDiagnosticPreviewRow(SupplierProduct $supplierProduct, int $rowNumber, Throwable $exception): array
    {
        return [
            'row_index' => $rowNumber,
            'supplier_product_id' => $supplierProduct->id,
            'supplier_sku' => $supplierProduct->supplier_sku,
            'ean' => $supplierProduct->ean,
            'product_name' => $supplierProduct->name ?: 'Supplier product '.$supplierProduct->id,
            'target_catalog_action' => 'conflict',
            'reason' => 'Preview generation failed',
            'result' => 'Conflict detected: preview row requires review before sync',
            'exception' => $exception::class,
            'exception_message' => $exception->getMessage(),
            'conflict_reasons' => ['preview_generation_failed'],
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

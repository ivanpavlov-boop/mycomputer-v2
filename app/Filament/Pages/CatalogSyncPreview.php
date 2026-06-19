<?php

namespace App\Filament\Pages;

use App\Models\Supplier;
use App\Models\SupplierProduct;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
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
     * @return array{rows: array<int, array<string, mixed>>, error: string|null, limit: int|string}
     */
    public function queryOnlySupplierProducts(): array
    {
        try {
            $limit = $this->filters['limit'] ?? 50;
            $query = SupplierProduct::query()
                ->with(['supplier:id,company_name', 'availabilityStatus:id,name,code'])
                ->select([
                    'id',
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
                ->map(fn (SupplierProduct $supplierProduct): array => [
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
                ])
                ->all();

            return [
                'rows' => $rows,
                'error' => null,
                'limit' => $limit,
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'rows' => [],
                'error' => $exception->getMessage(),
                'limit' => $this->filters['limit'] ?? 50,
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
}

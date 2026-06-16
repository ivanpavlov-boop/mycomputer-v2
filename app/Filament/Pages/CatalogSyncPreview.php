<?php

namespace App\Filament\Pages;

use App\Models\Supplier;
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
     * @return array{summary: array<string, int>, rows: array<int, array<string, mixed>>}
     */
    public function preview(): array
    {
        return app(CatalogSyncPreviewService::class)->preview($this->filters, $this->filters['limit'] ?? 50);
    }
}

<?php

namespace App\Filament\Resources\CsvExportJobs\Schemas;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Supplier;
use App\Support\Catalog\ProductCsvSchema;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CsvExportJobForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('CSV export')
                ->schema([
                    Grid::make(3)->schema([
                        Select::make('type')
                            ->options(ProductCsvSchema::exportTypeOptions())
                            ->required(),
                        Select::make('status')
                            ->options([
                                'pending' => 'Pending',
                                'running' => 'Running',
                                'completed' => 'Completed',
                                'failed' => 'Failed',
                            ])
                            ->default('pending')
                            ->required(),
                        TextInput::make('file_path')->disabled()->dehydrated(),
                        TextInput::make('total_rows')->numeric()->default(0),
                        TextInput::make('processed_rows')->numeric()->default(0),
                    ]),
                ]),
            Section::make('Filters')
                ->schema([
                    Grid::make(3)->schema([
                        Select::make('filters.category_id')
                            ->options(fn (): array => Category::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable(),
                        Select::make('filters.brand_id')
                            ->options(fn (): array => Brand::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable(),
                        Select::make('filters.supplier_id')
                            ->options(fn (): array => Supplier::query()->orderBy('company_name')->pluck('company_name', 'id')->all())
                            ->searchable(),
                        Select::make('filters.stock_status')->options([
                            'in_stock' => 'In stock',
                            'limited' => 'Limited',
                            'out_of_stock' => 'Out of stock',
                            'preorder' => 'Preorder',
                        ]),
                        Select::make('filters.active')->options(['1' => 'Active', '0' => 'Inactive']),
                        Select::make('filters.featured')->options(['1' => 'Featured', '0' => 'Not featured']),
                        DatePicker::make('filters.created_from'),
                        DatePicker::make('filters.created_until'),
                        DatePicker::make('filters.updated_from'),
                        DatePicker::make('filters.updated_until'),
                    ]),
                ])
                ->collapsible(),
        ]);
    }
}

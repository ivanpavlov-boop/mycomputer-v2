<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Product')
                    ->schema([
                        Grid::make(3)->schema([
                            TextInput::make('name')
                                ->required()
                                ->maxLength(255)
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug($state ?? '')))
                                ->columnSpan(2),
                            TextInput::make('slug')->required()->unique(ignoreRecord: true)->maxLength(255),
                            TextInput::make('sku')->required()->unique(ignoreRecord: true)->maxLength(255),
                            TextInput::make('supplier_sku')->maxLength(255),
                            TextInput::make('ean')->maxLength(255),
                            TextInput::make('mpn')->maxLength(255),
                            Select::make('category_id')->relationship('category', 'name')->searchable()->preload(),
                            Select::make('brand_id')->relationship('brand', 'name')->searchable()->preload(),
                            Select::make('supplier_id')->relationship('supplier', 'company_name')->searchable()->preload(),
                            TextInput::make('weight')->numeric()->suffix('kg'),
                            TextInput::make('warranty_months')->numeric()->suffix('months'),
                        ]),
                        Textarea::make('short_description')->rows(2)->columnSpanFull(),
                        RichEditor::make('description')->columnSpanFull(),
                    ]),
                Section::make('Pricing and inventory')
                    ->schema([
                        Grid::make(4)->schema([
                            TextInput::make('purchase_price')->numeric()->prefix('BGN'),
                            TextInput::make('price')->numeric()->prefix('BGN')->default(0)->required(),
                            TextInput::make('promo_price')->numeric()->prefix('BGN'),
                            DateTimePicker::make('promo_start'),
                            DateTimePicker::make('promo_end'),
                            TextInput::make('quantity')->numeric()->default(0)->required(),
                            TextInput::make('reserved_quantity')->numeric()->default(0)->required(),
                            Select::make('availability_status_id')
                                ->relationship('availabilityStatus', 'name', fn ($query) => $query->where('is_active', true)->orderBy('sort_order'))
                                ->searchable()
                                ->preload()
                                ->helperText('Admin-managed availability status. Leave manual override off for automatic quantity-based assignment.'),
                            Select::make('product_status')
                                ->options([
                                    'draft' => 'Draft',
                                    'active' => 'Active',
                                    'hidden' => 'Hidden',
                                    'archived' => 'Archived',
                                    'discontinued' => 'Discontinued',
                                ])
                                ->default('draft')
                                ->required(),
                            TextInput::make('stock_status')->helperText('Legacy mirrored status for older integrations.'),
                            Toggle::make('manual_override')->default(false),
                            TextInput::make('availability_message')->maxLength(255),
                            DateTimePicker::make('expected_date'),
                            TextInput::make('supplier_lead_time_days')->numeric()->suffix('days'),
                            DateTimePicker::make('published_at'),
                            Toggle::make('active')->default(false),
                            Toggle::make('featured')->default(false),
                            Toggle::make('new_product')->default(false),
                            Toggle::make('bestseller')->default(false),
                        ]),
                    ]),
                Section::make('Attributes')
                    ->schema([
                        Repeater::make('attributes')
                            ->relationship()
                            ->schema([
                                Select::make('product_attribute_id')
                                    ->relationship('attribute', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->required(),
                                Select::make('attribute_value_id')
                                    ->relationship('value', 'value')
                                    ->searchable()
                                    ->preload(),
                                TextInput::make('custom_value'),
                                Toggle::make('is_filterable')->default(true),
                            ])
                            ->columns(4)
                            ->defaultItems(0),
                    ])
                    ->collapsible(),
                Section::make('Images')
                    ->schema([
                        Repeater::make('images')
                            ->relationship()
                            ->schema([
                                FileUpload::make('path')
                                    ->disk('public')
                                    ->directory('products')
                                    ->image()
                                    ->required(),
                                TextInput::make('alt_text'),
                                TextInput::make('sort_order')->numeric()->default(0),
                                Toggle::make('is_primary')->default(false),
                            ])
                            ->columns(4)
                            ->defaultItems(0),
                    ])
                    ->collapsible(),
                Section::make('Relations')
                    ->schema([
                        Select::make('relatedProducts')
                            ->relationship('relatedProducts', 'name')
                            ->multiple()
                            ->searchable()
                            ->preload(),
                        Select::make('accessoryProducts')
                            ->relationship('accessoryProducts', 'name')
                            ->multiple()
                            ->searchable()
                            ->preload(),
                    ])
                    ->collapsible(),
                Section::make('SEO')
                    ->schema([
                        TextInput::make('meta_title')->maxLength(255),
                        Textarea::make('meta_description')->rows(2),
                        Textarea::make('meta_keywords')->rows(2),
                    ])
                    ->collapsible(),
                Section::make('Structured specifications')
                    ->schema([
                        Textarea::make('searchable_keywords')
                            ->rows(3)
                            ->helperText('Optional extra search terms for manual or future AI enrichment.')
                            ->columnSpanFull(),
                        KeyValue::make('specifications')
                            ->keyLabel('Specification')
                            ->valueLabel('Value'),
                    ])
                    ->collapsible()
                    ->collapsed(),
            ]);
    }
}

<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Models\Product;
use App\Models\ProductQualityFlag;
use App\Models\ProductQualityFlagAssignment;
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
                            Select::make('source')
                                ->options([
                                    Product::SOURCE_MANUAL => 'Manual',
                                    Product::SOURCE_SUPPLIER_IMPORT => 'Supplier import',
                                ])
                                ->default(Product::SOURCE_MANUAL)
                                ->required(),
                            Toggle::make('apply_pricing_rules')
                                ->default(false)
                                ->helperText('Explicitly apply pricing rules to this product even when it is manually managed.'),
                            TextInput::make('weight')->numeric()->suffix('kg'),
                            TextInput::make('warranty_months')->numeric()->suffix('months'),
                        ]),
                        Grid::make(3)->schema([
                            Toggle::make('lock_name')
                                ->label('Lock Product Name')
                                ->helperText('Prevent supplier sync from overwriting the curated product name.'),
                            Toggle::make('lock_seo')
                                ->label('Lock SEO')
                                ->helperText('Prevent supplier sync from overwriting meta title and meta description.'),
                            Toggle::make('lock_descriptions')
                                ->label('Lock Description')
                                ->helperText('Prevent supplier sync from overwriting short and full descriptions.'),
                        ]),
                        RichEditor::make('short_description')
                            ->toolbarButtons(self::shortDescriptionToolbar())
                            ->extraInputAttributes([
                                'style' => 'min-height: 8rem; max-height: 18rem; overflow-y: auto;',
                            ])
                            ->columnSpanFull(),
                        RichEditor::make('description')
                            ->toolbarButtons(self::productDescriptionToolbar())
                            ->extraInputAttributes([
                                'style' => 'min-height: 24rem; max-height: 42rem; overflow-y: auto;',
                            ])
                            ->columnSpanFull(),
                    ]),
                Section::make('Product workflow')
                    ->description('Manual products start as drafts. Use the workflow actions on the edit page to submit, approve and publish.')
                    ->schema([
                        Grid::make(3)->schema([
                            Select::make('workflow_status')
                                ->label('Workflow status')
                                ->options(Product::workflowStatusOptions())
                                ->default(Product::WORKFLOW_DRAFT)
                                ->disabled()
                                ->dehydrated()
                                ->required(),
                            Select::make('assigned_to')
                                ->label('Assigned to')
                                ->relationship('assignedTo', 'name')
                                ->searchable()
                                ->preload(),
                            Textarea::make('review_notes')
                                ->label('Review notes')
                                ->rows(2)
                                ->columnSpanFull(),
                        ]),
                    ]),
                Section::make('English localization')
                    ->description('Bulgarian remains the primary content in the main fields. English values are optional and are never filled by supplier sync.')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('name_translations.en')
                                ->label('English product name')
                                ->maxLength(255),
                            TextInput::make('slug_translations.en')
                                ->label('English slug')
                                ->maxLength(255),
                        ]),
                        RichEditor::make('short_description_translations.en')
                            ->label('English short description')
                            ->toolbarButtons(self::shortDescriptionToolbar())
                            ->extraInputAttributes([
                                'style' => 'min-height: 8rem; max-height: 18rem; overflow-y: auto;',
                            ])
                            ->columnSpanFull(),
                        RichEditor::make('description_translations.en')
                            ->label('English description')
                            ->toolbarButtons(self::productDescriptionToolbar())
                            ->extraInputAttributes([
                                'style' => 'min-height: 18rem; max-height: 36rem; overflow-y: auto;',
                            ])
                            ->columnSpanFull(),
                        Grid::make(2)->schema([
                            TextInput::make('meta_title_translations.en')
                                ->label('English SEO title')
                                ->maxLength(255),
                            Textarea::make('meta_description_translations.en')
                                ->label('English SEO description')
                                ->rows(2),
                        ]),
                    ])
                    ->collapsible()
                    ->collapsed(),
                Section::make('Pricing and inventory')
                    ->schema([
                        Grid::make(4)->schema([
                            TextInput::make('purchase_price')->numeric()->prefix('EUR'),
                            TextInput::make('regular_price')->numeric()->prefix('EUR'),
                            TextInput::make('price')
                                ->label('Regular selling price')
                                ->numeric()
                                ->prefix('EUR')
                                ->default(0)
                                ->required(),
                            Select::make('price_source')
                                ->options([
                                    Product::PRICE_SOURCE_MANUAL => 'Manual',
                                    Product::PRICE_SOURCE_SUPPLIER_IMPORT => 'Supplier import calculated',
                                    Product::PRICE_SOURCE_ADMIN_OVERRIDE => 'Admin override',
                                ])
                                ->default(Product::PRICE_SOURCE_MANUAL)
                                ->required(),
                            TextInput::make('sale_price')->numeric()->prefix('EUR'),
                            DateTimePicker::make('sale_price_starts_at')
                                ->helperText('Optional. Use this with sale price for one week, one month, or custom campaign ranges.'),
                            DateTimePicker::make('sale_price_ends_at')
                                ->helperText('Optional. Sale price is active only inside the configured range.'),
                            Select::make('sale_price_source')
                                ->options([
                                    Product::SALE_PRICE_SOURCE_MANUAL => 'Manual',
                                    Product::SALE_PRICE_SOURCE_PROMOTION_RULE => 'Promotion rule',
                                    Product::SALE_PRICE_SOURCE_SUPPLIER_FEED => 'Supplier feed',
                                ])
                                ->nullable(),
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
                Section::make('Product quality flags')
                    ->description('Non-blocking internal quality flags for content, SEO, media or data cleanup. They do not affect publishing by themselves.')
                    ->schema([
                        Repeater::make('qualityFlagAssignments')
                            ->relationship()
                            ->label('Assigned flags')
                            ->schema([
                                Select::make('product_quality_flag_id')
                                    ->label('Flag')
                                    ->options(fn () => ProductQualityFlag::query()->active()->ordered()->pluck('label_bg', 'id'))
                                    ->searchable()
                                    ->required(),
                                Select::make('status')
                                    ->options(ProductQualityFlagAssignment::statusOptions())
                                    ->default(ProductQualityFlagAssignment::STATUS_ACTIVE)
                                    ->required(),
                                Textarea::make('note')
                                    ->rows(2)
                                    ->columnSpanFull(),
                            ])
                            ->columns(2)
                            ->defaultItems(0),
                    ])
                    ->visible(fn (): bool => (bool) auth()->user()?->canManageProductQualityFlags())
                    ->collapsible()
                    ->collapsed(),
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

    /**
     * @return array<array<int, string>>
     */
    private static function shortDescriptionToolbar(): array
    {
        return [
            ['bold', 'italic', 'underline', 'link'],
            ['h2', 'h3'],
            ['bulletList', 'orderedList'],
            ['undo', 'redo'],
        ];
    }

    /**
     * @return array<array<int, string>>
     */
    private static function productDescriptionToolbar(): array
    {
        return [
            ['bold', 'italic', 'underline', 'link'],
            ['h2', 'h3'],
            ['blockquote', 'bulletList', 'orderedList'],
            ['table', 'horizontalRule'],
            ['code', 'codeBlock', 'clearFormatting'],
            ['undo', 'redo'],
        ];
    }
}

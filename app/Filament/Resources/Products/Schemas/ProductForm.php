<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Models\Product;
use App\Models\ProductQualityFlag;
use App\Models\ProductQualityFlagAssignment;
use App\Services\Products\ProductDataQualitySummaryService;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Основна информация')
                    ->schema([
                        Grid::make(3)->schema([
                            TextInput::make('name')
                                ->label('Име')
                                ->required()
                                ->maxLength(255)
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug($state ?? '')))
                                ->columnSpan(2),
                            TextInput::make('slug')->label('Slug')->required()->unique(ignoreRecord: true)->maxLength(255),
                            TextInput::make('sku')->label('SKU')->required()->unique(ignoreRecord: true)->maxLength(255),
                            TextInput::make('supplier_sku')->label('SKU при доставчик')->maxLength(255),
                            TextInput::make('ean')->label('EAN')->maxLength(255),
                            TextInput::make('mpn')->label('MPN')->maxLength(255),
                            Select::make('category_id')->label('Категория')->relationship('category', 'name')->searchable()->preload(),
                            Select::make('brand_id')->label('Бранд')->relationship('brand', 'name')->searchable()->preload(),
                            Select::make('supplier_id')->label('Доставчик')->relationship('supplier', 'company_name')->searchable()->preload(),
                            TextEntry::make('source_display')
                                ->label('Източник')
                                ->state(fn (?Product $record): string => $record?->source === Product::SOURCE_SUPPLIER_IMPORT ? 'От доставчик' : 'Ръчно')
                                ->badge(),
                            Toggle::make('apply_pricing_rules')
                                ->label('Прилагай ценови правила')
                                ->default(false)
                                ->helperText('Прилага ценовите правила към продукта дори когато е управляван ръчно.')
                                ->disabled(fn (): bool => ! (bool) auth()->user()?->canEditProductPrices()),
                            TextInput::make('weight')->label('Тегло')->numeric()->suffix('kg'),
                            TextInput::make('warranty_months')->label('Гаранция')->numeric()->suffix('месеца'),
                        ]),
                        Grid::make(3)->schema([
                            Toggle::make('lock_name')
                                ->label('Заключи името')
                                ->helperText('Не позволява синхронизация от доставчик да презапише ръчното име на продукта.'),
                            Toggle::make('lock_seo')
                                ->label('Заключи SEO')
                                ->helperText('Не позволява синхронизация от доставчик да презапише meta заглавие и meta описание.')
                                ->disabled(fn (): bool => ! (bool) auth()->user()?->canEditProductSeo()),
                            Toggle::make('lock_descriptions')
                                ->label('Заключи описанията')
                                ->helperText('Не позволява синхронизация от доставчик да презапише кратко и пълно описание.'),
                        ]),
                        RichEditor::make('short_description')
                            ->label('Кратко описание')
                            ->toolbarButtons(self::shortDescriptionToolbar())
                            ->extraInputAttributes([
                                'style' => 'min-height: 8rem; max-height: 18rem; overflow-y: auto;',
                            ])
                            ->columnSpanFull(),
                        RichEditor::make('description')
                            ->label('Описание')
                            ->toolbarButtons(self::productDescriptionToolbar())
                            ->extraInputAttributes([
                                'style' => 'min-height: 24rem; max-height: 42rem; overflow-y: auto;',
                            ])
                            ->columnSpanFull(),
                    ])
                    ->disabled(fn (): bool => ! (bool) auth()->user()?->canEditProductContent()),
                Section::make('Качество на продуктовите данни')
                    ->description('Обобщение на липсващи или непълни данни. Предупрежденията не блокират записа или работния процес.')
                    ->schema([
                        View::make('filament.products.data-quality-summary')
                            ->viewData(fn (?Product $record): array => [
                                'summary' => app(ProductDataQualitySummaryService::class)->summarize($record),
                            ])
                            ->columnSpanFull(),
                    ])
                    ->visible(fn (?Product $record): bool => (bool) $record?->exists),
                Section::make('Работен процес на продукта')
                    ->description('Ръчно създадените продукти започват като чернова. Използвайте действията в страницата за редакция за изпращане, одобрение и публикуване.')
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('workflow_status_display')
                                ->label('Работен статус')
                                ->state(fn (?Product $record): string => Product::workflowStatusLabel($record?->workflow_status ?? Product::WORKFLOW_DRAFT))
                                ->badge()
                                ->color(fn (?Product $record): string => Product::workflowStatusColor($record?->workflow_status ?? Product::WORKFLOW_DRAFT)),
                            Select::make('assigned_to')
                                ->label('Възложен на')
                                ->relationship('assignedTo', 'name')
                                ->searchable()
                                ->preload()
                                ->disabled(fn (): bool => ! (bool) auth()->user()?->canEditProductContent()),
                        ]),
                        Grid::make(4)->schema([
                            TextEntry::make('submitted_by_display')
                                ->label('Изпратен от')
                                ->state(fn (?Product $record): string => $record?->submittedBy?->name ?? 'Няма')
                                ->visible(fn (?Product $record): bool => $record !== null),
                            TextEntry::make('submitted_at_display')
                                ->label('Изпратен на')
                                ->state(fn (?Product $record) => $record?->submitted_at)
                                ->dateTime('d.m.Y H:i')
                                ->placeholder('Няма')
                                ->visible(fn (?Product $record): bool => $record !== null),
                            TextEntry::make('approved_by_display')
                                ->label('Одобрен от')
                                ->state(fn (?Product $record): string => $record?->approvedBy?->name ?? 'Няма')
                                ->visible(fn (?Product $record): bool => $record !== null),
                            TextEntry::make('approved_at_display')
                                ->label('Одобрен на')
                                ->state(fn (?Product $record) => $record?->approved_at)
                                ->dateTime('d.m.Y H:i')
                                ->placeholder('Няма')
                                ->visible(fn (?Product $record): bool => $record !== null),
                            TextEntry::make('published_by_display')
                                ->label('Публикуван от')
                                ->state(fn (?Product $record): string => $record?->publishedBy?->name ?? 'Няма')
                                ->visible(fn (?Product $record): bool => $record !== null),
                            TextEntry::make('published_at_display')
                                ->label('Публикуван на')
                                ->state(fn (?Product $record) => $record?->published_at)
                                ->dateTime('d.m.Y H:i')
                                ->placeholder('Няма')
                                ->visible(fn (?Product $record): bool => $record !== null),
                            TextEntry::make('returned_by_display')
                                ->label('Върнат от')
                                ->state(fn (?Product $record): string => $record?->returnedBy?->name ?? 'Няма')
                                ->visible(fn (?Product $record): bool => $record !== null),
                            TextEntry::make('returned_at_display')
                                ->label('Върнат на')
                                ->state(fn (?Product $record) => $record?->returned_at)
                                ->dateTime('d.m.Y H:i')
                                ->placeholder('Няма')
                                ->visible(fn (?Product $record): bool => $record !== null),
                            TextEntry::make('review_notes_display')
                                ->label('Последна бележка за преглед')
                                ->state(fn (?Product $record): ?string => $record?->review_notes)
                                ->placeholder('Няма')
                                ->visible(fn (?Product $record): bool => $record !== null)
                                ->columnSpanFull(),
                        ]),
                    ]),
                Section::make('Английска локализация')
                    ->description('Българският остава основното съдържание в главните полета. Английските стойности са незадължителни и не се попълват от синхронизация с доставчик.')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('name_translations.en')
                                ->label('Име на английски')
                                ->maxLength(255),
                            TextInput::make('slug_translations.en')
                                ->label('Slug на английски')
                                ->maxLength(255),
                        ]),
                        RichEditor::make('short_description_translations.en')
                            ->label('Кратко описание на английски')
                            ->toolbarButtons(self::shortDescriptionToolbar())
                            ->extraInputAttributes([
                                'style' => 'min-height: 8rem; max-height: 18rem; overflow-y: auto;',
                            ])
                            ->columnSpanFull(),
                        RichEditor::make('description_translations.en')
                            ->label('Описание на английски')
                            ->toolbarButtons(self::productDescriptionToolbar())
                            ->extraInputAttributes([
                                'style' => 'min-height: 18rem; max-height: 36rem; overflow-y: auto;',
                            ])
                            ->columnSpanFull(),
                        Grid::make(2)->schema([
                            TextInput::make('meta_title_translations.en')
                                ->label('SEO заглавие на английски')
                                ->maxLength(255)
                                ->disabled(fn (): bool => ! (bool) auth()->user()?->canEditProductSeo()),
                            Textarea::make('meta_description_translations.en')
                                ->label('SEO описание на английски')
                                ->rows(2)
                                ->disabled(fn (): bool => ! (bool) auth()->user()?->canEditProductSeo()),
                        ]),
                    ])
                    ->disabled(fn (): bool => ! (bool) auth()->user()?->canEditProductContent())
                    ->collapsible()
                    ->collapsed(),
                Section::make('Цени и наличност')
                    ->schema([
                        Grid::make(4)->schema([
                            TextInput::make('purchase_price')->label('Доставна цена')->numeric()->prefix('EUR')->disabled(fn (): bool => ! (bool) auth()->user()?->canEditProductPrices()),
                            TextInput::make('regular_price')->label('Редовна цена')->numeric()->prefix('EUR')->disabled(fn (): bool => ! (bool) auth()->user()?->canEditProductPrices()),
                            TextInput::make('price')
                                ->label('Цена')
                                ->numeric()
                                ->prefix('EUR')
                                ->default(0)
                                ->required()
                                ->disabled(fn (): bool => ! (bool) auth()->user()?->canEditProductPrices()),
                            Select::make('price_source')
                                ->label('Източник на цена')
                                ->options([
                                    Product::PRICE_SOURCE_MANUAL => 'Ръчно',
                                    Product::PRICE_SOURCE_SUPPLIER_IMPORT => 'Изчислено от доставчик',
                                    Product::PRICE_SOURCE_ADMIN_OVERRIDE => 'Админ корекция',
                                ])
                                ->default(Product::PRICE_SOURCE_MANUAL)
                                ->required()
                                ->disabled(fn (): bool => ! (bool) auth()->user()?->canEditProductPrices()),
                            TextInput::make('sale_price')->label('Промо цена')->numeric()->prefix('EUR')->disabled(fn (): bool => ! (bool) auth()->user()?->canEditProductPrices()),
                            DateTimePicker::make('sale_price_starts_at')
                                ->label('Начало на промо цена')
                                ->helperText('Незадължително. Използвайте за промо цена за седмица, месец или конкретна кампания.')
                                ->disabled(fn (): bool => ! (bool) auth()->user()?->canEditProductPrices()),
                            DateTimePicker::make('sale_price_ends_at')
                                ->label('Край на промо цена')
                                ->helperText('Незадължително. Промо цената е активна само в зададения период.')
                                ->disabled(fn (): bool => ! (bool) auth()->user()?->canEditProductPrices()),
                            Select::make('sale_price_source')
                                ->label('Източник на промо цена')
                                ->options([
                                    Product::SALE_PRICE_SOURCE_MANUAL => 'Ръчно',
                                    Product::SALE_PRICE_SOURCE_PROMOTION_RULE => 'Промо правило',
                                    Product::SALE_PRICE_SOURCE_SUPPLIER_FEED => 'Доставчик',
                                ])
                                ->nullable()
                                ->disabled(fn (): bool => ! (bool) auth()->user()?->canEditProductPrices()),
                            TextInput::make('quantity')->label('Количество')->numeric()->default(0)->required()->disabled(fn (): bool => ! (bool) auth()->user()?->canEditProductStock()),
                            TextInput::make('reserved_quantity')->label('Резервирано количество')->numeric()->default(0)->required()->disabled(fn (): bool => ! (bool) auth()->user()?->canEditProductStock()),
                            Select::make('availability_status_id')
                                ->label('Наличност')
                                ->relationship('availabilityStatus', 'name', fn ($query) => $query->where('is_active', true)->orderBy('sort_order'))
                                ->searchable()
                                ->preload()
                                ->helperText('Админ управляван статус на наличност. Оставете ръчната наличност изключена за автоматично определяне според количеството.')
                                ->disabled(fn (): bool => ! (bool) auth()->user()?->canEditProductStock()),
                            Select::make('stock_status')
                                ->label('Статус на наличност')
                                ->options(self::stockStatusOptions())
                                ->default(Product::STOCK_STATUS_IN_STOCK)
                                ->required()
                                ->helperText('Огледален статус за по-стари интеграции.')
                                ->disabled(fn (): bool => ! (bool) auth()->user()?->canEditProductStock()),
                            Toggle::make('manual_override')->label('Ръчна наличност')->default(false)->disabled(fn (): bool => ! (bool) auth()->user()?->canEditProductStock()),
                            TextInput::make('availability_message')->label('Съобщение за наличност')->maxLength(255)->disabled(fn (): bool => ! (bool) auth()->user()?->canEditProductStock()),
                            DateTimePicker::make('expected_date')->label('Очаквана дата')->disabled(fn (): bool => ! (bool) auth()->user()?->canEditProductStock()),
                            TextInput::make('supplier_lead_time_days')->label('Срок от доставчик')->numeric()->suffix('дни')->disabled(fn (): bool => ! (bool) auth()->user()?->canEditProductStock()),
                            Toggle::make('featured')->label('Препоръчан')->default(false)->disabled(fn (): bool => ! (bool) auth()->user()?->canEditProductContent()),
                            Toggle::make('new_product')->label('Нов продукт')->default(false)->disabled(fn (): bool => ! (bool) auth()->user()?->canEditProductContent()),
                            Toggle::make('bestseller')->label('Бестселър')->default(false)->disabled(fn (): bool => ! (bool) auth()->user()?->canEditProductContent()),
                        ]),
                    ]),
                Section::make('Флагове за качество')
                    ->description('Неблокиращи вътрешни флагове за съдържание, SEO, медия или данни. Те не влияят сами по себе си върху публикуването.')
                    ->schema([
                        Repeater::make('qualityFlagAssignments')
                            ->relationship()
                            ->label('Назначени флагове')
                            ->schema([
                                Select::make('product_quality_flag_id')
                                    ->label('Флаг')
                                    ->options(fn () => ProductQualityFlag::query()->active()->ordered()->pluck('label_bg', 'id'))
                                    ->searchable()
                                    ->required(),
                                Select::make('status')
                                    ->label('Статус')
                                    ->options(self::qualityFlagStatusOptions())
                                    ->default(ProductQualityFlagAssignment::STATUS_ACTIVE)
                                    ->required(),
                                Textarea::make('note')
                                    ->label('Бележка')
                                    ->rows(2)
                                    ->columnSpanFull(),
                            ])
                            ->columns(2)
                            ->defaultItems(0),
                    ])
                    ->visible(fn (): bool => (bool) auth()->user()?->canManageProductQualityFlags())
                    ->collapsible()
                    ->collapsed(),
                Section::make('Снимки')
                    ->schema([
                        Repeater::make('images')
                            ->label('Снимки')
                            ->relationship()
                            ->schema([
                                FileUpload::make('path')
                                    ->label('Файл')
                                    ->disk('public')
                                    ->directory('products')
                                    ->image()
                                    ->required(),
                                TextInput::make('alt_text')->label('Alt текст'),
                                TextInput::make('sort_order')->label('Ред на сортиране')->numeric()->default(0),
                                Toggle::make('is_primary')->label('Основна снимка')->default(false),
                            ])
                            ->columns(4)
                            ->defaultItems(0),
                    ])
                    ->disabled(fn (): bool => ! (bool) auth()->user()?->canEditProductContent())
                    ->collapsible(),
                Section::make('Свързани продукти')
                    ->schema([
                        Select::make('relatedProducts')
                            ->label('Свързани продукти')
                            ->relationship('relatedProducts', 'name')
                            ->multiple()
                            ->searchable()
                            ->preload(),
                        Select::make('accessoryProducts')
                            ->label('Аксесоари')
                            ->relationship('accessoryProducts', 'name')
                            ->multiple()
                            ->searchable()
                            ->preload(),
                    ])
                    ->disabled(fn (): bool => ! (bool) auth()->user()?->canEditProductContent())
                    ->collapsible(),
                Section::make('SEO')
                    ->schema([
                        TextInput::make('meta_title')->label('Meta заглавие')->maxLength(255),
                        Textarea::make('meta_description')->label('Meta описание')->rows(2),
                        Textarea::make('meta_keywords')->label('Meta ключови думи')->rows(2),
                    ])
                    ->disabled(fn (): bool => ! (bool) auth()->user()?->canEditProductSeo())
                    ->collapsible(),
                Section::make('Структурирани спецификации')
                    ->schema([
                        Textarea::make('searchable_keywords')
                            ->label('Ключови думи за търсене')
                            ->rows(3)
                            ->helperText('Незадължителни допълнителни думи за ръчно или бъдещо AI обогатяване.')
                            ->columnSpanFull(),
                        KeyValue::make('specifications')
                            ->label('Спецификации')
                            ->keyLabel('Спецификация')
                            ->valueLabel('Стойност'),
                    ])
                    ->disabled(fn (): bool => ! (bool) auth()->user()?->canEditProductContent())
                    ->collapsible()
                    ->collapsed(),
            ]);
    }

    /**
     * @return array<string, string>
     */
    private static function stockStatusOptions(): array
    {
        return [
            Product::STOCK_STATUS_OUT_OF_STOCK => 'Няма наличност',
            Product::STOCK_STATUS_IN_STOCK => 'В наличност',
            Product::STOCK_STATUS_LIMITED_STOCK => 'Ограничена наличност',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function qualityFlagStatusOptions(): array
    {
        return [
            ProductQualityFlagAssignment::STATUS_ACTIVE => 'Активен',
            ProductQualityFlagAssignment::STATUS_RESOLVED => 'Решен',
        ];
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

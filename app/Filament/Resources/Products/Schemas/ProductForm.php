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
                            Select::make('source')
                                ->label('Източник')
                                ->options([
                                    Product::SOURCE_MANUAL => 'Ръчно',
                                    Product::SOURCE_SUPPLIER_IMPORT => 'От доставчик',
                                ])
                                ->default(Product::SOURCE_MANUAL)
                                ->required(),
                            Toggle::make('apply_pricing_rules')
                                ->label('Прилагай ценови правила')
                                ->default(false)
                                ->helperText('Прилага ценовите правила към продукта дори когато е управляван ръчно.'),
                            TextInput::make('weight')->label('Тегло')->numeric()->suffix('kg'),
                            TextInput::make('warranty_months')->label('Гаранция')->numeric()->suffix('месеца'),
                        ]),
                        Grid::make(3)->schema([
                            Toggle::make('lock_name')
                                ->label('Заключи името')
                                ->helperText('Не позволява синхронизация от доставчик да презапише ръчното име на продукта.'),
                            Toggle::make('lock_seo')
                                ->label('Заключи SEO')
                                ->helperText('Не позволява синхронизация от доставчик да презапише meta заглавие и meta описание.'),
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
                    ]),
                Section::make('Работен процес на продукта')
                    ->description('Ръчно създадените продукти започват като чернова. Използвайте действията в страницата за редакция за изпращане, одобрение и публикуване.')
                    ->schema([
                        Grid::make(3)->schema([
                            Select::make('workflow_status')
                                ->label('Работен статус')
                                ->options(self::workflowStatusOptions())
                                ->default(Product::WORKFLOW_DRAFT)
                                ->disabled()
                                ->dehydrated()
                                ->required(),
                            Select::make('assigned_to')
                                ->label('Възложен на')
                                ->relationship('assignedTo', 'name')
                                ->searchable()
                                ->preload(),
                            Textarea::make('review_notes')
                                ->label('Бележки за преглед')
                                ->rows(2)
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
                                ->maxLength(255),
                            Textarea::make('meta_description_translations.en')
                                ->label('SEO описание на английски')
                                ->rows(2),
                        ]),
                    ])
                    ->collapsible()
                    ->collapsed(),
                Section::make('Цени и наличност')
                    ->schema([
                        Grid::make(4)->schema([
                            TextInput::make('purchase_price')->label('Доставна цена')->numeric()->prefix('EUR'),
                            TextInput::make('regular_price')->label('Редовна цена')->numeric()->prefix('EUR'),
                            TextInput::make('price')
                                ->label('Цена')
                                ->numeric()
                                ->prefix('EUR')
                                ->default(0)
                                ->required(),
                            Select::make('price_source')
                                ->label('Източник на цена')
                                ->options([
                                    Product::PRICE_SOURCE_MANUAL => 'Ръчно',
                                    Product::PRICE_SOURCE_SUPPLIER_IMPORT => 'Изчислено от доставчик',
                                    Product::PRICE_SOURCE_ADMIN_OVERRIDE => 'Админ корекция',
                                ])
                                ->default(Product::PRICE_SOURCE_MANUAL)
                                ->required(),
                            TextInput::make('sale_price')->label('Промо цена')->numeric()->prefix('EUR'),
                            DateTimePicker::make('sale_price_starts_at')
                                ->label('Начало на промо цена')
                                ->helperText('Незадължително. Използвайте за промо цена за седмица, месец или конкретна кампания.'),
                            DateTimePicker::make('sale_price_ends_at')
                                ->label('Край на промо цена')
                                ->helperText('Незадължително. Промо цената е активна само в зададения период.'),
                            Select::make('sale_price_source')
                                ->label('Източник на промо цена')
                                ->options([
                                    Product::SALE_PRICE_SOURCE_MANUAL => 'Ръчно',
                                    Product::SALE_PRICE_SOURCE_PROMOTION_RULE => 'Промо правило',
                                    Product::SALE_PRICE_SOURCE_SUPPLIER_FEED => 'Доставчик',
                                ])
                                ->nullable(),
                            TextInput::make('quantity')->label('Количество')->numeric()->default(0)->required(),
                            TextInput::make('reserved_quantity')->label('Резервирано количество')->numeric()->default(0)->required(),
                            Select::make('availability_status_id')
                                ->label('Наличност')
                                ->relationship('availabilityStatus', 'name', fn ($query) => $query->where('is_active', true)->orderBy('sort_order'))
                                ->searchable()
                                ->preload()
                                ->helperText('Админ управляван статус на наличност. Оставете ръчната наличност изключена за автоматично определяне според количеството.'),
                            Select::make('product_status')
                                ->label('Продуктов статус')
                                ->options([
                                    'draft' => 'Чернова',
                                    'active' => 'Активен',
                                    'hidden' => 'Скрит',
                                    'archived' => 'Архивиран',
                                    'discontinued' => 'Спрян',
                                ])
                                ->default('draft')
                                ->required(),
                            Select::make('stock_status')
                                ->label('Статус на наличност')
                                ->options(self::stockStatusOptions())
                                ->default(Product::STOCK_STATUS_IN_STOCK)
                                ->required()
                                ->helperText('Огледален статус за по-стари интеграции.'),
                            Toggle::make('manual_override')->label('Ръчна наличност')->default(false),
                            TextInput::make('availability_message')->label('Съобщение за наличност')->maxLength(255),
                            DateTimePicker::make('expected_date')->label('Очаквана дата'),
                            TextInput::make('supplier_lead_time_days')->label('Срок от доставчик')->numeric()->suffix('дни'),
                            DateTimePicker::make('published_at')->label('Публикуван на'),
                            Toggle::make('active')->label('Активен')->default(false),
                            Toggle::make('featured')->label('Препоръчан')->default(false),
                            Toggle::make('new_product')->label('Нов продукт')->default(false),
                            Toggle::make('bestseller')->label('Бестселър')->default(false),
                        ]),
                    ]),
                Section::make('Атрибути')
                    ->schema([
                        Repeater::make('attributes')
                            ->label('Атрибути')
                            ->relationship()
                            ->schema([
                                Select::make('product_attribute_id')
                                    ->label('Атрибут')
                                    ->relationship('attribute', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->required(),
                                Select::make('attribute_value_id')
                                    ->label('Стойност')
                                    ->relationship('value', 'value')
                                    ->searchable()
                                    ->preload(),
                                TextInput::make('custom_value')->label('Ръчна стойност'),
                                Toggle::make('is_filterable')->label('Използва се за филтри')->default(true),
                            ])
                            ->columns(4)
                            ->defaultItems(0),
                    ])
                    ->collapsible(),
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
                    ->collapsible(),
                Section::make('SEO')
                    ->schema([
                        TextInput::make('meta_title')->label('Meta заглавие')->maxLength(255),
                        Textarea::make('meta_description')->label('Meta описание')->rows(2),
                        Textarea::make('meta_keywords')->label('Meta ключови думи')->rows(2),
                    ])
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
                    ->collapsible()
                    ->collapsed(),
            ]);
    }

    /**
     * @return array<string, string>
     */
    private static function workflowStatusOptions(): array
    {
        return [
            Product::WORKFLOW_DRAFT => 'Чернова',
            Product::WORKFLOW_PENDING_REVIEW => 'За преглед',
            Product::WORKFLOW_CHANGES_REQUESTED => 'Върнат за корекции',
            Product::WORKFLOW_APPROVED => 'Одобрен',
            Product::WORKFLOW_PUBLISHED => 'Публикуван',
        ];
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

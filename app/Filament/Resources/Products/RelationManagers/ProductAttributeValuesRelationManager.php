<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Models\AttributeValue;
use App\Models\CategoryProductAttribute;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProductAttributeValuesRelationManager extends RelationManager
{
    protected static string $relationship = 'attributeValues';

    protected static ?string $title = 'Характеристики';

    protected static ?string $modelLabel = 'характеристика';

    protected static ?string $pluralModelLabel = 'Характеристики';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Продуктови характеристики')
                    ->description('Ръчно управлявани вътрешни характеристики за този продукт. Предложенията идват първо от категорийния набор, когато има такъв.')
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('product_attribute_id')
                                ->label('Характеристика')
                                ->options(fn (): array => $this->attributeOptionsForProduct($this->getOwnerProduct()))
                                ->searchable()
                                ->required()
                                ->live()
                                ->helperText('Атрибутите от категорията на продукта са показани първи. Ако няма категориен набор, се показват всички активни характеристики.')
                                ->afterStateUpdated(function (Set $set, ?int $state): void {
                                    $attribute = $state ? ProductAttribute::query()->find($state) : null;

                                    $set('attribute_value_id', null);
                                    $set('selected_attribute_value_ids', []);
                                    $set('value_text', null);
                                    $set('value_number', null);
                                    $set('value_boolean', false);
                                    $set('value_json_text', null);
                                    $set('custom_value', null);
                                    $set('unit', $attribute?->unit);
                                    $set('is_filterable', (bool) ($attribute?->is_filterable ?? false));
                                }),
                            TextInput::make('attribute_type_preview')
                                ->label('Тип')
                                ->dehydrated(false)
                                ->disabled()
                                ->formatStateUsing(fn (Get $get): string => $this->attributeTypeLabel((string) $this->attributeType($get('product_attribute_id'))))
                                ->helperText('Полето е само за ориентация. Съвместимата стойност се проверява при запис.'),
                        ]),
                        Select::make('attribute_value_id')
                            ->label('Контролирана стойност')
                            ->options(fn (Get $get): array => $this->optionValuesForAttribute($get('product_attribute_id')))
                            ->searchable()
                            ->visible(fn (Get $get): bool => $this->attributeType($get('product_attribute_id')) === ProductAttribute::TYPE_SELECT)
                            ->helperText('Показват се само активни опции за избраната характеристика.'),
                        Select::make('selected_attribute_value_ids')
                            ->label('Контролирани стойности')
                            ->options(fn (Get $get): array => $this->optionValuesForAttribute($get('product_attribute_id')))
                            ->multiple()
                            ->searchable()
                            ->visible(fn (Get $get): bool => $this->attributeType($get('product_attribute_id')) === ProductAttribute::TYPE_MULTISELECT)
                            ->helperText('Избраните опции се пазят като JSON списък в един ред за продукт + характеристика.'),
                        TextInput::make('value_text')
                            ->label('Текстова стойност')
                            ->maxLength(1000)
                            ->visible(fn (Get $get): bool => $this->attributeType($get('product_attribute_id')) === ProductAttribute::TYPE_TEXT),
                        TextInput::make('value_number')
                            ->label('Числова стойност')
                            ->numeric()
                            ->visible(fn (Get $get): bool => in_array($this->attributeType($get('product_attribute_id')), [
                                ProductAttribute::TYPE_NUMBER,
                                ProductAttribute::TYPE_DECIMAL,
                            ], true)),
                        Toggle::make('value_boolean')
                            ->label('Да / Не')
                            ->default(false)
                            ->visible(fn (Get $get): bool => $this->attributeType($get('product_attribute_id')) === ProductAttribute::TYPE_BOOLEAN),
                        Textarea::make('value_json_text')
                            ->label('JSON стойност')
                            ->rows(4)
                            ->visible(fn (Get $get): bool => $this->attributeType($get('product_attribute_id')) === ProductAttribute::TYPE_JSON)
                            ->helperText('Въведете валиден JSON. Това е ръчно поле за редки структурирани стойности.'),
                        Grid::make(4)->schema([
                            TextInput::make('unit')
                                ->label('Мерна единица')
                                ->maxLength(50),
                            Select::make('source')
                                ->label('Източник')
                                ->options([
                                    ProductAttributeValue::SOURCE_MANUAL => 'Ръчно',
                                ])
                                ->default(ProductAttributeValue::SOURCE_MANUAL)
                                ->disabled()
                                ->dehydrated()
                                ->required(),
                            Toggle::make('is_verified')
                                ->label('Проверена')
                                ->default(false),
                            Toggle::make('is_filterable')
                                ->label('Бъдещ филтър')
                                ->default(false),
                            TextInput::make('sort_order')
                                ->label('Подредба')
                                ->numeric()
                                ->default(0),
                        ]),
                        Hidden::make('custom_value'),
                    ]),
            ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function categorySpecificationRowsForProduct(Product $product): array
    {
        $categoryIds = $this->categoryIdsForProduct($product);

        if ($categoryIds === []) {
            return [];
        }

        $assignments = CategoryProductAttribute::query()
            ->with('attribute')
            ->whereIn('category_id', $categoryIds)
            ->whereHas('attribute', fn ($query) => $query->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($assignments->isEmpty()) {
            return [];
        }

        $values = $product
            ->attributeValues()
            ->with(['attribute', 'value'])
            ->whereIn('product_attribute_id', $assignments->pluck('product_attribute_id')->unique()->all())
            ->get()
            ->keyBy('product_attribute_id');

        $seen = [];
        $rows = [];

        foreach ($assignments as $assignment) {
            $attribute = $assignment->attribute;

            if (! $attribute || isset($seen[$attribute->id])) {
                continue;
            }

            $seen[$attribute->id] = true;

            $rows[] = [
                'assignment' => $assignment,
                'attribute' => $attribute,
                'product_attribute_id' => (int) $attribute->id,
                'label' => $this->attributeDisplayLabel($attribute),
                'type' => (string) $attribute->type,
                'unit' => $attribute->unit,
                'is_required' => (bool) $assignment->is_required,
                'sort_order' => (int) $assignment->sort_order,
                'value' => $values->get($attribute->id),
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, ProductAttributeValue>
     */
    public function outOfCategoryAttributeValuesForProduct(Product $product): array
    {
        $categoryAttributeIds = collect($this->categorySpecificationRowsForProduct($product))
            ->pluck('product_attribute_id')
            ->all();

        return $product
            ->attributeValues()
            ->with(['attribute', 'value'])
            ->when($categoryAttributeIds !== [], fn ($query) => $query->whereNotIn('product_attribute_id', $categoryAttributeIds))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * @return array<int, Section>
     */
    public function categorySpecificationFormSchema(): array
    {
        return collect($this->categorySpecificationRowsForProduct($this->getOwnerProduct()))
            ->map(fn (array $row): Section => Section::make($row['label'])
                ->description($this->categorySpecificationDescription($row))
                ->schema([
                    ...$this->categorySpecificationValueFields($row),
                    Grid::make(4)->schema([
                        TextInput::make("specifications.{$row['product_attribute_id']}.unit")
                            ->label('Мерна единица')
                            ->maxLength(50),
                        Toggle::make("specifications.{$row['product_attribute_id']}.is_verified")
                            ->label('Проверена')
                            ->default(false),
                        Toggle::make("specifications.{$row['product_attribute_id']}.is_filterable")
                            ->label('Бъдещ филтър')
                            ->default((bool) ($row['attribute']->is_filterable ?? false)),
                        TextInput::make("specifications.{$row['product_attribute_id']}.sort_order")
                            ->label('Подредба')
                            ->numeric()
                            ->default($row['sort_order']),
                    ]),
                ]))
            ->values()
            ->all();
    }

    /**
     * @return array<int, mixed>
     */
    private function categorySpecificationValueFields(array $row): array
    {
        $fieldPrefix = "specifications.{$row['product_attribute_id']}";
        $attribute = $row['attribute'];

        return match ($row['type']) {
            ProductAttribute::TYPE_TEXT => [
                TextInput::make("{$fieldPrefix}.value_text")
                    ->label('Стойност')
                    ->maxLength(1000)
                    ->helperText('Оставете празно, ако стойността още не е известна.'),
            ],
            ProductAttribute::TYPE_NUMBER, ProductAttribute::TYPE_DECIMAL => [
                TextInput::make("{$fieldPrefix}.value_number")
                    ->label('Числова стойност')
                    ->numeric()
                    ->helperText('Оставете празно, ако стойността още не е известна.'),
            ],
            ProductAttribute::TYPE_BOOLEAN => [
                Select::make("{$fieldPrefix}.value_boolean")
                    ->label('Да / Не')
                    ->options([
                        '1' => 'Да',
                        '0' => 'Не',
                    ])
                    ->placeholder('Без стойност')
                    ->native(false)
                    ->helperText('Без стойност не създава ред.'),
            ],
            ProductAttribute::TYPE_SELECT => [
                Select::make("{$fieldPrefix}.attribute_value_id")
                    ->label('Контролирана стойност')
                    ->options(fn (): array => $this->optionValuesForAttribute($attribute->id))
                    ->searchable()
                    ->placeholder('Без стойност')
                    ->helperText('Изберете само съществуваща опция за тази характеристика.'),
            ],
            ProductAttribute::TYPE_MULTISELECT => [
                Select::make("{$fieldPrefix}.selected_attribute_value_ids")
                    ->label('Контролирани стойности')
                    ->options(fn (): array => $this->optionValuesForAttribute($attribute->id))
                    ->multiple()
                    ->searchable()
                    ->helperText('Празен избор не създава ред.'),
            ],
            ProductAttribute::TYPE_JSON => [
                Textarea::make("{$fieldPrefix}.value_json_text")
                    ->label('JSON стойност')
                    ->rows(3)
                    ->helperText('Оставете празно, ако стойността още не е известна.'),
            ],
            default => [
                TextInput::make("{$fieldPrefix}.value_text")
                    ->label('Стойност')
                    ->maxLength(1000),
            ],
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function categorySpecificationFormData(): array
    {
        $data = [];

        foreach ($this->categorySpecificationRowsForProduct($this->getOwnerProduct()) as $row) {
            /** @var ProductAttribute $attribute */
            $attribute = $row['attribute'];
            /** @var ProductAttributeValue|null $value */
            $value = $row['value'];
            $attributeId = (int) $row['product_attribute_id'];

            $data[$attributeId] = [
                'unit' => $value?->unit ?? $attribute->unit,
                'is_verified' => (bool) ($value?->is_verified ?? false),
                'is_filterable' => (bool) ($value?->is_filterable ?? $attribute->is_filterable),
                'sort_order' => $value?->sort_order ?? $row['sort_order'],
                'attribute_value_id' => $value?->attribute_value_id,
                'selected_attribute_value_ids' => $value ? $this->selectedOptionIdsFromJson($value->value_json) : [],
                'value_text' => $value?->value_text,
                'value_number' => $value?->value_number,
                'value_boolean' => $value?->value_boolean === null ? null : ($value->value_boolean ? '1' : '0'),
                'value_json_text' => $value?->value_json === null
                    ? null
                    : json_encode($value->value_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveCategorySpecifications(array $data): void
    {
        $product = $this->getOwnerProduct();
        $specifications = Arr::wrap($data['specifications'] ?? []);

        foreach ($this->categorySpecificationRowsForProduct($product) as $row) {
            /** @var ProductAttribute $attribute */
            $attribute = $row['attribute'];
            $attributeId = (int) $row['product_attribute_id'];
            $submitted = Arr::wrap($specifications[$attributeId] ?? $specifications[(string) $attributeId] ?? []);
            $record = $this->existingAttributeValueForAttribute($product, $attributeId);

            if (! $this->categorySpecificationHasValue($attribute, $submitted)) {
                $record?->delete();

                continue;
            }

            $normalized = $this->normalizeFormData([
                ...$submitted,
                'product_attribute_id' => $attributeId,
                'source' => ProductAttributeValue::SOURCE_MANUAL,
                'sort_order' => $submitted['sort_order'] ?? $row['sort_order'],
                'is_filterable' => $submitted['is_filterable'] ?? $attribute->is_filterable,
            ], $record);

            if ($record) {
                $record->update($normalized);

                continue;
            }

            $product->attributeValues()->create($normalized);
        }
    }

    /**
     * @return array<int, int>
     */
    private function categoryIdsForProduct(Product $product): array
    {
        return collect([$product->category_id])
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function categorySpecificationDescription(array $row): string
    {
        $parts = [
            'Тип: '.$this->attributeTypeLabel((string) $row['type']),
        ];

        if (filled($row['unit'] ?? null)) {
            $parts[] = 'Мерна единица: '.$row['unit'];
        }

        if ((bool) ($row['is_required'] ?? false)) {
            $parts[] = 'Важна за категорията, но не блокира запис.';
        }

        return implode(' · ', $parts);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function categorySpecificationHasValue(ProductAttribute $attribute, array $data): bool
    {
        return match ($attribute->type) {
            ProductAttribute::TYPE_TEXT => is_string($data['value_text'] ?? null) && trim($data['value_text']) !== '',
            ProductAttribute::TYPE_NUMBER, ProductAttribute::TYPE_DECIMAL => array_key_exists('value_number', $data)
                && $data['value_number'] !== null
                && $data['value_number'] !== '',
            ProductAttribute::TYPE_BOOLEAN => array_key_exists('value_boolean', $data)
                && $data['value_boolean'] !== null
                && $data['value_boolean'] !== '',
            ProductAttribute::TYPE_SELECT => filled($data['attribute_value_id'] ?? null),
            ProductAttribute::TYPE_MULTISELECT => array_values(array_filter(Arr::wrap($data['selected_attribute_value_ids'] ?? []))) !== [],
            ProductAttribute::TYPE_JSON => is_string($data['value_json_text'] ?? null) && trim($data['value_json_text']) !== '',
            default => is_string($data['value_text'] ?? null) && trim($data['value_text']) !== '',
        };
    }

    private function existingAttributeValueForAttribute(Product $product, int $attributeId): ?ProductAttributeValue
    {
        return $product
            ->attributeValues()
            ->where('product_attribute_id', $attributeId)
            ->first();
    }

    private function categoryScopeLabel(ProductAttributeValue $record): string
    {
        return $this->categoryAssignmentForAttribute($record) ? 'Категорийна' : 'Допълнителна';
    }

    private function categoryScopeColor(ProductAttributeValue $record): string
    {
        return $this->categoryAssignmentForAttribute($record) ? 'primary' : 'gray';
    }

    private function categoryRequiredState(ProductAttributeValue $record): bool
    {
        return (bool) $this->categoryAssignmentForAttribute($record)?->is_required;
    }

    private function categoryAssignmentForAttribute(ProductAttributeValue $record): ?CategoryProductAttribute
    {
        $categoryIds = $this->categoryIdsForProduct($this->getOwnerProduct());

        if ($categoryIds === []) {
            return null;
        }

        return CategoryProductAttribute::query()
            ->whereIn('category_id', $categoryIds)
            ->where('product_attribute_id', $record->product_attribute_id)
            ->orderBy('sort_order')
            ->first();
    }

    private function attributeDisplayLabel(ProductAttribute $attribute): string
    {
        return $attribute->name_bg ?: $attribute->name ?: $attribute->code;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('custom_value')
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('attribute.name')
                    ->label('Характеристика')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category_scope')
                    ->label('Обхват')
                    ->state(fn (ProductAttributeValue $record): string => $this->categoryScopeLabel($record))
                    ->badge()
                    ->color(fn (ProductAttributeValue $record): string => $this->categoryScopeColor($record)),
                IconColumn::make('category_required')
                    ->label('Важна')
                    ->state(fn (ProductAttributeValue $record): bool => $this->categoryRequiredState($record))
                    ->boolean()
                    ->tooltip('Категорийният required флаг е само визуален ориентир и не блокира запис.'),
                TextColumn::make('display_value')
                    ->label('Стойност')
                    ->state(fn (ProductAttributeValue $record): string => $this->displayValue($record))
                    ->wrap()
                    ->searchable(['custom_value', 'value_text']),
                TextColumn::make('unit')
                    ->label('Мерна единица')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('source')
                    ->label('Източник')
                    ->formatStateUsing(fn (?string $state): string => $this->sourceLabel($state))
                    ->badge(),
                IconColumn::make('is_verified')
                    ->label('Проверена')
                    ->boolean(),
                TextColumn::make('sort_order')
                    ->label('Подредба')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Обновена на')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->headerActions([
                Action::make('saveCategorySpecifications')
                    ->label('Попълни категорийни характеристики')
                    ->modalHeading('Категорийни характеристики')
                    ->modalDescription('Показани са характеристиките, прикачени към категорията на продукта. Празните полета не създават редове. Изчистването премахва само стойността за този продукт.')
                    ->modalSubmitActionLabel('Запази характеристиките')
                    ->icon(Heroicon::OutlinedSquaresPlus)
                    ->color('primary')
                    ->visible(fn (): bool => $this->categorySpecificationRowsForProduct($this->getOwnerProduct()) !== [])
                    ->form(fn (): array => $this->categorySpecificationFormSchema())
                    ->fillForm(fn (): array => [
                        'specifications' => $this->categorySpecificationFormData(),
                    ])
                    ->action(function (array $data): void {
                        $this->saveCategorySpecifications($data);
                    }),
                CreateAction::make()
                    ->label('Добави характеристика')
                    ->modalHeading('Добавяне на продуктова характеристика')
                    ->modalSubmitActionLabel('Запази характеристика')
                    ->createAnother(false)
                    ->icon(Heroicon::Plus)
                    ->using(function (array $data): ProductAttributeValue {
                        return $this->getOwnerProduct()
                            ->attributeValues()
                            ->create($this->normalizeFormData($data));
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('Редакция')
                    ->modalHeading('Редакция на продуктова характеристика')
                    ->modalSubmitActionLabel('Запази характеристика')
                    ->mutateRecordDataUsing(fn (array $data, ProductAttributeValue $record): array => $this->formDataFromRecord($record, $data))
                    ->using(function (ProductAttributeValue $record, array $data): ProductAttributeValue {
                        $record->update($this->normalizeFormData($data, $record));

                        return $record;
                    }),
                DeleteAction::make()
                    ->label('Премахни')
                    ->modalHeading('Премахване на характеристика')
                    ->modalDescription('Това премахва само стойността за този продукт. Характеристиката, опциите, продуктът и категориите няма да бъдат изтрити.')
                    ->modalSubmitActionLabel('Премахни стойността'),
            ])
            ->emptyStateHeading('Няма записани характеристики')
            ->emptyStateDescription('Ако продуктът има категория с прикачени характеристики, използвайте бутона за категорийни характеристики. Празните полета не създават редове.');
    }

    /**
     * @return array<int, string>
     */
    public function attributeOptionsForProduct(Product $product): array
    {
        $suggestedIds = $this->categorySuggestedAttributeIds($product);

        $suggested = ProductAttribute::query()
            ->whereIn('id', $suggestedIds)
            ->where('is_active', true)
            ->get()
            ->sortBy(fn (ProductAttribute $attribute): int => array_search($attribute->id, $suggestedIds, true))
            ->mapWithKeys(fn (ProductAttribute $attribute): array => [
                $attribute->id => $this->attributeOptionLabel($attribute, true),
            ])
            ->all();

        $fallback = ProductAttribute::query()
            ->where('is_active', true)
            ->whereNotIn('id', $suggestedIds)
            ->orderBy('sort_order')
            ->orderBy('name_bg')
            ->get()
            ->mapWithKeys(fn (ProductAttribute $attribute): array => [
                $attribute->id => $this->attributeOptionLabel($attribute, false),
            ])
            ->all();

        return $suggested + $fallback;
    }

    /**
     * @return array<int, string>
     */
    public function optionValuesForAttribute(mixed $attributeId): array
    {
        if (! $attributeId) {
            return [];
        }

        return AttributeValue::query()
            ->where('product_attribute_id', $attributeId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('value')
            ->pluck('value', 'id')
            ->all();
    }

    public function displayValue(ProductAttributeValue $record): string
    {
        if ($record->value) {
            return $record->value->value;
        }

        if (filled($record->value_text)) {
            return (string) $record->value_text;
        }

        if ($record->value_number !== null) {
            return rtrim(rtrim((string) $record->value_number, '0'), '.');
        }

        if ($record->value_boolean !== null) {
            return $record->value_boolean ? 'Да' : 'Не';
        }

        $selectedIds = $this->selectedOptionIdsFromJson($record->value_json);
        if ($selectedIds !== []) {
            $values = AttributeValue::query()
                ->whereIn('id', $selectedIds)
                ->orderBy('sort_order')
                ->pluck('value')
                ->all();

            return implode(', ', $values);
        }

        if ($record->value_json !== null) {
            return json_encode($record->value_json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '-';
        }

        return filled($record->custom_value) ? (string) $record->custom_value : '-';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeFormData(array $data, ?ProductAttributeValue $record = null): array
    {
        $attribute = ProductAttribute::query()->find($data['product_attribute_id'] ?? null);

        if (! $attribute) {
            throw ValidationException::withMessages([
                'product_attribute_id' => 'Изберете характеристика.',
            ]);
        }

        $this->validateDuplicateAttribute($attribute, $record);

        $base = [
            'product_attribute_id' => $attribute->id,
            'canonical_attribute_id' => null,
            'canonical_attribute_value_id' => null,
            'attribute_value_id' => null,
            'custom_value' => null,
            'value_text' => null,
            'value_number' => null,
            'value_boolean' => null,
            'value_json' => null,
            'unit' => filled($data['unit'] ?? null) ? (string) $data['unit'] : $attribute->unit,
            'source' => ProductAttributeValue::SOURCE_MANUAL,
            'is_verified' => (bool) ($data['is_verified'] ?? false),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_filterable' => (bool) ($data['is_filterable'] ?? $attribute->is_filterable),
        ];

        return match ($attribute->type) {
            ProductAttribute::TYPE_TEXT => [
                ...$base,
                'value_text' => $this->requiredString($data['value_text'] ?? null, 'value_text', 'Въведете текстова стойност.'),
                'custom_value' => $this->requiredString($data['value_text'] ?? null, 'value_text', 'Въведете текстова стойност.'),
            ],
            ProductAttribute::TYPE_NUMBER, ProductAttribute::TYPE_DECIMAL => $this->normalizeNumericValue($base, $data),
            ProductAttribute::TYPE_BOOLEAN => [
                ...$base,
                'value_boolean' => filter_var($data['value_boolean'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'custom_value' => filter_var($data['value_boolean'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false',
            ],
            ProductAttribute::TYPE_SELECT => $this->normalizeSelectValue($base, $attribute, $data),
            ProductAttribute::TYPE_MULTISELECT => $this->normalizeMultiselectValue($base, $attribute, $data),
            ProductAttribute::TYPE_JSON => $this->normalizeJsonValue($base, $data),
            default => throw ValidationException::withMessages([
                'product_attribute_id' => 'Неподдържан тип характеристика.',
            ]),
        };
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeNumericValue(array $base, array $data): array
    {
        $validator = Validator::make($data, [
            'value_number' => ['required', 'numeric'],
        ], [
            'value_number.required' => 'Въведете числова стойност.',
            'value_number.numeric' => 'Стойността трябва да бъде число.',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $value = (string) $data['value_number'];

        return [
            ...$base,
            'value_number' => $value,
            'custom_value' => $value,
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeSelectValue(array $base, ProductAttribute $attribute, array $data): array
    {
        $option = $this->requireOptionForAttribute($attribute, $data['attribute_value_id'] ?? null, 'attribute_value_id');

        return [
            ...$base,
            'attribute_value_id' => $option->id,
            'custom_value' => $option->value,
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeMultiselectValue(array $base, ProductAttribute $attribute, array $data): array
    {
        $selectedIds = array_values(array_unique(array_filter(Arr::wrap($data['selected_attribute_value_ids'] ?? []))));

        if ($selectedIds === []) {
            throw ValidationException::withMessages([
                'selected_attribute_value_ids' => 'Изберете поне една контролирана стойност.',
            ]);
        }

        $options = AttributeValue::query()
            ->where('product_attribute_id', $attribute->id)
            ->whereIn('id', $selectedIds)
            ->get();

        if ($options->count() !== count($selectedIds)) {
            throw ValidationException::withMessages([
                'selected_attribute_value_ids' => 'Всички стойности трябва да принадлежат към избраната характеристика.',
            ]);
        }

        return [
            ...$base,
            'value_json' => ['attribute_value_ids' => $options->pluck('id')->values()->all()],
            'custom_value' => $options->pluck('value')->implode(', '),
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeJsonValue(array $base, array $data): array
    {
        $raw = $this->requiredString($data['value_json_text'] ?? null, 'value_json_text', 'Въведете JSON стойност.');

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'value_json_text' => 'Въведете валиден JSON.',
            ]);
        }

        return [
            ...$base,
            'value_json' => $decoded,
            'custom_value' => json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    private function requiredString(mixed $value, string $field, string $message): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw ValidationException::withMessages([
                $field => $message,
            ]);
        }

        return trim($value);
    }

    private function requireOptionForAttribute(ProductAttribute $attribute, mixed $optionId, string $field): AttributeValue
    {
        $option = AttributeValue::query()
            ->where('product_attribute_id', $attribute->id)
            ->whereKey($optionId)
            ->first();

        if (! $option) {
            throw ValidationException::withMessages([
                $field => 'Избраната стойност трябва да принадлежи към характеристиката.',
            ]);
        }

        return $option;
    }

    private function validateDuplicateAttribute(ProductAttribute $attribute, ?ProductAttributeValue $record): void
    {
        $exists = $this->getOwnerProduct()
            ->attributeValues()
            ->where('product_attribute_id', $attribute->id)
            ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'product_attribute_id' => 'Този продукт вече има стойност за избраната характеристика.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function formDataFromRecord(ProductAttributeValue $record, array $data): array
    {
        return [
            ...$data,
            'source' => ProductAttributeValue::SOURCE_MANUAL,
            'selected_attribute_value_ids' => $this->selectedOptionIdsFromJson($record->value_json),
            'value_json_text' => $record->value_json === null
                ? null
                : json_encode($record->value_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @return array<int, int>
     */
    private function selectedOptionIdsFromJson(?array $json): array
    {
        return collect($json['attribute_value_ids'] ?? [])
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    private function getOwnerProduct(): Product
    {
        /** @var Product $product */
        $product = $this->getOwnerRecord();

        return $product;
    }

    /**
     * @return array<int, int>
     */
    private function categorySuggestedAttributeIds(Product $product): array
    {
        $categoryIds = $this->categoryIdsForProduct($product);

        if ($categoryIds === []) {
            return [];
        }

        return CategoryProductAttribute::query()
            ->whereIn('category_id', $categoryIds)
            ->whereHas('attribute', fn ($query) => $query->where('is_active', true))
            ->orderBy('sort_order')
            ->pluck('product_attribute_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function attributeType(mixed $attributeId): ?string
    {
        if (! $attributeId) {
            return null;
        }

        return ProductAttribute::query()->whereKey($attributeId)->value('type');
    }

    private function attributeTypeLabel(?string $type): string
    {
        return match ($type) {
            ProductAttribute::TYPE_TEXT => 'Текст',
            ProductAttribute::TYPE_NUMBER => 'Число',
            ProductAttribute::TYPE_DECIMAL => 'Десетично число',
            ProductAttribute::TYPE_BOOLEAN => 'Да / Не',
            ProductAttribute::TYPE_SELECT => 'Единичен избор',
            ProductAttribute::TYPE_MULTISELECT => 'Множествен избор',
            ProductAttribute::TYPE_JSON => 'JSON',
            default => '-',
        };
    }

    private function attributeOptionLabel(ProductAttribute $attribute, bool $suggested): string
    {
        $label = $attribute->name_bg ?: $attribute->name ?: $attribute->code;
        $type = $this->attributeTypeLabel($attribute->type);
        $prefix = $suggested ? 'Категория: ' : 'Всички: ';

        return "{$prefix}{$label} ({$type})";
    }

    private function sourceLabel(?string $state): string
    {
        return match ($state) {
            ProductAttributeValue::SOURCE_MANUAL => 'Ръчно',
            ProductAttributeValue::SOURCE_IMPORT_PREVIEW => 'Преглед',
            ProductAttributeValue::SOURCE_CONTROLLED_SYNC => 'Контролиран sync',
            default => $state ?: '-',
        };
    }
}

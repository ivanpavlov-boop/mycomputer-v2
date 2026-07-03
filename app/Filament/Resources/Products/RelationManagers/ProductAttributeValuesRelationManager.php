<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Models\AttributeValue;
use App\Models\CategoryProductAttribute;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
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
            ]);
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
        if (! $product->category_id) {
            return [];
        }

        return CategoryProductAttribute::query()
            ->where('category_id', $product->category_id)
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

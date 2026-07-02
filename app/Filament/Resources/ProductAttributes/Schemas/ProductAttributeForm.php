<?php

namespace App\Filament\Resources\ProductAttributes\Schemas;

use App\Models\ProductAttribute;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ProductAttributeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Основни данни')
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('attribute_group_id')
                                ->label('Група')
                                ->relationship('group', 'name')
                                ->searchable()
                                ->preload()
                                ->required(),
                            Select::make('type')
                                ->label('Тип')
                                ->options(self::typeOptions())
                                ->default(ProductAttribute::TYPE_SELECT)
                                ->required()
                                ->in(ProductAttribute::TYPES),
                            TextInput::make('code')
                                ->label('Код')
                                ->helperText('Стабилен вътрешен ключ за бъдещи филтри и спецификации. Използвайте малки букви, цифри и долна черта, например ram или ssd_capacity. Не го променяйте след употреба.')
                                ->required()
                                ->maxLength(120)
                                ->regex('/^[a-z0-9_]+$/')
                                ->dehydrateStateUsing(fn (?string $state): string => Str::slug((string) $state, '_'))
                                ->unique(ignoreRecord: true),
                            TextInput::make('name_bg')
                                ->label('Име на български')
                                ->required()
                                ->maxLength(255),
                            TextInput::make('name_en')
                                ->label('Име на английски')
                                ->maxLength(255),
                            TextInput::make('unit')
                                ->label('Мерна единица')
                                ->maxLength(50),
                            TextInput::make('sort_order')
                                ->label('Ред на сортиране')
                                ->numeric()
                                ->default(0),
                        ]),
                        Grid::make(2)->schema([
                            Textarea::make('description_bg')
                                ->label('Описание на български')
                                ->rows(3),
                            Textarea::make('description_en')
                                ->label('Описание на английски')
                                ->rows(3),
                        ]),
                        Grid::make(3)->schema([
                            Toggle::make('is_filterable')
                                ->label('Филтър')
                                ->helperText('Може да се използва по-късно във филтри в каталога.')
                                ->default(false),
                            Toggle::make('is_visible_on_product')
                                ->label('Видима в продукта')
                                ->helperText('Може да се показва по-късно в страницата на продукта.')
                                ->default(true),
                            Toggle::make('is_comparable')
                                ->label('Сравнима')
                                ->helperText('Може да се използва по-късно в сравнение на продукти.')
                                ->default(false),
                            Toggle::make('is_required_by_default')
                                ->label('Задължителна по подразбиране')
                                ->helperText('Може да бъде задължителна за избрани категории.')
                                ->default(false),
                            Toggle::make('is_required')
                                ->label('Задължителна')
                                ->default(false),
                            Toggle::make('is_active')
                                ->label('Активна')
                                ->default(true),
                        ]),
                    ]),
            ]);
    }

    /**
     * @return array<string, string>
     */
    private static function typeOptions(): array
    {
        return [
            ProductAttribute::TYPE_TEXT => 'Текст',
            ProductAttribute::TYPE_NUMBER => 'Число',
            ProductAttribute::TYPE_BOOLEAN => 'Да/Не',
            ProductAttribute::TYPE_SELECT => 'Избор',
            ProductAttribute::TYPE_MULTISELECT => 'Множествен избор',
            ProductAttribute::TYPE_DECIMAL => 'Десетично число',
            ProductAttribute::TYPE_JSON => 'JSON',
        ];
    }
}

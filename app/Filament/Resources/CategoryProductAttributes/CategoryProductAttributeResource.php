<?php

namespace App\Filament\Resources\CategoryProductAttributes;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\CategoryProductAttributes\Pages\CreateCategoryProductAttribute;
use App\Filament\Resources\CategoryProductAttributes\Pages\EditCategoryProductAttribute;
use App\Filament\Resources\CategoryProductAttributes\Pages\ListCategoryProductAttributes;
use App\Models\CategoryProductAttribute;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

class CategoryProductAttributeResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = CategoryProductAttribute::class;

    protected static ?string $permission = 'manage products';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static ?string $navigationLabel = 'Категорийни характеристики';

    protected static string|UnitEnum|null $navigationGroup = 'Каталог характеристики';

    public static function getModelLabel(): string
    {
        return 'категорийна характеристика';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Категорийни характеристики';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Категорийно правило')
                ->schema([
                    Grid::make(2)->schema([
                        Select::make('category_id')
                            ->label('Категория')
                            ->relationship('category', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('product_attribute_id')
                            ->label('Характеристика')
                            ->relationship('attribute', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        TextInput::make('sort_order')
                            ->label('Ред на сортиране')
                            ->numeric()
                            ->default(0),
                    ]),
                    Grid::make(4)->schema([
                        Toggle::make('is_required')
                            ->label('Задължителна')
                            ->default(false),
                        Toggle::make('is_filterable')
                            ->label('Филтър')
                            ->default(false),
                        Toggle::make('is_visible_on_product')
                            ->label('Видима в продукта')
                            ->default(true),
                        Toggle::make('is_comparable')
                            ->label('За сравнение')
                            ->default(false),
                    ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('category.name')->label('Категория')->searchable()->sortable(),
                TextColumn::make('attribute.code')->label('Код')->searchable()->sortable(),
                TextColumn::make('attribute.name')->label('Характеристика')->searchable()->sortable(),
                IconColumn::make('is_required')->label('Задълж.')->boolean(),
                IconColumn::make('is_filterable')->label('Филтър')->boolean(),
                IconColumn::make('is_visible_on_product')->label('В продукт')->boolean(),
                IconColumn::make('is_comparable')->label('Сравнение')->boolean(),
                TextColumn::make('sort_order')->label('Ред')->sortable(),
            ])
            ->filters([
                SelectFilter::make('category')->label('Категория')->relationship('category', 'name')->searchable()->preload(),
                SelectFilter::make('attribute')->label('Характеристика')->relationship('attribute', 'name')->searchable()->preload(),
                TernaryFilter::make('is_required')->label('Задължителна'),
                TernaryFilter::make('is_filterable')->label('Филтър'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCategoryProductAttributes::route('/'),
            'create' => CreateCategoryProductAttribute::route('/create'),
            'edit' => EditCategoryProductAttribute::route('/{record}/edit'),
        ];
    }
}

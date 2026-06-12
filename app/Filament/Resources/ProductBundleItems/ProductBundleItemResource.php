<?php

namespace App\Filament\Resources\ProductBundleItems;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\ProductBundleItems\Pages\CreateProductBundleItem;
use App\Filament\Resources\ProductBundleItems\Pages\EditProductBundleItem;
use App\Filament\Resources\ProductBundleItems\Pages\ListProductBundleItems;
use App\Models\ProductBundleItem;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class ProductBundleItemResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = ProductBundleItem::class;

    protected static ?string $permission = 'manage products';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static ?string $navigationLabel = 'Bundle Items';

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('product_bundle_id')->relationship('bundle', 'name')->searchable()->preload()->required(),
            Select::make('product_id')->relationship('product', 'name')->searchable()->preload(),
            TextInput::make('component_group')->maxLength(255),
            TextInput::make('quantity')->numeric()->minValue(1)->default(1)->required(),
            TextInput::make('min_quantity')->numeric()->minValue(1),
            TextInput::make('max_quantity')->numeric()->minValue(1),
            Select::make('is_required')->options([1 => 'Required', 0 => 'Optional'])->default(1)->required(),
            TextInput::make('sort_order')->numeric()->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('bundle.name')->searchable()->sortable(),
                TextColumn::make('product.name')->searchable(),
                TextColumn::make('component_group')->searchable()->sortable(),
                TextColumn::make('quantity')->numeric()->sortable(),
                IconColumn::make('is_required')->boolean(),
                TextColumn::make('sort_order')->numeric()->sortable(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductBundleItems::route('/'),
            'create' => CreateProductBundleItem::route('/create'),
            'edit' => EditProductBundleItem::route('/{record}/edit'),
        ];
    }
}

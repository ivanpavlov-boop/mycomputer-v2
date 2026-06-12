<?php

namespace App\Filament\Resources\ProductBundleOptions;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\ProductBundleOptions\Pages\CreateProductBundleOption;
use App\Filament\Resources\ProductBundleOptions\Pages\EditProductBundleOption;
use App\Filament\Resources\ProductBundleOptions\Pages\ListProductBundleOptions;
use App\Models\ProductBundleOption;
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

class ProductBundleOptionResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = ProductBundleOption::class;

    protected static ?string $permission = 'manage products';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $navigationLabel = 'Bundle Options';

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('product_bundle_id')->relationship('bundle', 'name')->searchable()->preload()->required(),
            TextInput::make('component_group')->required()->maxLength(255),
            Select::make('product_id')->relationship('product', 'name')->searchable()->preload()->required(),
            TextInput::make('price_adjustment')->numeric()->prefix('BGN')->default(0),
            Select::make('is_default')->options([1 => 'Default', 0 => 'Alternative'])->default(0)->required(),
            TextInput::make('sort_order')->numeric()->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('bundle.name')->searchable()->sortable(),
                TextColumn::make('component_group')->searchable()->sortable(),
                TextColumn::make('product.name')->searchable(),
                TextColumn::make('price_adjustment')->money('BGN')->sortable(),
                IconColumn::make('is_default')->boolean(),
                TextColumn::make('sort_order')->numeric()->sortable(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductBundleOptions::route('/'),
            'create' => CreateProductBundleOption::route('/create'),
            'edit' => EditProductBundleOption::route('/{record}/edit'),
        ];
    }
}

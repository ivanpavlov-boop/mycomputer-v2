<?php

namespace App\Filament\Resources\ErpProductMappings;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\ErpProductMappings\Pages\CreateErpProductMapping;
use App\Filament\Resources\ErpProductMappings\Pages\EditErpProductMapping;
use App\Filament\Resources\ErpProductMappings\Pages\ListErpProductMappings;
use App\Models\ErpProductMapping;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class ErpProductMappingResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = ErpProductMapping::class;

    protected static ?string $permission = 'manage erp';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?string $navigationLabel = 'ERP Product Mappings';

    protected static string|UnitEnum|null $navigationGroup = 'ERP';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('provider_id')->relationship('provider', 'name')->searchable()->preload(),
            Select::make('product_id')->relationship('product', 'name')->searchable()->preload()->required(),
            TextInput::make('external_product_id'),
            TextInput::make('external_sku'),
            TextInput::make('external_barcode'),
            Toggle::make('sync_enabled')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('provider.name')->placeholder('None')->sortable(),
                TextColumn::make('product.sku')->searchable(),
                TextColumn::make('product.name')->searchable(),
                TextColumn::make('external_product_id')->searchable(),
                TextColumn::make('external_sku')->searchable(),
                IconColumn::make('sync_enabled')->boolean(),
                TextColumn::make('last_synced_at')->dateTime()->sortable(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListErpProductMappings::route('/'),
            'create' => CreateErpProductMapping::route('/create'),
            'edit' => EditErpProductMapping::route('/{record}/edit'),
        ];
    }
}

<?php

namespace App\Filament\Resources\ProductSyncLogs;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\ProductSyncLogs\Pages\CreateProductSyncLog;
use App\Filament\Resources\ProductSyncLogs\Pages\EditProductSyncLog;
use App\Filament\Resources\ProductSyncLogs\Pages\ListProductSyncLogs;
use App\Filament\Resources\ProductSyncLogs\Schemas\ProductSyncLogForm;
use App\Filament\Resources\ProductSyncLogs\Tables\ProductSyncLogsTable;
use App\Models\ProductSyncLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ProductSyncLogResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = ProductSyncLog::class;

    protected static ?string $permission = 'manage products';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static ?string $navigationLabel = 'Product Sync Logs';

    protected static string|UnitEnum|null $navigationGroup = 'Supplier Imports';

    public static function form(Schema $schema): Schema
    {
        return ProductSyncLogForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductSyncLogsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductSyncLogs::route('/'),
            'create' => CreateProductSyncLog::route('/create'),
            'edit' => EditProductSyncLog::route('/{record}/edit'),
        ];
    }
}

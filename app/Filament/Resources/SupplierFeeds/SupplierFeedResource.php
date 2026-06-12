<?php

namespace App\Filament\Resources\SupplierFeeds;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\SupplierFeeds\Pages\CreateSupplierFeed;
use App\Filament\Resources\SupplierFeeds\Pages\EditSupplierFeed;
use App\Filament\Resources\SupplierFeeds\Pages\ListSupplierFeeds;
use App\Filament\Resources\SupplierFeeds\Schemas\SupplierFeedForm;
use App\Filament\Resources\SupplierFeeds\Tables\SupplierFeedsTable;
use App\Models\SupplierFeed;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class SupplierFeedResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = SupplierFeed::class;

    protected static ?string $permission = 'manage feeds';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static ?string $navigationLabel = 'Supplier Feeds';

    protected static string|UnitEnum|null $navigationGroup = 'Suppliers';

    public static function form(Schema $schema): Schema
    {
        return SupplierFeedForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SupplierFeedsTable::configure($table);
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
            'index' => ListSupplierFeeds::route('/'),
            'create' => CreateSupplierFeed::route('/create'),
            'edit' => EditSupplierFeed::route('/{record}/edit'),
        ];
    }
}

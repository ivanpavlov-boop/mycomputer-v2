<?php

namespace App\Filament\Resources\FailedImports;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\FailedImports\Pages\CreateFailedImport;
use App\Filament\Resources\FailedImports\Pages\EditFailedImport;
use App\Filament\Resources\FailedImports\Pages\ListFailedImports;
use App\Filament\Resources\FailedImports\Schemas\FailedImportForm;
use App\Filament\Resources\FailedImports\Tables\FailedImportsTable;
use App\Models\FailedImport;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class FailedImportResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = FailedImport::class;

    protected static ?string $permission = 'manage imports';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $navigationLabel = 'Failed Imports';

    protected static string|UnitEnum|null $navigationGroup = 'Supplier Imports';

    public static function form(Schema $schema): Schema
    {
        return FailedImportForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FailedImportsTable::configure($table);
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
            'index' => ListFailedImports::route('/'),
            'create' => CreateFailedImport::route('/create'),
            'edit' => EditFailedImport::route('/{record}/edit'),
        ];
    }
}

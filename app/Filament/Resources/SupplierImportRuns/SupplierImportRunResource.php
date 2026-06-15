<?php

namespace App\Filament\Resources\SupplierImportRuns;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\SupplierImportRuns\Pages\EditSupplierImportRun;
use App\Filament\Resources\SupplierImportRuns\Pages\ListSupplierImportRuns;
use App\Filament\Resources\SupplierImportRuns\Schemas\SupplierImportRunForm;
use App\Filament\Resources\SupplierImportRuns\Tables\SupplierImportRunsTable;
use App\Models\SupplierImportRun;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class SupplierImportRunResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = SupplierImportRun::class;

    protected static ?string $permission = 'view supplier import logs';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Supplier Import Runs';

    protected static string|UnitEnum|null $navigationGroup = 'Supplier Imports';

    public static function form(Schema $schema): Schema
    {
        return SupplierImportRunForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SupplierImportRunsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupplierImportRuns::route('/'),
            'edit' => EditSupplierImportRun::route('/{record}/edit'),
        ];
    }
}

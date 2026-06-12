<?php

namespace App\Filament\Resources\ImportHistories;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\ImportHistories\Pages\CreateImportHistory;
use App\Filament\Resources\ImportHistories\Pages\EditImportHistory;
use App\Filament\Resources\ImportHistories\Pages\ListImportHistories;
use App\Filament\Resources\ImportHistories\Schemas\ImportHistoryForm;
use App\Filament\Resources\ImportHistories\Tables\ImportHistoriesTable;
use App\Models\ImportHistory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ImportHistoryResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = ImportHistory::class;

    protected static ?string $permission = 'manage imports';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Import History';

    protected static string|UnitEnum|null $navigationGroup = 'Supplier Imports';

    public static function form(Schema $schema): Schema
    {
        return ImportHistoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ImportHistoriesTable::configure($table);
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
            'index' => ListImportHistories::route('/'),
            'create' => CreateImportHistory::route('/create'),
            'edit' => EditImportHistory::route('/{record}/edit'),
        ];
    }
}

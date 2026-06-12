<?php

namespace App\Filament\Resources\CsvImportJobs;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\CsvImportJobs\Pages\CreateCsvImportJob;
use App\Filament\Resources\CsvImportJobs\Pages\EditCsvImportJob;
use App\Filament\Resources\CsvImportJobs\Pages\ListCsvImportJobs;
use App\Filament\Resources\CsvImportJobs\Schemas\CsvImportJobForm;
use App\Filament\Resources\CsvImportJobs\Tables\CsvImportJobsTable;
use App\Models\CsvImportJob;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CsvImportJobResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = CsvImportJob::class;

    protected static ?string $permission = 'manage imports';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $navigationLabel = 'CSV Import Jobs';

    protected static string|UnitEnum|null $navigationGroup = 'CSV Center';

    public static function form(Schema $schema): Schema
    {
        return CsvImportJobForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CsvImportJobsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCsvImportJobs::route('/'),
            'create' => CreateCsvImportJob::route('/create'),
            'edit' => EditCsvImportJob::route('/{record}/edit'),
        ];
    }
}

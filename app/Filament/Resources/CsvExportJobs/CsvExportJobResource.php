<?php

namespace App\Filament\Resources\CsvExportJobs;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\CsvExportJobs\Pages\CreateCsvExportJob;
use App\Filament\Resources\CsvExportJobs\Pages\EditCsvExportJob;
use App\Filament\Resources\CsvExportJobs\Pages\ListCsvExportJobs;
use App\Filament\Resources\CsvExportJobs\Schemas\CsvExportJobForm;
use App\Filament\Resources\CsvExportJobs\Tables\CsvExportJobsTable;
use App\Models\CsvExportJob;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CsvExportJobResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = CsvExportJob::class;

    protected static ?string $permission = 'manage imports';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $navigationLabel = 'CSV Export Jobs';

    protected static string|UnitEnum|null $navigationGroup = 'CSV Center';

    public static function form(Schema $schema): Schema
    {
        return CsvExportJobForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CsvExportJobsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCsvExportJobs::route('/'),
            'create' => CreateCsvExportJob::route('/create'),
            'edit' => EditCsvExportJob::route('/{record}/edit'),
        ];
    }
}

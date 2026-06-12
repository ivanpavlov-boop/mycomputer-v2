<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Filament\Resources\Categories\CategoryResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCategories extends ListRecords
{
    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('csvImport')
                ->label('CSV Import')
                ->icon('heroicon-o-arrow-up-tray')
                ->url('/admin/csv-import-jobs/create'),
            Action::make('csvExport')
                ->label('CSV Export')
                ->icon('heroicon-o-arrow-down-tray')
                ->url('/admin/csv-export-jobs/create'),
            CreateAction::make(),
        ];
    }
}

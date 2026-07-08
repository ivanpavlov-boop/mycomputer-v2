<?php

namespace App\Filament\Resources\SupplierCategoryMappings\Pages;

use App\Filament\Resources\SupplierCategoryMappings\SupplierCategoryMappingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSupplierCategoryMappings extends ListRecords
{
    protected static string $resource = SupplierCategoryMappingResource::class;

    public function sortTable(?string $column = null, ?string $direction = null): void
    {
        if ($column === 'status' && $direction === null) {
            $direction = match ($this->getTableSortColumn() === 'status' ? $this->getTableSortDirection() : null) {
                'desc' => 'asc',
                'asc' => null,
                default => 'desc',
            };
        }

        parent::sortTable($column, $direction);
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Създай картографиране'),
        ];
    }
}

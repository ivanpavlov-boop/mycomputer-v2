<?php

namespace App\Filament\Resources\ReusableContentBlocks\Pages;

use App\Filament\Resources\ReusableContentBlocks\ReusableContentBlockResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListReusableContentBlocks extends ListRecords
{
    protected static string $resource = ReusableContentBlockResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

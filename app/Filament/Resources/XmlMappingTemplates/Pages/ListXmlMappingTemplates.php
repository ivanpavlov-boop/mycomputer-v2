<?php

namespace App\Filament\Resources\XmlMappingTemplates\Pages;

use App\Filament\Resources\XmlMappingTemplates\XmlMappingTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListXmlMappingTemplates extends ListRecords
{
    protected static string $resource = XmlMappingTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

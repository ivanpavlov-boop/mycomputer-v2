<?php

namespace App\Filament\Resources\XmlMappingTemplates\Pages;

use App\Filament\Resources\XmlMappingTemplates\XmlMappingTemplateResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditXmlMappingTemplate extends EditRecord
{
    protected static string $resource = XmlMappingTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

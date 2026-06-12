<?php

namespace App\Filament\Resources\XmlMappingTemplates;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\XmlMappingTemplates\Pages\CreateXmlMappingTemplate;
use App\Filament\Resources\XmlMappingTemplates\Pages\EditXmlMappingTemplate;
use App\Filament\Resources\XmlMappingTemplates\Pages\ListXmlMappingTemplates;
use App\Filament\Resources\XmlMappingTemplates\Schemas\XmlMappingTemplateForm;
use App\Filament\Resources\XmlMappingTemplates\Tables\XmlMappingTemplatesTable;
use App\Models\XmlMappingTemplate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class XmlMappingTemplateResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = XmlMappingTemplate::class;

    protected static ?string $permission = 'manage imports';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCodeBracketSquare;

    protected static ?string $navigationLabel = 'XML Mapping Templates';

    protected static string|UnitEnum|null $navigationGroup = 'Supplier Imports';

    public static function form(Schema $schema): Schema
    {
        return XmlMappingTemplateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return XmlMappingTemplatesTable::configure($table);
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
            'index' => ListXmlMappingTemplates::route('/'),
            'create' => CreateXmlMappingTemplate::route('/create'),
            'edit' => EditXmlMappingTemplate::route('/{record}/edit'),
        ];
    }
}

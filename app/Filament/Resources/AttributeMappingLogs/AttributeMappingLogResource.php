<?php

namespace App\Filament\Resources\AttributeMappingLogs;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\AttributeMappingLogs\Pages\ListAttributeMappingLogs;
use App\Models\AttributeMappingLog;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class AttributeMappingLogResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = AttributeMappingLog::class;

    protected static ?string $permission = 'manage attribute mappings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Mapping Logs';

    protected static string|UnitEnum|null $navigationGroup = 'Attribute Normalization';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Mapping event')
                ->schema([
                    TextInput::make('raw_name')->disabled(),
                    TextInput::make('raw_value')->disabled(),
                    Textarea::make('message')->disabled(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('action')->badge()->sortable(),
                TextColumn::make('raw_name')->searchable()->sortable(),
                TextColumn::make('raw_value')->searchable()->limit(40),
                TextColumn::make('mappedAttribute.code')->label('Attribute')->searchable(),
                TextColumn::make('mappedValue.display_value')->label('Value')->searchable(),
                TextColumn::make('confidence')->sortable(),
                TextColumn::make('supplier.company_name')->label('Supplier')->toggleable(),
                TextColumn::make('message')->limit(60)->toggleable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('action')->options(array_combine(AttributeMappingLog::ACTIONS, AttributeMappingLog::ACTIONS)),
                SelectFilter::make('source_type')->options([
                    'xml' => 'XML',
                    'csv' => 'CSV',
                    'erp' => 'ERP',
                    'api' => 'API',
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAttributeMappingLogs::route('/'),
        ];
    }
}

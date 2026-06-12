<?php

namespace App\Filament\Resources\AttributeAliases;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\AttributeAliases\Pages\CreateAttributeAlias;
use App\Filament\Resources\AttributeAliases\Pages\EditAttributeAlias;
use App\Filament\Resources\AttributeAliases\Pages\ListAttributeAliases;
use App\Models\AttributeAlias;
use App\Models\Supplier;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class AttributeAliasResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = AttributeAlias::class;

    protected static ?string $permission = 'manage attribute mappings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLanguage;

    protected static ?string $navigationLabel = 'Attribute Aliases';

    protected static string|UnitEnum|null $navigationGroup = 'Attribute Normalization';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Alias mapping')
                ->schema([
                    Select::make('canonical_attribute_id')->relationship('canonicalAttribute', 'name')->searchable()->preload()->required(),
                    Select::make('supplier_id')->relationship('supplier', 'company_name')->searchable()->preload(),
                    Grid::make(3)->schema([
                        TextInput::make('alias')->required()->maxLength(255),
                        TextInput::make('normalized_alias')->required()->maxLength(255),
                        TextInput::make('locale')->maxLength(12),
                        Select::make('source_type')->options([
                            'xml' => 'XML',
                            'csv' => 'CSV',
                            'erp' => 'ERP',
                            'api' => 'API',
                            'manual' => 'Manual',
                        ]),
                        TextInput::make('confidence')->numeric()->default(100)->minValue(0)->maxValue(100),
                        Toggle::make('is_active')->default(true),
                    ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('alias')->searchable()->sortable(),
                TextColumn::make('normalized_alias')->searchable()->toggleable(),
                TextColumn::make('canonicalAttribute.code')->label('Canonical')->searchable()->sortable(),
                TextColumn::make('supplier.company_name')->label('Supplier')->searchable()->toggleable(),
                TextColumn::make('source_type')->badge()->toggleable(),
                TextColumn::make('confidence')->sortable(),
                IconColumn::make('is_active')->boolean()->sortable(),
            ])
            ->filters([
                SelectFilter::make('supplier_id')
                    ->label('Supplier')
                    ->options(fn (): array => Supplier::query()->orderBy('company_name')->pluck('company_name', 'id')->all()),
                SelectFilter::make('source_type')->options([
                    'xml' => 'XML',
                    'csv' => 'CSV',
                    'erp' => 'ERP',
                    'api' => 'API',
                    'manual' => 'Manual',
                ]),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAttributeAliases::route('/'),
            'create' => CreateAttributeAlias::route('/create'),
            'edit' => EditAttributeAlias::route('/{record}/edit'),
        ];
    }
}

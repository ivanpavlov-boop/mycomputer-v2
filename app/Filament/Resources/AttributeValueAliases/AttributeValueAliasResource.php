<?php

namespace App\Filament\Resources\AttributeValueAliases;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\AttributeValueAliases\Pages\CreateAttributeValueAlias;
use App\Filament\Resources\AttributeValueAliases\Pages\EditAttributeValueAlias;
use App\Filament\Resources\AttributeValueAliases\Pages\ListAttributeValueAliases;
use App\Models\AttributeValueAlias;
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

class AttributeValueAliasResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = AttributeValueAlias::class;

    protected static ?string $permission = 'manage attribute mappings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Value Aliases';

    protected static string|UnitEnum|null $navigationGroup = 'Attribute Normalization';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Value alias')
                ->schema([
                    Select::make('canonical_attribute_value_id')
                        ->relationship('canonicalAttributeValue', 'display_value')
                        ->searchable()
                        ->preload()
                        ->required(),
                    Select::make('supplier_id')->relationship('supplier', 'company_name')->searchable()->preload(),
                    Grid::make(3)->schema([
                        TextInput::make('alias')->required()->maxLength(255),
                        TextInput::make('normalized_alias')->required()->maxLength(255),
                        TextInput::make('locale')->maxLength(12),
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
                TextColumn::make('canonicalAttributeValue.canonicalAttribute.code')->label('Attribute')->searchable()->sortable(),
                TextColumn::make('canonicalAttributeValue.display_value')->label('Value')->searchable()->sortable(),
                TextColumn::make('supplier.company_name')->label('Supplier')->searchable()->toggleable(),
                TextColumn::make('confidence')->sortable(),
                IconColumn::make('is_active')->boolean()->sortable(),
            ])
            ->filters([
                SelectFilter::make('supplier_id')
                    ->label('Supplier')
                    ->options(fn (): array => Supplier::query()->orderBy('company_name')->pluck('company_name', 'id')->all()),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAttributeValueAliases::route('/'),
            'create' => CreateAttributeValueAlias::route('/create'),
            'edit' => EditAttributeValueAlias::route('/{record}/edit'),
        ];
    }
}

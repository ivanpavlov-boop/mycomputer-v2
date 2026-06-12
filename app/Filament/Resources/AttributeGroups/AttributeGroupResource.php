<?php

namespace App\Filament\Resources\AttributeGroups;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\AttributeGroups\Pages\CreateAttributeGroup;
use App\Filament\Resources\AttributeGroups\Pages\EditAttributeGroup;
use App\Filament\Resources\AttributeGroups\Pages\ListAttributeGroups;
use App\Filament\Resources\AttributeGroups\Schemas\AttributeGroupForm;
use App\Filament\Resources\AttributeGroups\Tables\AttributeGroupsTable;
use App\Models\AttributeGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class AttributeGroupResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = AttributeGroup::class;

    protected static ?string $permission = 'manage products';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $navigationLabel = 'Attribute Groups';

    protected static string|UnitEnum|null $navigationGroup = 'Catalog Attributes';

    public static function form(Schema $schema): Schema
    {
        return AttributeGroupForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AttributeGroupsTable::configure($table);
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
            'index' => ListAttributeGroups::route('/'),
            'create' => CreateAttributeGroup::route('/create'),
            'edit' => EditAttributeGroup::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}

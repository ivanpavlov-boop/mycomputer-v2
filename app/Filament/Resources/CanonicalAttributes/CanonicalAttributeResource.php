<?php

namespace App\Filament\Resources\CanonicalAttributes;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\CanonicalAttributes\Pages\CreateCanonicalAttribute;
use App\Filament\Resources\CanonicalAttributes\Pages\EditCanonicalAttribute;
use App\Filament\Resources\CanonicalAttributes\Pages\ListCanonicalAttributes;
use App\Models\CanonicalAttribute;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
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

class CanonicalAttributeResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = CanonicalAttribute::class;

    protected static ?string $permission = 'manage attribute mappings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $navigationLabel = 'Canonical Attributes';

    protected static string|UnitEnum|null $navigationGroup = 'Attribute Normalization';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Canonical definition')
                ->schema([
                    Grid::make(3)->schema([
                        TextInput::make('code')->required()->maxLength(120)->unique(ignoreRecord: true),
                        TextInput::make('name')->required()->maxLength(255),
                        TextInput::make('group_name')->maxLength(255),
                        Select::make('type')->options(array_combine(CanonicalAttribute::TYPES, CanonicalAttribute::TYPES))->required(),
                        TextInput::make('unit')->maxLength(50),
                        TextInput::make('sort_order')->numeric()->default(0),
                    ]),
                    TagsInput::make('category_scope')
                        ->separator(',')
                        ->helperText('Optional category slugs where this attribute is expected.'),
                    Grid::make(4)->schema([
                        Toggle::make('is_filterable')->default(true),
                        Toggle::make('is_comparable')->default(true),
                        Toggle::make('is_required'),
                        Toggle::make('is_active')->default(true),
                    ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('group_name')->searchable()->sortable(),
                TextColumn::make('type')->badge()->sortable(),
                TextColumn::make('unit')->toggleable(),
                IconColumn::make('is_filterable')->boolean()->sortable(),
                IconColumn::make('is_comparable')->boolean()->sortable(),
                IconColumn::make('is_active')->boolean()->sortable(),
                TextColumn::make('aliases_count')->counts('aliases')->label('Aliases')->sortable(),
                TextColumn::make('values_count')->counts('values')->label('Values')->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')->options(array_combine(CanonicalAttribute::TYPES, CanonicalAttribute::TYPES)),
                SelectFilter::make('is_active')->options([1 => 'Active', 0 => 'Inactive']),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCanonicalAttributes::route('/'),
            'create' => CreateCanonicalAttribute::route('/create'),
            'edit' => EditCanonicalAttribute::route('/{record}/edit'),
        ];
    }
}

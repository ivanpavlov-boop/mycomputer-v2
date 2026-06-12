<?php

namespace App\Filament\Resources\CanonicalAttributeValues;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\CanonicalAttributeValues\Pages\CreateCanonicalAttributeValue;
use App\Filament\Resources\CanonicalAttributeValues\Pages\EditCanonicalAttributeValue;
use App\Filament\Resources\CanonicalAttributeValues\Pages\ListCanonicalAttributeValues;
use App\Models\CanonicalAttribute;
use App\Models\CanonicalAttributeValue;
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

class CanonicalAttributeValueResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = CanonicalAttributeValue::class;

    protected static ?string $permission = 'manage attribute mappings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $navigationLabel = 'Canonical Values';

    protected static string|UnitEnum|null $navigationGroup = 'Attribute Normalization';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Canonical value')
                ->schema([
                    Select::make('canonical_attribute_id')
                        ->relationship('canonicalAttribute', 'name')
                        ->searchable()
                        ->preload()
                        ->required(),
                    Grid::make(3)->schema([
                        TextInput::make('normalized_value')->required()->maxLength(255),
                        TextInput::make('display_value')->required()->maxLength(255),
                        TextInput::make('numeric_value')->numeric(),
                        TextInput::make('unit')->maxLength(50),
                        TextInput::make('sort_order')->numeric()->default(0),
                        Toggle::make('is_active')->default(true),
                    ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('canonicalAttribute.code')->label('Attribute')->searchable()->sortable(),
                TextColumn::make('display_value')->searchable()->sortable(),
                TextColumn::make('normalized_value')->searchable()->toggleable(),
                TextColumn::make('numeric_value')->sortable()->toggleable(),
                TextColumn::make('unit')->toggleable(),
                IconColumn::make('is_active')->boolean()->sortable(),
                TextColumn::make('aliases_count')->counts('aliases')->label('Aliases')->sortable(),
            ])
            ->filters([
                SelectFilter::make('canonical_attribute_id')
                    ->label('Attribute')
                    ->options(fn (): array => CanonicalAttribute::query()->orderBy('name')->pluck('name', 'id')->all()),
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
            'index' => ListCanonicalAttributeValues::route('/'),
            'create' => CreateCanonicalAttributeValue::route('/create'),
            'edit' => EditCanonicalAttributeValue::route('/{record}/edit'),
        ];
    }
}

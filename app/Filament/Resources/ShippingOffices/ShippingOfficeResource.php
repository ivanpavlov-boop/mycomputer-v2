<?php

namespace App\Filament\Resources\ShippingOffices;

use App\Filament\Resources\ShippingOffices\Pages\CreateShippingOffice;
use App\Filament\Resources\ShippingOffices\Pages\EditShippingOffice;
use App\Filament\Resources\ShippingOffices\Pages\ListShippingOffices;
use App\Models\ShippingOffice;
use BackedEnum;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class ShippingOfficeResource extends Resource
{
    protected static ?string $model = ShippingOffice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?string $navigationLabel = 'Shipping Offices';

    protected static string|UnitEnum|null $navigationGroup = 'Shipping';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Section::make('Office')->schema([
            Select::make('shipping_provider_id')->relationship('provider', 'name')->required()->searchable()->preload(),
            TextInput::make('office_id')->required(),
            TextInput::make('name')->required(),
            TextInput::make('city')->required(),
            TextInput::make('postcode'),
            TextInput::make('address')->required(),
            TextInput::make('phone'),
            TextInput::make('latitude')->numeric(),
            TextInput::make('longitude')->numeric(),
            Select::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive'])->required(),
            KeyValue::make('raw_data'),
        ])]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('provider.name')->sortable(),
            TextColumn::make('office_id')->searchable(),
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('city')->searchable()->sortable(),
            TextColumn::make('address')->limit(45),
            TextColumn::make('status')->badge(),
        ])->filters([
            SelectFilter::make('provider')->relationship('provider', 'name')->searchable()->preload(),
            SelectFilter::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive']),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListShippingOffices::route('/'), 'create' => CreateShippingOffice::route('/create'), 'edit' => EditShippingOffice::route('/{record}/edit')];
    }
}

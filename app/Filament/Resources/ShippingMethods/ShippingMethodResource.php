<?php

namespace App\Filament\Resources\ShippingMethods;

use App\Filament\Resources\ShippingMethods\Pages\CreateShippingMethod;
use App\Filament\Resources\ShippingMethods\Pages\EditShippingMethod;
use App\Filament\Resources\ShippingMethods\Pages\ListShippingMethods;
use App\Models\ShippingMethod;
use BackedEnum;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class ShippingMethodResource extends Resource
{
    protected static ?string $model = ShippingMethod::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Shipping Methods';

    protected static string|UnitEnum|null $navigationGroup = 'Shipping';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Section::make('Method')->schema([
            Select::make('shipping_provider_id')->relationship('provider', 'name')->required()->searchable()->preload(),
            TextInput::make('name')->required(),
            TextInput::make('code')->required(),
            Select::make('type')->options(['office' => 'Office', 'address' => 'Address', 'locker' => 'Locker', 'pickup' => 'Pickup'])->required(),
            Select::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive'])->required(),
            TextInput::make('price')->numeric()->prefix('BGN')->required(),
            TextInput::make('free_shipping_threshold')->numeric()->prefix('BGN'),
            TextInput::make('sort_order')->numeric()->default(0),
            KeyValue::make('settings'),
        ])]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('provider.name')->sortable(),
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('code')->badge(),
            TextColumn::make('type')->badge(),
            TextColumn::make('status')->badge(),
            TextColumn::make('price')->money('BGN')->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListShippingMethods::route('/'), 'create' => CreateShippingMethod::route('/create'), 'edit' => EditShippingMethod::route('/{record}/edit')];
    }
}

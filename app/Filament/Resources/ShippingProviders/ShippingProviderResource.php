<?php

namespace App\Filament\Resources\ShippingProviders;

use App\Filament\Resources\ShippingProviders\Pages\CreateShippingProvider;
use App\Filament\Resources\ShippingProviders\Pages\EditShippingProvider;
use App\Filament\Resources\ShippingProviders\Pages\ListShippingProviders;
use App\Models\ShippingProvider;
use App\Services\Shipping\ShippingOfficeService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class ShippingProviderResource extends Resource
{
    protected static ?string $model = ShippingProvider::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $navigationLabel = 'Shipping Providers';

    protected static string|UnitEnum|null $navigationGroup = 'Shipping';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Provider')->schema([
                TextInput::make('name')->required(),
                TextInput::make('code')->required()->unique(ignoreRecord: true),
                Select::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive'])->required(),
                KeyValue::make('credentials')->keyLabel('Key')->valueLabel('Value'),
                KeyValue::make('settings')->keyLabel('Setting')->valueLabel('Value'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('code')->badge()->sortable(),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('updated_at')->dateTime()->sortable(),
        ])->recordActions([
            Action::make('syncOffices')
                ->label('Sync offices')
                ->icon('heroicon-o-arrow-path')
                ->action(function (ShippingProvider $record, ShippingOfficeService $service): void {
                    $count = $service->sync($record);
                    Notification::make()->title("Synced {$count} offices")->success()->send();
                }),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListShippingProviders::route('/'),
            'create' => CreateShippingProvider::route('/create'),
            'edit' => EditShippingProvider::route('/{record}/edit'),
        ];
    }
}

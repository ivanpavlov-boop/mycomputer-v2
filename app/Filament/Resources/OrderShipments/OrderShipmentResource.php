<?php

namespace App\Filament\Resources\OrderShipments;

use App\Filament\Resources\OrderShipments\Pages\CreateOrderShipment;
use App\Filament\Resources\OrderShipments\Pages\EditOrderShipment;
use App\Filament\Resources\OrderShipments\Pages\ListOrderShipments;
use App\Models\OrderShipment;
use BackedEnum;
use Filament\Actions\Action;
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

class OrderShipmentResource extends Resource
{
    protected static ?string $model = OrderShipment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $navigationLabel = 'Order Shipments';

    protected static string|UnitEnum|null $navigationGroup = 'Shipping';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Section::make('Shipment')->schema([
            Select::make('order_id')->relationship('order', 'order_number')->required()->searchable()->preload(),
            Select::make('shipping_provider_id')->relationship('provider', 'name')->searchable()->preload(),
            Select::make('shipping_method_id')->relationship('method', 'name')->searchable()->preload(),
            Select::make('office_id')->relationship('office', 'name')->searchable()->preload(),
            TextInput::make('tracking_number'),
            TextInput::make('label_path'),
            TextInput::make('delivery_type')->required(),
            TextInput::make('recipient_name')->required(),
            TextInput::make('recipient_phone')->required(),
            TextInput::make('city')->required(),
            TextInput::make('postcode'),
            TextInput::make('address'),
            TextInput::make('price')->numeric()->prefix('EUR')->required(),
            Select::make('status')->options(['pending' => 'Pending', 'created' => 'Created', 'cancelled' => 'Cancelled', 'delivered' => 'Delivered'])->required(),
        ])]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('order.order_number')->searchable()->sortable(),
            TextColumn::make('provider.name')->sortable(),
            TextColumn::make('method.name')->toggleable(),
            TextColumn::make('tracking_number')->searchable(),
            TextColumn::make('delivery_type')->badge(),
            TextColumn::make('city')->searchable(),
            TextColumn::make('price')->money('EUR'),
            TextColumn::make('status')->badge(),
        ])->recordActions([
            Action::make('tracking')->icon('heroicon-o-map')->action(fn () => Notification::make()->title('Tracking placeholder')->success()->send()),
            Action::make('printLabel')->label('Print label')->icon('heroicon-o-printer')->action(fn () => Notification::make()->title('Label placeholder')->success()->send()),
            Action::make('cancel')->icon('heroicon-o-x-circle')->requiresConfirmation()->action(fn (OrderShipment $record) => $record->update(['status' => 'cancelled'])),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListOrderShipments::route('/'), 'create' => CreateOrderShipment::route('/create'), 'edit' => EditOrderShipment::route('/{record}/edit')];
    }
}

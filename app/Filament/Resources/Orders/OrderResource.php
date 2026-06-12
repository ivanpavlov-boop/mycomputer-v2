<?php

namespace App\Filament\Resources\Orders;

use App\Events\OrderCancelled;
use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Jobs\CreateErpInvoiceJob;
use App\Jobs\SyncOrderToErpJob;
use App\Models\ErpDocument;
use App\Models\Order;
use App\Models\ShippingMethod;
use App\Models\ShippingOffice;
use App\Models\ShippingProvider;
use App\Services\Erp\ErpService;
use App\Services\Payments\PaymentService;
use App\Services\Shipping\ShipmentService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class OrderResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = Order::class;

    protected static ?string $permission = 'manage orders';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?string $navigationLabel = 'Orders';

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Order')->schema([
                Grid::make(3)->schema([
                    TextInput::make('order_number')->disabled()->dehydrated(),
                    Select::make('status')->options(array_combine(Order::STATUSES, Order::STATUSES))->required(),
                    Select::make('payment_status')->options(array_combine(Order::PAYMENT_STATUSES, Order::PAYMENT_STATUSES))->required(),
                    Select::make('shipping_status')->options(array_combine(Order::SHIPPING_STATUSES, Order::SHIPPING_STATUSES))->required(),
                    TextInput::make('customer_name')->required(),
                    TextInput::make('customer_email')->email()->required(),
                    TextInput::make('customer_phone')->required(),
                    TextInput::make('grand_total')->numeric()->prefix('BGN')->required(),
                ]),
                Textarea::make('billing_address')->rows(2)->columnSpanFull(),
                Textarea::make('shipping_address')->rows(2)->columnSpanFull(),
                Textarea::make('notes')->rows(2)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('order_number')->searchable()->sortable(),
                TextColumn::make('customer_name')->label('Customer')->searchable(),
                TextColumn::make('customer_phone')->searchable(),
                TextColumn::make('customer_email')->searchable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('payment_status')->badge()->sortable(),
                TextColumn::make('shipping_status')->badge()->sortable(),
                TextColumn::make('grand_total')->money('BGN')->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(array_combine(Order::STATUSES, Order::STATUSES)),
                SelectFilter::make('payment_status')->options(array_combine(Order::PAYMENT_STATUSES, Order::PAYMENT_STATUSES)),
                SelectFilter::make('shipping_status')->options(array_combine(Order::SHIPPING_STATUSES, Order::SHIPPING_STATUSES)),
            ])
            ->recordActions([
                Action::make('changeStatus')
                    ->label('Change status')
                    ->icon('heroicon-o-arrow-path')
                    ->schema([Select::make('status')->options(array_combine(Order::STATUSES, Order::STATUSES))->required()])
                    ->action(function (Order $record, array $data): void {
                        $record->update(['status' => $data['status']]);
                        if ($data['status'] === 'cancelled') {
                            OrderCancelled::dispatch($record->id);
                        }
                    }),
                Action::make('changePaymentStatus')
                    ->label('Payment')
                    ->icon('heroicon-o-credit-card')
                    ->schema([Select::make('payment_status')->options(array_combine(Order::PAYMENT_STATUSES, Order::PAYMENT_STATUSES))->required()])
                    ->action(fn (Order $record, array $data) => $record->update(['payment_status' => $data['payment_status']])),
                Action::make('changeShippingStatus')
                    ->label('Shipping')
                    ->icon('heroicon-o-truck')
                    ->schema([Select::make('shipping_status')->options(array_combine(Order::SHIPPING_STATUSES, Order::SHIPPING_STATUSES))->required()])
                    ->action(fn (Order $record, array $data) => $record->update(['shipping_status' => $data['shipping_status']])),
                Action::make('viewItems')
                    ->label('Items')
                    ->icon('heroicon-o-list-bullet')
                    ->modalHeading(fn (Order $record): string => 'Order items '.$record->order_number)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn (Order $record) => view('filament.orders.items', [
                        'order' => $record->load('items'),
                    ])),
                Action::make('createShipment')
                    ->label('Create shipment')
                    ->icon('heroicon-o-truck')
                    ->schema([
                        Select::make('shipping_provider_id')->options(fn (): array => ShippingProvider::query()->pluck('name', 'id')->all())->searchable(),
                        Select::make('shipping_method_id')->options(fn (): array => ShippingMethod::query()->pluck('name', 'id')->all())->searchable(),
                        Select::make('office_id')->options(fn (): array => ShippingOffice::query()->pluck('name', 'id')->all())->searchable(),
                        Select::make('delivery_type')->options(['office' => 'Office', 'address' => 'Address', 'manual' => 'Manual'])->required(),
                        TextInput::make('city')->required(),
                        TextInput::make('postcode'),
                        TextInput::make('address'),
                        TextInput::make('price')->numeric()->prefix('BGN')->required(),
                    ])
                    ->action(function (Order $record, array $data, ShipmentService $shipmentService): void {
                        $shipmentService->create($record, $data);
                    }),
                Action::make('print')
                    ->label('Print')
                    ->icon('heroicon-o-printer')
                    ->disabled(),
                Action::make('sendToErp')
                    ->label('Send to ERP')
                    ->icon('heroicon-o-server-stack')
                    ->visible(fn (): bool => (bool) auth()->user()?->can('manage erp'))
                    ->action(function (Order $record, ErpService $erp): void {
                        $syncJob = $erp->createSyncJob('push', 'order', $record->id, $erp->orderPayload($record));
                        SyncOrderToErpJob::dispatch($syncJob->id);
                    }),
                Action::make('createErpInvoice')
                    ->label('ERP invoice')
                    ->icon('heroicon-o-document-plus')
                    ->visible(fn (): bool => (bool) auth()->user()?->can('manage erp'))
                    ->action(function (Order $record, ErpService $erp): void {
                        $syncJob = $erp->createSyncJob('push', 'invoice', $record->id, $erp->orderPayload($record));
                        CreateErpInvoiceJob::dispatch($syncJob->id);
                    }),
                Action::make('viewErpDocuments')
                    ->label('ERP docs')
                    ->icon('heroicon-o-document-text')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn (Order $record) => view('filament.erp.documents', [
                        'documents' => ErpDocument::query()->where('order_id', $record->id)->latest()->get(),
                    ])),
                Action::make('markPaid')
                    ->label('Mark paid')
                    ->icon('heroicon-o-check-circle')
                    ->requiresConfirmation()
                    ->action(fn (Order $record, PaymentService $paymentService) => $paymentService->markPaid($record)),
                Action::make('markFailed')
                    ->label('Mark failed')
                    ->icon('heroicon-o-x-circle')
                    ->requiresConfirmation()
                    ->action(fn (Order $record, PaymentService $paymentService) => $paymentService->markFailed($record)),
                Action::make('refund')
                    ->label('Refund')
                    ->icon('heroicon-o-receipt-refund')
                    ->disabled(),
                Action::make('bankInstructions')
                    ->label('Bank instructions')
                    ->icon('heroicon-o-envelope')
                    ->disabled(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'create' => CreateOrder::route('/create'),
            'edit' => EditOrder::route('/{record}/edit'),
        ];
    }
}

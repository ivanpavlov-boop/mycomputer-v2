<?php

namespace App\Filament\Resources\PaymentTransactions;

use App\Filament\Resources\PaymentTransactions\Pages\CreatePaymentTransaction;
use App\Filament\Resources\PaymentTransactions\Pages\EditPaymentTransaction;
use App\Filament\Resources\PaymentTransactions\Pages\ListPaymentTransactions;
use App\Models\PaymentTransaction;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
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

class PaymentTransactionResource extends Resource
{
    protected static ?string $model = PaymentTransaction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?string $navigationLabel = 'Payment Transactions';

    protected static string|UnitEnum|null $navigationGroup = 'Payments';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Section::make('Transaction')->schema([
            Select::make('order_id')->relationship('order', 'order_number')->required()->searchable()->preload(),
            Select::make('payment_provider_id')->relationship('provider', 'name')->searchable()->preload(),
            Select::make('payment_method_id')->relationship('method', 'name')->searchable()->preload(),
            TextInput::make('transaction_id'),
            TextInput::make('amount')->numeric()->required(),
            TextInput::make('currency')->default('BGN')->required(),
            Select::make('status')->options(array_combine(PaymentTransaction::STATUSES, PaymentTransaction::STATUSES))->required(),
            DateTimePicker::make('paid_at'),
            DateTimePicker::make('failed_at'),
            KeyValue::make('raw_request'),
            KeyValue::make('raw_response'),
        ])]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('order.order_number')->searchable()->sortable(),
            TextColumn::make('method.name')->sortable(),
            TextColumn::make('transaction_id')->searchable(),
            TextColumn::make('amount')->money('BGN')->sortable(),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('created_at')->dateTime()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPaymentTransactions::route('/'), 'create' => CreatePaymentTransaction::route('/create'), 'edit' => EditPaymentTransaction::route('/{record}/edit')];
    }
}

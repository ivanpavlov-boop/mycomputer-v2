<?php

namespace App\Filament\Resources\PaymentProviders;

use App\Filament\Resources\PaymentProviders\Pages\CreatePaymentProvider;
use App\Filament\Resources\PaymentProviders\Pages\EditPaymentProvider;
use App\Filament\Resources\PaymentProviders\Pages\ListPaymentProviders;
use App\Models\PaymentProvider;
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

class PaymentProviderResource extends Resource
{
    protected static ?string $model = PaymentProvider::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?string $navigationLabel = 'Payment Providers';

    protected static string|UnitEnum|null $navigationGroup = 'Payments';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Section::make('Provider')->schema([
            TextInput::make('name')->required(),
            TextInput::make('code')->required()->unique(ignoreRecord: true),
            Select::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive'])->required(),
            KeyValue::make('credentials'),
            KeyValue::make('settings'),
        ])]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('code')->badge()->sortable(),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('updated_at')->dateTime()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPaymentProviders::route('/'), 'create' => CreatePaymentProvider::route('/create'), 'edit' => EditPaymentProvider::route('/{record}/edit')];
    }
}

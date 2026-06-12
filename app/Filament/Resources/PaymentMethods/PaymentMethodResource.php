<?php

namespace App\Filament\Resources\PaymentMethods;

use App\Filament\Resources\PaymentMethods\Pages\CreatePaymentMethod;
use App\Filament\Resources\PaymentMethods\Pages\EditPaymentMethod;
use App\Filament\Resources\PaymentMethods\Pages\ListPaymentMethods;
use App\Models\PaymentMethod;
use BackedEnum;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class PaymentMethodResource extends Resource
{
    protected static ?string $model = PaymentMethod::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Payment Methods';

    protected static string|UnitEnum|null $navigationGroup = 'Payments';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Section::make('Method')->schema([
            Select::make('payment_provider_id')->relationship('provider', 'name')->searchable()->preload(),
            TextInput::make('name')->required(),
            TextInput::make('code')->required()->unique(ignoreRecord: true),
            Select::make('type')->options(['offline' => 'Offline', 'online' => 'Online', 'leasing' => 'Leasing'])->required(),
            Select::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive'])->required(),
            Textarea::make('description'),
            Textarea::make('instructions'),
            TextInput::make('sort_order')->numeric()->default(0),
            KeyValue::make('settings'),
        ])]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('code')->badge()->sortable(),
            TextColumn::make('type')->badge()->sortable(),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('sort_order')->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPaymentMethods::route('/'), 'create' => CreatePaymentMethod::route('/create'), 'edit' => EditPaymentMethod::route('/{record}/edit')];
    }
}

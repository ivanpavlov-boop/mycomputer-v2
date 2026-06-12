<?php

namespace App\Filament\Resources\ErpCustomerMappings;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\ErpCustomerMappings\Pages\CreateErpCustomerMapping;
use App\Filament\Resources\ErpCustomerMappings\Pages\EditErpCustomerMapping;
use App\Filament\Resources\ErpCustomerMappings\Pages\ListErpCustomerMappings;
use App\Models\ErpCustomerMapping;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class ErpCustomerMappingResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = ErpCustomerMapping::class;

    protected static ?string $permission = 'manage erp';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'ERP Customer Mappings';

    protected static string|UnitEnum|null $navigationGroup = 'ERP';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('provider_id')->relationship('provider', 'name')->searchable()->preload(),
            Select::make('user_id')->relationship('user', 'email')->searchable()->preload(),
            Select::make('customer_id')->relationship('customer', 'email')->searchable()->preload(),
            TextInput::make('external_customer_id'),
            TextInput::make('external_company_id'),
            Toggle::make('sync_enabled')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('provider.name')->placeholder('None')->sortable(),
                TextColumn::make('user.email')->searchable(),
                TextColumn::make('customer.email')->searchable(),
                TextColumn::make('external_customer_id')->searchable(),
                TextColumn::make('external_company_id')->searchable(),
                IconColumn::make('sync_enabled')->boolean(),
                TextColumn::make('last_synced_at')->dateTime()->sortable(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListErpCustomerMappings::route('/'),
            'create' => CreateErpCustomerMapping::route('/create'),
            'edit' => EditErpCustomerMapping::route('/{record}/edit'),
        ];
    }
}

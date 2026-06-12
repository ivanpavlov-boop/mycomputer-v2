<?php

namespace App\Filament\Resources\RewardVouchers;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\RewardVouchers\Pages\CreateRewardVoucher;
use App\Filament\Resources\RewardVouchers\Pages\EditRewardVoucher;
use App\Filament\Resources\RewardVouchers\Pages\ListRewardVouchers;
use App\Models\RewardVoucher;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
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

class RewardVoucherResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = RewardVoucher::class;

    protected static ?string $permission = 'manage marketing';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?string $navigationLabel = 'Reward Vouchers';

    protected static string|UnitEnum|null $navigationGroup = 'Loyalty';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->required()->unique(ignoreRecord: true),
            TextInput::make('title')->required(),
            TextInput::make('points_cost')->numeric()->required(),
            Select::make('discount_type')->options(array_combine(RewardVoucher::DISCOUNT_TYPES, RewardVoucher::DISCOUNT_TYPES))->required(),
            TextInput::make('discount_value')->numeric()->required(),
            TextInput::make('minimum_order_amount')->numeric(),
            DateTimePicker::make('starts_at'),
            DateTimePicker::make('expires_at'),
            TextInput::make('usage_limit')->numeric(),
            Toggle::make('is_active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->searchable()->sortable(),
            TextColumn::make('title')->searchable(),
            TextColumn::make('points_cost')->sortable(),
            TextColumn::make('discount_type')->badge(),
            TextColumn::make('discount_value')->sortable(),
            TextColumn::make('usage_count')->sortable(),
            IconColumn::make('is_active')->boolean(),
        ])->recordActions([EditAction::make()])->defaultSort('points_cost');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRewardVouchers::route('/'),
            'create' => CreateRewardVoucher::route('/create'),
            'edit' => EditRewardVoucher::route('/{record}/edit'),
        ];
    }
}

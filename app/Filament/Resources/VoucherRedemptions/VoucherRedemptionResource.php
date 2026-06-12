<?php

namespace App\Filament\Resources\VoucherRedemptions;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\VoucherRedemptions\Pages\ListVoucherRedemptions;
use App\Models\VoucherRedemption;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class VoucherRedemptionResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = VoucherRedemption::class;

    protected static ?string $permission = 'manage marketing';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?string $navigationLabel = 'Voucher Redemptions';

    protected static string|UnitEnum|null $navigationGroup = 'Loyalty';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('user.email')->searchable(),
            TextColumn::make('voucher.title')->searchable(),
            TextColumn::make('code')->searchable()->sortable(),
            TextColumn::make('order.order_number')->searchable(),
            TextColumn::make('redeemed_points')->sortable(),
            TextColumn::make('created_at')->dateTime()->sortable(),
        ])->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListVoucherRedemptions::route('/')];
    }
}

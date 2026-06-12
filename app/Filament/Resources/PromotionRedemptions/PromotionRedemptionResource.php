<?php

namespace App\Filament\Resources\PromotionRedemptions;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\PromotionRedemptions\Pages\ListPromotionRedemptions;
use App\Models\PromotionRedemption;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class PromotionRedemptionResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = PromotionRedemption::class;

    protected static ?string $permission = 'manage marketing';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?string $navigationLabel = 'Promotion Redemptions';

    protected static string|UnitEnum|null $navigationGroup = 'Marketing';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('promotion.name')->searchable()->sortable(),
            TextColumn::make('order.order_number')->searchable(),
            TextColumn::make('user.email')->searchable(),
            TextColumn::make('session_id')->searchable(),
            TextColumn::make('discount_amount')->money('BGN')->sortable(),
            TextColumn::make('created_at')->dateTime()->sortable(),
        ])->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListPromotionRedemptions::route('/')];
    }
}

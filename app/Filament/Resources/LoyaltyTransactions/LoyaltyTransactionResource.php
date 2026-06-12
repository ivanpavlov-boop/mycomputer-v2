<?php

namespace App\Filament\Resources\LoyaltyTransactions;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\LoyaltyTransactions\Pages\ListLoyaltyTransactions;
use App\Models\LoyaltyTransaction;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class LoyaltyTransactionResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = LoyaltyTransaction::class;

    protected static ?string $permission = 'manage marketing';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Loyalty Transactions';

    protected static string|UnitEnum|null $navigationGroup = 'Loyalty';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('account.user.email')->searchable(),
            TextColumn::make('type')->badge()->sortable(),
            TextColumn::make('points')->sortable(),
            TextColumn::make('description')->searchable(),
            TextColumn::make('expires_at')->dateTime()->sortable(),
            TextColumn::make('created_at')->dateTime()->sortable(),
        ])->filters([
            SelectFilter::make('type')->options(array_combine(LoyaltyTransaction::TYPES, LoyaltyTransaction::TYPES)),
        ])->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListLoyaltyTransactions::route('/')];
    }
}

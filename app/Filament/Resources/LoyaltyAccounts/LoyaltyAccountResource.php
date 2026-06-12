<?php

namespace App\Filament\Resources\LoyaltyAccounts;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\LoyaltyAccounts\Pages\ListLoyaltyAccounts;
use App\Models\LoyaltyAccount;
use App\Services\Loyalty\PointsService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class LoyaltyAccountResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = LoyaltyAccount::class;

    protected static ?string $permission = 'manage marketing';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static ?string $navigationLabel = 'Loyalty Accounts';

    protected static string|UnitEnum|null $navigationGroup = 'Loyalty';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('user.email')->disabled(),
            TextInput::make('points_balance')->disabled(),
            TextInput::make('lifetime_points')->disabled(),
            TextInput::make('tier')->disabled(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('user.email')->searchable()->sortable(),
            TextColumn::make('points_balance')->sortable(),
            TextColumn::make('lifetime_points')->sortable(),
            TextColumn::make('tier')->badge()->sortable(),
            TextColumn::make('updated_at')->dateTime()->sortable(),
        ])->recordActions([
            Action::make('adjustPoints')
                ->label('Adjust points')
                ->schema([
                    TextInput::make('points')->numeric()->required(),
                    TextInput::make('description')->required()->maxLength(255),
                ])
                ->action(function (LoyaltyAccount $record, array $data): void {
                    app(PointsService::class)->adjust($record->user, (int) $data['points'], $data['description']);
                }),
        ])->defaultSort('points_balance', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListLoyaltyAccounts::route('/')];
    }
}

<?php

namespace App\Filament\Resources\PromotionRules;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\PromotionRules\Pages\ListPromotionRules;
use App\Models\PromotionRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class PromotionRuleResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = PromotionRule::class;

    protected static ?string $permission = 'manage marketing';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $navigationLabel = 'Promotion Rules';

    protected static string|UnitEnum|null $navigationGroup = 'Marketing';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('promotion.name')->searchable()->sortable(),
            TextColumn::make('rule_type')->badge()->sortable(),
            TextColumn::make('operator')->badge(),
            TextColumn::make('value')->formatStateUsing(fn ($state): string => json_encode($state, JSON_UNESCAPED_UNICODE)),
            TextColumn::make('created_at')->dateTime()->sortable(),
        ])->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListPromotionRules::route('/')];
    }
}

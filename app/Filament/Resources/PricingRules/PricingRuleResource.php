<?php

namespace App\Filament\Resources\PricingRules;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\PricingRules\Pages\CreatePricingRule;
use App\Filament\Resources\PricingRules\Pages\EditPricingRule;
use App\Filament\Resources\PricingRules\Pages\ListPricingRules;
use App\Filament\Resources\PricingRules\Schemas\PricingRuleForm;
use App\Filament\Resources\PricingRules\Tables\PricingRulesTable;
use App\Models\PricingRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class PricingRuleResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = PricingRule::class;

    protected static ?string $permission = 'manage products';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyEuro;

    protected static ?string $navigationLabel = 'Pricing Rules';

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    public static function form(Schema $schema): Schema
    {
        return PricingRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PricingRulesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPricingRules::route('/'),
            'create' => CreatePricingRule::route('/create'),
            'edit' => EditPricingRule::route('/{record}/edit'),
        ];
    }
}

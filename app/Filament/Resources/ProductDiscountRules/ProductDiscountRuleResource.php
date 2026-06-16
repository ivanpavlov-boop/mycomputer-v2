<?php

namespace App\Filament\Resources\ProductDiscountRules;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\ProductDiscountRules\Pages\CreateProductDiscountRule;
use App\Filament\Resources\ProductDiscountRules\Pages\EditProductDiscountRule;
use App\Filament\Resources\ProductDiscountRules\Pages\ListProductDiscountRules;
use App\Filament\Resources\ProductDiscountRules\Schemas\ProductDiscountRuleForm;
use App\Filament\Resources\ProductDiscountRules\Tables\ProductDiscountRulesTable;
use App\Models\ProductDiscountRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ProductDiscountRuleResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = ProductDiscountRule::class;

    protected static ?string $permission = 'manage products';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?string $navigationLabel = 'Discount Rules';

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    public static function form(Schema $schema): Schema
    {
        return ProductDiscountRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductDiscountRulesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductDiscountRules::route('/'),
            'create' => CreateProductDiscountRule::route('/create'),
            'edit' => EditProductDiscountRule::route('/{record}/edit'),
        ];
    }
}

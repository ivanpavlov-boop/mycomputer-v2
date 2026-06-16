<?php

namespace App\Filament\Resources\ProductDiscountRules\Schemas;

use App\Models\ProductDiscountRule;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductDiscountRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Rule')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('name')->required()->maxLength(255),
                        Select::make('scope_type')
                            ->options([
                                ProductDiscountRule::SCOPE_PRODUCT => 'Product',
                                ProductDiscountRule::SCOPE_CATEGORY_BRAND_SUPPLIER => 'Category + Brand + Supplier',
                                ProductDiscountRule::SCOPE_CATEGORY_BRAND => 'Category + Brand',
                                ProductDiscountRule::SCOPE_CATEGORY_SUPPLIER => 'Category + Supplier',
                                ProductDiscountRule::SCOPE_CATEGORY => 'Category',
                                ProductDiscountRule::SCOPE_BRAND => 'Brand',
                                ProductDiscountRule::SCOPE_SUPPLIER => 'Supplier',
                                ProductDiscountRule::SCOPE_GLOBAL_CAMPAIGN => 'Global campaign',
                            ])
                            ->required()
                            ->default(ProductDiscountRule::SCOPE_GLOBAL_CAMPAIGN),
                        Select::make('product_id')->relationship('product', 'name')->searchable()->preload(),
                        Select::make('category_id')->relationship('category', 'name')->searchable()->preload(),
                        Select::make('brand_id')->relationship('brand', 'name')->searchable()->preload(),
                        Select::make('supplier_id')->relationship('supplier', 'company_name')->searchable()->preload(),
                        Toggle::make('is_active')->default(true),
                        TextInput::make('sort_order')->numeric()->default(0),
                    ]),
                ]),
            Section::make('Discount')
                ->schema([
                    Grid::make(3)->schema([
                        Select::make('discount_type')
                            ->options([
                                ProductDiscountRule::TYPE_PERCENTAGE => 'Percentage discount',
                                ProductDiscountRule::TYPE_FIXED_PRICE => 'Fixed sale price',
                                ProductDiscountRule::TYPE_FIXED_AMOUNT => 'Fixed amount off',
                            ])
                            ->default(ProductDiscountRule::TYPE_PERCENTAGE)
                            ->required(),
                        TextInput::make('discount_value')->numeric()->required()->default(0),
                        DateTimePicker::make('starts_at'),
                        DateTimePicker::make('ends_at'),
                    ]),
                ]),
        ]);
    }
}

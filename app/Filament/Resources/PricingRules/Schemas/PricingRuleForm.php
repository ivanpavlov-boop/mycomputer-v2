<?php

namespace App\Filament\Resources\PricingRules\Schemas;

use App\Models\PricingRule;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class PricingRuleForm
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
                                PricingRule::SCOPE_PRODUCT => 'Product',
                                PricingRule::SCOPE_CATEGORY_BRAND_SUPPLIER => 'Category + Brand + Supplier',
                                PricingRule::SCOPE_CATEGORY_BRAND => 'Category + Brand',
                                PricingRule::SCOPE_CATEGORY_SUPPLIER => 'Category + Supplier',
                                PricingRule::SCOPE_CATEGORY => 'Category',
                                PricingRule::SCOPE_BRAND => 'Brand',
                                PricingRule::SCOPE_SUPPLIER => 'Supplier',
                                PricingRule::SCOPE_PRICE_RANGE => 'Price Range',
                                PricingRule::SCOPE_GLOBAL => 'Global',
                            ])
                            ->required()
                            ->default(PricingRule::SCOPE_GLOBAL),
                        Select::make('product_id')->relationship('product', 'name')->searchable()->preload(),
                        Select::make('category_id')->relationship('category', 'name')->searchable()->preload(),
                        Select::make('brand_id')->relationship('brand', 'name')->searchable()->preload(),
                        Select::make('supplier_id')->relationship('supplier', 'company_name')->searchable()->preload(),
                        TextInput::make('price_min')->numeric()->nullable(),
                        TextInput::make('price_max')->numeric()->nullable(),
                        Toggle::make('is_active')->default(true),
                        TextInput::make('sort_order')->numeric()->default(0),
                    ]),
                ]),
            Section::make('Margin')
                ->schema([
                    Grid::make(3)->schema([
                        Select::make('margin_type')
                            ->options([
                                PricingRule::MARGIN_PERCENTAGE => 'Percentage',
                                PricingRule::MARGIN_FIXED => 'Fixed amount',
                            ])
                            ->default(PricingRule::MARGIN_PERCENTAGE)
                            ->required(),
                        TextInput::make('margin_value')
                            ->numeric()
                            ->required()
                            ->default(0)
                            ->suffix(fn (Get $get): string => $get('margin_type') === PricingRule::MARGIN_FIXED ? 'EUR' : '%'),
                        TextInput::make('minimum_margin')->numeric()->nullable(),
                        TextInput::make('minimum_final_price')->numeric()->nullable(),
                        Select::make('rounding_rule')
                            ->options([
                                PricingRule::ROUND_NONE => 'None',
                                PricingRule::ROUND_NEAREST_0_01 => 'Nearest 0.01',
                                PricingRule::ROUND_NEAREST_0_05 => 'Nearest 0.05',
                                PricingRule::ROUND_NEAREST_0_10 => 'Nearest 0.10',
                                PricingRule::ROUND_UP_0_99 => 'Up to .99',
                            ])
                            ->default(PricingRule::ROUND_NONE),
                        Select::make('msrp_strategy')
                            ->options([
                                PricingRule::MSRP_MARGIN_ONLY => 'Margin price only',
                                PricingRule::MSRP_RECOMMENDED_ONLY => 'Recommended price only',
                                PricingRule::MSRP_RECOMMENDED_MIN_MARGIN => 'Recommended with minimum margin',
                                PricingRule::MSRP_HIGHER_OF_MARGIN_OR_RECOMMENDED => 'Higher of margin/recommended',
                                PricingRule::MSRP_LOWER_OF_MARGIN_OR_RECOMMENDED => 'Lower of margin/recommended',
                            ])
                            ->nullable(),
                    ]),
                ]),
        ]);
    }
}

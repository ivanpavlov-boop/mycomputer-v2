<?php

namespace App\Filament\Resources\ProductDiscountRules\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProductDiscountRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('scope_type')->badge()->sortable(),
                TextColumn::make('product.name')->toggleable(),
                TextColumn::make('category.name')->toggleable(),
                TextColumn::make('brand.name')->toggleable(),
                TextColumn::make('supplier.company_name')->toggleable(),
                TextColumn::make('discount_type')->badge()->sortable(),
                TextColumn::make('discount_value')->sortable(),
                TextColumn::make('starts_at')->dateTime()->sortable(),
                TextColumn::make('ends_at')->dateTime()->sortable(),
                IconColumn::make('is_active')->boolean()->sortable(),
            ])
            ->filters([
                SelectFilter::make('scope_type')->options([
                    'product' => 'Product',
                    'category_brand_supplier' => 'Category + Brand + Supplier',
                    'category_brand' => 'Category + Brand',
                    'category_supplier' => 'Category + Supplier',
                    'category' => 'Category',
                    'brand' => 'Brand',
                    'supplier' => 'Supplier',
                    'global_campaign' => 'Global campaign',
                ]),
                SelectFilter::make('discount_type')->options([
                    'percentage' => 'Percentage discount',
                    'fixed_price' => 'Fixed sale price',
                    'fixed_amount' => 'Fixed amount off',
                ]),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

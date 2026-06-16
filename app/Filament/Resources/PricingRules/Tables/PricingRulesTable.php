<?php

namespace App\Filament\Resources\PricingRules\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PricingRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('scope_type')->badge()->sortable(),
                TextColumn::make('product.name')->toggleable(),
                TextColumn::make('category.name')->toggleable(),
                TextColumn::make('supplier.company_name')->toggleable(),
                TextColumn::make('margin_type')->badge()->sortable(),
                TextColumn::make('margin_value')->sortable(),
                TextColumn::make('minimum_margin')->sortable()->toggleable(),
                TextColumn::make('minimum_final_price')->sortable()->toggleable(),
                TextColumn::make('msrp_strategy')->badge()->toggleable(),
                IconColumn::make('is_active')->boolean()->sortable(),
            ])
            ->filters([
                SelectFilter::make('scope_type')->options([
                    'product' => 'Product',
                    'category' => 'Category',
                    'supplier' => 'Supplier',
                    'global' => 'Global',
                ]),
                SelectFilter::make('margin_type')->options([
                    'percentage' => 'Percentage',
                    'fixed' => 'Fixed amount',
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

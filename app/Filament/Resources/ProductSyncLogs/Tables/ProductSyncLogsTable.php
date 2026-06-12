<?php

namespace App\Filament\Resources\ProductSyncLogs\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProductSyncLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('product.sku')->label('Product SKU')->searchable()->sortable(),
                TextColumn::make('supplier.company_name')->searchable()->sortable(),
                TextColumn::make('supplierProduct.supplier_sku')->label('Supplier SKU')->searchable(),
                TextColumn::make('match_type')->badge()->sortable(),
                TextColumn::make('strategy')->badge()->sortable(),
                TextColumn::make('action')->badge()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('message')->limit(80)->searchable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'synced' => 'Synced',
                    'skipped' => 'Skipped',
                    'conflict' => 'Conflict',
                    'duplicate' => 'Duplicate',
                ]),
                SelectFilter::make('action')->options([
                    'created' => 'Created',
                    'updated' => 'Updated',
                    'skipped' => 'Skipped',
                    'conflict' => 'Conflict',
                    'duplicate' => 'Duplicate',
                ]),
                SelectFilter::make('strategy')->options([
                    'lowest_price' => 'Lowest price',
                    'preferred_supplier' => 'Preferred supplier',
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

<?php

namespace App\Filament\Resources\FailedImports\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FailedImportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('import_job_id')->sortable(),
                TextColumn::make('supplier.company_name')->searchable()->sortable(),
                TextColumn::make('feed.feed_name')->searchable()->toggleable(),
                TextColumn::make('supplier_sku')->searchable(),
                TextColumn::make('row_number')->sortable(),
                TextColumn::make('error_type')->badge()->sortable(),
                TextColumn::make('error_message')->searchable()->limit(80),
            ])
            ->filters([
                SelectFilter::make('supplier')->relationship('supplier', 'company_name')->searchable()->preload(),
                SelectFilter::make('error_type')->options([
                    'validation' => 'Validation',
                    'xml' => 'XML',
                    'network' => 'Network',
                    'runtime' => 'Runtime',
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

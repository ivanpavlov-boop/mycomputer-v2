<?php

namespace App\Filament\Resources\SupplierImportRuns\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SupplierImportRunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('supplier.company_name')->label('Supplier')->searchable()->sortable(),
                TextColumn::make('feed.feed_name')->label('Feed')->toggleable(),
                TextColumn::make('trigger_type')->badge()->sortable(),
                TextColumn::make('import_type')->badge()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('products_seen')->sortable(),
                TextColumn::make('products_created')->sortable()->toggleable(),
                TextColumn::make('products_updated')->sortable()->toggleable(),
                TextColumn::make('products_skipped')->sortable()->toggleable(),
                TextColumn::make('products_failed')->sortable()->toggleable(),
                TextColumn::make('attributes_unmapped')->label('Unmapped attributes')->sortable()->toggleable(),
                TextColumn::make('availability_unmapped')->label('Unmapped availability')->sortable()->toggleable(),
                TextColumn::make('warning_count')->sortable()->toggleable(),
                TextColumn::make('error_count')->sortable()->toggleable(),
                TextColumn::make('duration_seconds')->sortable()->toggleable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('supplier')->relationship('supplier', 'company_name')->searchable()->preload(),
                SelectFilter::make('trigger_type')->options([
                    'scheduled' => 'Scheduled',
                    'manual' => 'Manual',
                    'retry' => 'Retry',
                    'force' => 'Force',
                ]),
                SelectFilter::make('status')->options([
                    'pending' => 'Pending',
                    'running' => 'Running',
                    'completed' => 'Completed',
                    'completed_with_warnings' => 'Completed with warnings',
                    'failed' => 'Failed',
                    'skipped' => 'Skipped',
                ]),
            ])
            ->recordActions([
                EditAction::make()->label('View report'),
            ])
            ->defaultSort('created_at', 'desc');
    }
}

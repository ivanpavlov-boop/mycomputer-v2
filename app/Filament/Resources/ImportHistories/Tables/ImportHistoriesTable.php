<?php

namespace App\Filament\Resources\ImportHistories\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ImportHistoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('import_job_id')->sortable(),
                TextColumn::make('supplier.company_name')->searchable()->sortable(),
                TextColumn::make('feed.feed_name')->searchable()->toggleable(),
                TextColumn::make('event')->badge()->sortable(),
                TextColumn::make('level')->badge()->sortable(),
                TextColumn::make('message')->searchable()->limit(80),
            ])
            ->filters([
                SelectFilter::make('supplier')->relationship('supplier', 'company_name')->searchable()->preload(),
                SelectFilter::make('level')->options([
                    'info' => 'Info',
                    'warning' => 'Warning',
                    'error' => 'Error',
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

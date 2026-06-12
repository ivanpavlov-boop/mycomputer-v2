<?php

namespace App\Filament\Resources\ProductAttributes\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ProductAttributesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('group.name')->label('Group')->sortable(),
                TextColumn::make('type')->badge()->sortable(),
                TextColumn::make('unit')->toggleable(),
                IconColumn::make('is_filterable')->boolean(),
                IconColumn::make('is_required')->boolean(),
                IconColumn::make('is_active')->boolean(),
                TextColumn::make('values_count')->counts('values')->label('Values')->sortable(),
            ])
            ->filters([
                SelectFilter::make('group')->relationship('group', 'name')->searchable()->preload(),
                SelectFilter::make('type')->options([
                    'select' => 'Select',
                    'text' => 'Text',
                    'number' => 'Number',
                    'boolean' => 'Boolean',
                ]),
                TernaryFilter::make('is_filterable'),
                TernaryFilter::make('is_active'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                RestoreAction::make(),
                ForceDeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                ]),
            ]);
    }
}

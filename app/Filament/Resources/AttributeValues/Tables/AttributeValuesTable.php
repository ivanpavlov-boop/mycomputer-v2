<?php

namespace App\Filament\Resources\AttributeValues\Tables;

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

class AttributeValuesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('value')->label('Етикет')->searchable()->sortable(),
                TextColumn::make('attribute.name')->label('Характеристика')->searchable()->sortable(),
                TextColumn::make('attribute.group.name')->label('Група')->sortable(),
                TextColumn::make('slug')->label('Стойност/slug')->searchable()->toggleable(),
                TextColumn::make('sort_order')->label('Ред')->sortable(),
                IconColumn::make('is_active')->label('Активна')->boolean(),
            ])
            ->filters([
                SelectFilter::make('attribute')->label('Характеристика')->relationship('attribute', 'name')->searchable()->preload(),
                TernaryFilter::make('is_active')->label('Активна'),
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

<?php

namespace App\Filament\Resources\ProductAttributes\Tables;

use App\Models\ProductAttribute;
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
                TextColumn::make('code')->label('Код')->searchable()->sortable(),
                TextColumn::make('name_bg')->label('Име')->searchable()->sortable(),
                TextColumn::make('name_en')->label('Име EN')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('group.name')->label('Група')->sortable(),
                TextColumn::make('type')->label('Тип')->badge()->formatStateUsing(fn (?string $state): string => self::typeLabels()[$state] ?? (string) $state)->sortable(),
                TextColumn::make('unit')->label('Мерна единица')->toggleable(),
                IconColumn::make('is_filterable')->label('Филтър')->boolean(),
                IconColumn::make('is_visible_on_product')->label('Видима')->boolean(),
                IconColumn::make('is_comparable')->label('Сравнима')->boolean(),
                IconColumn::make('is_required_by_default')->label('Задължителна')->boolean(),
                IconColumn::make('is_active')->label('Активна')->boolean(),
                TextColumn::make('sort_order')->label('Подредба')->sortable(),
                TextColumn::make('values_count')->counts('values')->label('Опции')->sortable(),
            ])
            ->filters([
                SelectFilter::make('group')->label('Група')->relationship('group', 'name')->searchable()->preload(),
                SelectFilter::make('type')->label('Тип')->options(self::typeLabels()),
                TernaryFilter::make('is_filterable')->label('Филтър'),
                TernaryFilter::make('is_visible_on_product')->label('Видима в продукта'),
                TernaryFilter::make('is_comparable')->label('Сравнима'),
                TernaryFilter::make('is_required_by_default')->label('Задължителна'),
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

    /**
     * @return array<string, string>
     */
    private static function typeLabels(): array
    {
        return [
            ProductAttribute::TYPE_TEXT => 'Текст',
            ProductAttribute::TYPE_NUMBER => 'Число',
            ProductAttribute::TYPE_BOOLEAN => 'Да/Не',
            ProductAttribute::TYPE_SELECT => 'Избор',
            ProductAttribute::TYPE_MULTISELECT => 'Множествен избор',
            ProductAttribute::TYPE_DECIMAL => 'Десетично число',
            ProductAttribute::TYPE_JSON => 'JSON',
        ];
    }
}

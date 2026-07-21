<?php

namespace App\Filament\Resources\Categories\Tables;

use App\Filament\Resources\CategoryProductAttributes\CategoryProductAttributeResource;
use App\Models\Category;
use App\Services\Products\CategorySpecificationTemplateResolver;
use App\Services\Products\CategorySpecificationTemplateResult;
use Filament\Actions\Action;
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

class CategoriesTable
{
    public static function configure(Table $table): Table
    {
        $templateResolver = app(CategorySpecificationTemplateResolver::class);

        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('parent.name')->label('Parent')->sortable(),
                TextColumn::make('specification_template_coverage')
                    ->label('Шаблон характеристики')
                    ->state(fn (Category $record): string => $templateResolver->resolve($record)->statusLabel())
                    ->badge()
                    ->color(fn (Category $record): string => $templateResolver->resolve($record)->statusColor())
                    ->description(fn (Category $record): ?string => match ($templateResolver->resolve($record)->status) {
                        CategorySpecificationTemplateResult::STATUS_INHERITED_TEMPLATE => $templateResolver->resolve($record)->templateSourceLabel(),
                        default => null,
                    })
                    ->tooltip(fn (Category $record): ?string => $templateResolver->resolve($record)->hierarchyLabel() ?: null),
                TextColumn::make('direct_specification_attributes')
                    ->label('Директни характеристики')
                    ->state(fn (Category $record): int => $templateResolver->resolve($record)->directAttributeCount())
                    ->toggleable(),
                TextColumn::make('effective_specification_attributes')
                    ->label('Ефективни характеристики')
                    ->state(fn (Category $record): int => $templateResolver->resolve($record)->effectiveAttributeCount())
                    ->toggleable(),
                TextColumn::make('required_specification_attributes')
                    ->label('Задължителни характеристики')
                    ->state(fn (Category $record): int => $templateResolver->resolve($record)->requiredAttributeCount())
                    ->toggleable(),
                TextColumn::make('recommended_specification_attributes')
                    ->label('Препоръчителни характеристики')
                    ->state(fn (Category $record): int => $templateResolver->resolve($record)->recommendedAttributeCount())
                    ->toggleable(),
                TextColumn::make('slug')->searchable()->toggleable(),
                TextColumn::make('icon')->searchable()->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')->boolean(),
                TextColumn::make('sort_order')->sortable(),
                TextColumn::make('products_count')->counts('products')->label('Products')->sortable(),
            ])
            ->filters([
                SelectFilter::make('specification_template_coverage')
                    ->label('Покритие на шаблона')
                    ->placeholder('Всички')
                    ->options(CategorySpecificationTemplateResult::options())
                    ->query(fn ($query, array $data) => $templateResolver->applyCoverageQuery($query, $data['value'] ?? null)),
                TernaryFilter::make('is_active'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('manageSpecificationTemplate')
                    ->label('Управление на шаблона')
                    ->url(fn (Category $record): string => CategoryProductAttributeResource::getUrl('index', [
                        'tableFilters' => [
                            'category' => ['value' => $record->getKey()],
                        ],
                    ]))
                    ->visible(fn (): bool => CategoryProductAttributeResource::canViewAny()),
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

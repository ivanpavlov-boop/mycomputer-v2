<?php

namespace App\Filament\Resources\Products\Tables;

use App\Models\AvailabilityStatus;
use App\Models\Product;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('thumbnailImage')->withCount('activeQualityFlagAssignments'))
            ->defaultSort('created_at', 'desc')
            ->defaultSortOptionLabel('Най-нови първо')
            ->columns([
                ImageColumn::make('thumbnail')
                    ->label('Снимка')
                    ->state(fn (Product $record): ?string => $record->thumbnailUrl())
                    ->defaultImageUrl(self::placeholderImageUrl())
                    ->size(56)
                    ->square()
                    ->url(fn (Product $record): ?string => $record->thumbnailUrl())
                    ->openUrlInNewTab()
                    ->toggleable(),
                TextColumn::make('sku')->label('SKU')->searchable()->sortable(),
                TextColumn::make('name')->label('Име')->searchable()->sortable()->limit(45),
                TextColumn::make('workflow_status')
                    ->label('Работен статус')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::workflowStatusOptions()[$state] ?? 'Неизвестен')
                    ->color(fn (?string $state): string => match ($state) {
                        Product::WORKFLOW_PUBLISHED => 'success',
                        Product::WORKFLOW_APPROVED => 'info',
                        Product::WORKFLOW_PENDING_REVIEW => 'warning',
                        Product::WORKFLOW_CHANGES_REQUESTED => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('active_quality_flag_assignments_count')
                    ->label('Флагове за качество')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray')
                    ->toggleable(),
                TextColumn::make('category.name')->label('Категория')->sortable()->toggleable(),
                TextColumn::make('brand.name')->label('Бранд')->sortable()->toggleable(),
                TextColumn::make('price')->label('Цена')->money(Product::CATALOG_CURRENCY)->sortable(),
                TextColumn::make('promo_price')->label('Промо цена')->money(Product::CATALOG_CURRENCY)->sortable()->toggleable(),
                TextColumn::make('quantity')->label('Количество')->sortable(),
                TextColumn::make('reserved_quantity')->label('Резервирано количество')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('availabilityStatus.name')
                    ->label('Наличност')
                    ->formatStateUsing(fn (?string $state): string => self::availabilityLabel($state))
                    ->badge()
                    ->sortable(),
                TextColumn::make('stock_status')
                    ->label('Статус на наличност')
                    ->formatStateUsing(fn (?string $state): string => self::stockStatusOptions()[$state] ?? 'Неизвестен')
                    ->badge()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('manual_override')->label('Ръчна наличност')->boolean()->toggleable(),
                IconColumn::make('active')->label('Активен')->boolean(),
                IconColumn::make('featured')->label('Препоръчан')->boolean(),
                IconColumn::make('new_product')->label('Нов продукт')->boolean()->toggleable(),
                IconColumn::make('bestseller')->label('Бестселър')->boolean()->toggleable(),
                TextColumn::make('updated_at')->label('Обновен на')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('availability_status_id')->relationship('availabilityStatus', 'name')->label('Наличност')->searchable()->preload(),
                SelectFilter::make('workflow_status')
                    ->label('Работен статус')
                    ->options(self::workflowStatusOptions()),
                SelectFilter::make('stock_status')
                    ->label('Статус на наличност')
                    ->options(self::stockStatusOptions()),
                SelectFilter::make('category')->label('Категория')->relationship('category', 'name')->searchable()->preload(),
                SelectFilter::make('brand')->label('Бранд')->relationship('brand', 'name')->searchable()->preload(),
                TernaryFilter::make('active')->label('Активен'),
                TernaryFilter::make('featured')->label('Препоръчан'),
                TernaryFilter::make('new_product')->label('Нов продукт'),
                TernaryFilter::make('bestseller')->label('Бестселър'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make()->label('Редакция'),
                RestoreAction::make()->label('Възстановяване'),
                ForceDeleteAction::make()->label('Изтрий завинаги'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('assignAvailability')
                        ->label('Задай наличност')
                        ->form([
                            Select::make('availability_status_id')
                                ->label('Статус на наличност')
                                ->options(fn () => AvailabilityStatus::query()->active()->ordered()->pluck('name', 'id'))
                                ->required(),
                            Select::make('manual_override')
                                ->label('Ръчна наличност')
                                ->options([1 => 'Ръчна наличност включена', 0 => 'Ръчна наличност изключена'])
                                ->default(1)
                                ->required(),
                        ])
                        ->action(function ($records, array $data): void {
                            $status = AvailabilityStatus::query()->findOrFail($data['availability_status_id']);
                            $records->each->update([
                                'availability_status_id' => $status->id,
                                'stock_status' => $status->code,
                                'manual_override' => (bool) $data['manual_override'],
                            ]);
                        }),
                    DeleteBulkAction::make()
                        ->label('Изтрий избраните')
                        ->modalHeading('Изтриване на избрани продукти')
                        ->modalDescription('Избраните продукти ще бъдат преместени в кошчето. Историческите връзки остават запазени.')
                        ->modalSubmitActionLabel('Изтрий избраните'),
                    RestoreBulkAction::make()->label('Възстанови избраните'),
                    ForceDeleteBulkAction::make()->label('Изтрий избраните завинаги'),
                ]),
            ]);
    }

    protected static function placeholderImageUrl(): string
    {
        return 'data:image/svg+xml;utf8,'.rawurlencode(
            '<svg xmlns="http://www.w3.org/2000/svg" width="56" height="56" viewBox="0 0 56 56"><rect width="56" height="56" rx="6" fill="#f3f4f6"/><path d="M17 37h22l-7-9-5 6-3-4-7 7Z" fill="#9ca3af"/><circle cx="21" cy="21" r="4" fill="#d1d5db"/></svg>'
        );
    }

    /**
     * @return array<string, string>
     */
    protected static function workflowStatusOptions(): array
    {
        return [
            Product::WORKFLOW_DRAFT => 'Чернова',
            Product::WORKFLOW_PENDING_REVIEW => 'За преглед',
            Product::WORKFLOW_CHANGES_REQUESTED => 'Върнат за корекции',
            Product::WORKFLOW_APPROVED => 'Одобрен',
            Product::WORKFLOW_PUBLISHED => 'Публикуван',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected static function stockStatusOptions(): array
    {
        return [
            Product::STOCK_STATUS_OUT_OF_STOCK => 'Няма наличност',
            Product::STOCK_STATUS_IN_STOCK => 'В наличност',
            Product::STOCK_STATUS_LIMITED_STOCK => 'Ограничена наличност',
        ];
    }

    protected static function availabilityLabel(?string $state): string
    {
        return match (strtolower((string) $state)) {
            'out of stock', 'out_of_stock' => 'Няма наличност',
            'in stock', 'in_stock' => 'В наличност',
            'limited stock', 'limited_stock' => 'Ограничена наличност',
            default => filled($state) ? (string) $state : 'Неизвестен',
        };
    }
}

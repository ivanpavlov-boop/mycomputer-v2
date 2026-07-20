<?php

namespace App\Filament\Resources\Products\Tables;

use App\Filament\Resources\Products\ProductResource;
use App\Models\AvailabilityStatus;
use App\Models\Product;
use App\Services\Products\ProductSpecificationQualityResult;
use App\Services\Products\ProductSpecificationQualityService;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with([
                    'availabilityStatus',
                    'brand',
                    'category',
                    'supplier',
                    'thumbnailImage',
                ])
                ->withCount('activeQualityFlagAssignments'))
            ->defaultSort('created_at', 'desc')
            ->defaultSortOptionLabel('Най-нови първо')
            ->recordUrl(fn (Product $record): ?string => ProductResource::canEdit($record)
                ? ProductResource::getUrl('edit', ['record' => $record])
                : null)
            ->columns([
                ImageColumn::make('thumbnail')
                    ->label('Снимка')
                    ->state(fn (Product $record): ?string => $record->thumbnailUrl())
                    ->defaultImageUrl(self::placeholderImageUrl())
                    ->imageSize(42)
                    ->square()
                    ->toggleable(),
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->copyableState(fn (?string $state): ?string => $state)
                    ->copyMessage('SKU е копиран')
                    ->copyMessageDuration(1500)
                    ->fontFamily(FontFamily::Mono)
                    ->toggleable(),
                TextColumn::make('name')
                    ->label('Име на продукта')
                    ->searchable()
                    ->sortable()
                    ->lineClamp(2)
                    ->tooltip(fn (Product $record): string => $record->name)
                    ->grow()
                    ->toggleable(),
                TextColumn::make('category.name')
                    ->label('Категория')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('brand.name')
                    ->label('Марка')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('price')
                    ->label('Цена')
                    ->money(Product::CATALOG_CURRENCY)
                    ->sortable()
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('supplier.company_name')
                    ->label('Вносител')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('workflow_status')
                    ->label('Статус')
                    ->state(fn (): string => '●')
                    ->color(fn (Product $record): string => Product::workflowStatusColor($record->workflow_status))
                    ->tooltip(fn (Product $record): string => Product::workflowStatusLabel($record->workflow_status))
                    ->extraAttributes(fn (Product $record): array => [
                        'aria-label' => Product::workflowStatusLabel($record->workflow_status),
                        'role' => 'img',
                    ])
                    ->alignCenter()
                    ->size('xs')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('availabilityStatus.name')
                    ->label('Наличност')
                    ->formatStateUsing(fn (?string $state, Product $record): string => self::availabilityWithQuantity($record, $state))
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('storefront')
                    ->label('Виж в сайта')
                    ->state(fn (Product $record): string => $record->storefrontUrl() === null ? '—' : 'Виж в сайта')
                    ->url(fn (Product $record): ?string => $record->storefrontUrl())
                    ->openUrlInNewTab()
                    ->disabledClick(fn (Product $record): bool => $record->storefrontUrl() === null)
                    ->alignCenter()
                    ->toggleable(),
                TextColumn::make('active_quality_flag_assignments_count')
                    ->label('Флагове за качество')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('specification_quality')
                    ->label('Характеристики')
                    ->state(fn (Product $record): string => self::specificationQuality($record)->statusLabel())
                    ->description(fn (Product $record): string => self::specificationQuality($record)->scoreLabel())
                    ->badge()
                    ->color(fn (Product $record): string => self::specificationQuality($record)->statusColor())
                    ->tooltip(fn (Product $record): string => self::specificationQualityTooltip($record))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('promo_price')
                    ->label('Промо цена')
                    ->money(Product::CATALOG_CURRENCY)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('quantity')
                    ->label('Количество')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reserved_quantity')
                    ->label('Резервирано количество')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('stock_status')
                    ->label('Статус на наличност')
                    ->formatStateUsing(fn (?string $state): string => self::stockStatusOptions()[$state] ?? 'Неизвестен')
                    ->badge()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('manual_override')
                    ->label('Ръчна наличност')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('active')
                    ->label('Активен')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('featured')
                    ->label('Препоръчан')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('new_product')
                    ->label('Нов продукт')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('bestseller')
                    ->label('Бестселър')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Обновен на')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('availability_status_id')->relationship('availabilityStatus', 'name')->label('Наличност')->searchable()->preload(),
                SelectFilter::make('workflow_status')
                    ->label('Работен статус')
                    ->options(Product::workflowStatusOptions()),
                SelectFilter::make('stock_status')
                    ->label('Статус на наличност')
                    ->options(self::stockStatusOptions()),
                SelectFilter::make('category')->label('Категория')->relationship('category', 'name')->searchable()->preload(),
                SelectFilter::make('brand')->label('Марка')->relationship('brand', 'name')->searchable()->preload(),
                SelectFilter::make('supplier')->label('Вносител')->relationship('supplier', 'company_name')->searchable()->preload(),
                TernaryFilter::make('active')->label('Активен'),
                TernaryFilter::make('featured')->label('Препоръчан'),
                TernaryFilter::make('new_product')->label('Нов продукт'),
                TernaryFilter::make('bestseller')->label('Бестселър'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ActionGroup::make([
                    RestoreAction::make()->label('Възстановяване'),
                    ForceDeleteAction::make()->label('Изтрий завинаги'),
                ])
                    ->icon(Heroicon::EllipsisVertical)
                    ->tooltip('Допълнителни действия'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('assignAvailability')
                        ->label('Задай наличност')
                        ->visible(fn (): bool => (bool) auth()->user()?->canEditProductStock())
                        ->authorize(fn (): bool => (bool) auth()->user()?->canEditProductStock())
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
            '<svg xmlns="http://www.w3.org/2000/svg" width="42" height="42" viewBox="0 0 42 42"><rect width="42" height="42" rx="6" fill="#f3f4f6"/><path d="M12 29h18l-6-8-4 5-3-3-5 6Z" fill="#9ca3af"/><circle cx="16" cy="16" r="3" fill="#d1d5db"/></svg>'
        );
    }

    protected static function specificationQuality(Product $record): ProductSpecificationQualityResult
    {
        return app(ProductSpecificationQualityService::class)->evaluate($record);
    }

    protected static function specificationQualityTooltip(Product $record): string
    {
        $result = self::specificationQuality($record);
        $missing = $result->missingAttributeSummary();

        if ($missing === '') {
            return 'Спецификациите са попълнени според категорийния шаблон.';
        }

        return 'Липсват: '.$missing;
    }

    protected static function availabilityWithQuantity(Product $record, ?string $state): string
    {
        $availability = filled($state)
            ? self::availabilityLabel($state)
            : self::stockStatusOptions()[$record->stock_status] ?? 'Неизвестен';

        return $availability.' · '.(int) $record->quantity;
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

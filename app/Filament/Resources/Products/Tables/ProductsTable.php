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
            ->columns([
                ImageColumn::make('thumbnail')
                    ->label('Image')
                    ->state(fn (Product $record): ?string => $record->thumbnailUrl())
                    ->defaultImageUrl(self::placeholderImageUrl())
                    ->size(56)
                    ->square()
                    ->url(fn (Product $record): ?string => $record->thumbnailUrl())
                    ->openUrlInNewTab()
                    ->toggleable(),
                TextColumn::make('sku')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable()->limit(45),
                TextColumn::make('workflow_status')
                    ->label('Workflow')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Product::workflowStatusLabel($state))
                    ->color(fn (?string $state): string => match ($state) {
                        Product::WORKFLOW_PUBLISHED => 'success',
                        Product::WORKFLOW_APPROVED => 'info',
                        Product::WORKFLOW_PENDING_REVIEW => 'warning',
                        Product::WORKFLOW_CHANGES_REQUESTED => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('active_quality_flag_assignments_count')
                    ->label('Quality flags')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray')
                    ->toggleable(),
                TextColumn::make('category.name')->sortable()->toggleable(),
                TextColumn::make('brand.name')->sortable()->toggleable(),
                TextColumn::make('price')->money(Product::CATALOG_CURRENCY)->sortable(),
                TextColumn::make('promo_price')->money(Product::CATALOG_CURRENCY)->sortable()->toggleable(),
                TextColumn::make('quantity')->sortable(),
                TextColumn::make('reserved_quantity')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('availabilityStatus.name')->label('Availability')->badge()->sortable(),
                TextColumn::make('stock_status')->badge()->sortable()->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('manual_override')->boolean()->toggleable(),
                IconColumn::make('active')->boolean(),
                IconColumn::make('featured')->boolean(),
                IconColumn::make('new_product')->boolean()->toggleable(),
                IconColumn::make('bestseller')->boolean()->toggleable(),
                TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('availability_status_id')->relationship('availabilityStatus', 'name')->label('Availability')->searchable()->preload(),
                SelectFilter::make('workflow_status')
                    ->label('Workflow')
                    ->options(Product::workflowStatusOptions()),
                SelectFilter::make('stock_status'),
                SelectFilter::make('category')->relationship('category', 'name')->searchable()->preload(),
                SelectFilter::make('brand')->relationship('brand', 'name')->searchable()->preload(),
                TernaryFilter::make('active'),
                TernaryFilter::make('featured'),
                TernaryFilter::make('new_product'),
                TernaryFilter::make('bestseller'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                RestoreAction::make(),
                ForceDeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('assignAvailability')
                        ->label('Assign availability')
                        ->form([
                            Select::make('availability_status_id')
                                ->label('Availability status')
                                ->options(fn () => AvailabilityStatus::query()->active()->ordered()->pluck('name', 'id'))
                                ->required(),
                            Select::make('manual_override')
                                ->options([1 => 'Manual override on', 0 => 'Manual override off'])
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
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                ]),
            ]);
    }

    protected static function placeholderImageUrl(): string
    {
        return 'data:image/svg+xml;utf8,'.rawurlencode(
            '<svg xmlns="http://www.w3.org/2000/svg" width="56" height="56" viewBox="0 0 56 56"><rect width="56" height="56" rx="6" fill="#f3f4f6"/><path d="M17 37h22l-7-9-5 6-3-4-7 7Z" fill="#9ca3af"/><circle cx="21" cy="21" r="4" fill="#d1d5db"/></svg>'
        );
    }
}

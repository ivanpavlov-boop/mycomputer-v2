<?php

namespace App\Filament\Resources\SupplierProducts\Tables;

use App\Jobs\SyncProductJob;
use App\Models\SupplierProduct;
use App\Services\Products\ProductSyncService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SupplierProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('supplier.company_name')->searchable()->sortable(),
                TextColumn::make('feed.feed_name')->searchable()->toggleable(),
                TextColumn::make('supplier_sku')->searchable()->sortable(),
                TextColumn::make('ean')->searchable()->toggleable(),
                TextColumn::make('mpn')->searchable()->toggleable(),
                TextColumn::make('product.sku')->label('Matched SKU')->searchable()->toggleable(),
                TextColumn::make('name')->searchable()->limit(45),
                TextColumn::make('brand_name')->searchable()->toggleable(),
                TextColumn::make('price')->money('BGN')->sortable(),
                TextColumn::make('quantity')->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('received_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('supplier')->relationship('supplier', 'company_name')->searchable()->preload(),
                SelectFilter::make('feed')->relationship('feed', 'feed_name')->searchable()->preload(),
                SelectFilter::make('status')->options([
                    'new' => 'New',
                    'mapped' => 'Mapped',
                    'ignored' => 'Ignored',
                    'error' => 'Error',
                ]),
            ])
            ->recordActions([
                Action::make('syncNow')
                    ->label('Sync now')
                    ->icon('heroicon-o-bolt')
                    ->action(function (SupplierProduct $record, ProductSyncService $syncService): void {
                        $syncService->sync($record);

                        Notification::make()
                            ->title('Supplier product synced')
                            ->success()
                            ->send();
                    }),
                Action::make('queueSync')
                    ->label('Queue sync')
                    ->icon('heroicon-o-arrow-path')
                    ->action(function (SupplierProduct $record): void {
                        SyncProductJob::dispatch($record->id);

                        Notification::make()
                            ->title('Product sync queued')
                            ->success()
                            ->send();
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

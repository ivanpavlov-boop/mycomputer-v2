<?php

namespace App\Filament\Widgets;

use App\Models\SupplierFeed;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class RecentSupplierFeeds extends TableWidget
{
    protected static ?string $heading = 'Recent Supplier Feeds';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => SupplierFeed::query()->with('supplier')->latest('last_sync_at'))
            ->columns([
                TextColumn::make('feed_name')->searchable(),
                TextColumn::make('supplier.company_name')->label('Supplier')->searchable(),
                TextColumn::make('feed_type')->badge(),
                TextColumn::make('update_interval')->badge(),
                TextColumn::make('last_sync_at')->dateTime()->sortable(),
                TextColumn::make('status')->badge(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                //
            ])
            ->recordActions([
                //
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                ]),
            ]);
    }
}

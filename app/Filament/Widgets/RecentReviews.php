<?php

namespace App\Filament\Widgets;

use App\Models\ProductReview;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentReviews extends TableWidget
{
    protected static ?string $heading = 'Recent Reviews';

    public function table(Table $table): Table
    {
        return $table
            ->query(ProductReview::query()->with('product')->latest()->limit(5))
            ->columns([
                TextColumn::make('product.name')->limit(32),
                TextColumn::make('customer_name'),
                TextColumn::make('rating')->badge(),
                TextColumn::make('status')->badge(),
                TextColumn::make('created_at')->dateTime(),
            ]);
    }
}

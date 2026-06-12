<?php

namespace App\Filament\Resources\ProductSyncLogs\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductSyncLogForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Sync log')
                    ->schema([
                        Grid::make(3)->schema([
                            Select::make('product_id')->relationship('product', 'name')->searchable()->preload(),
                            Select::make('supplier_id')->relationship('supplier', 'company_name')->searchable()->preload(),
                            Select::make('supplier_product_id')->relationship('supplierProduct', 'name')->searchable()->preload(),
                            TextInput::make('match_type'),
                            TextInput::make('strategy'),
                            TextInput::make('action'),
                            TextInput::make('status'),
                        ]),
                        Textarea::make('message')->rows(3)->columnSpanFull(),
                        KeyValue::make('before_data')->columnSpanFull(),
                        KeyValue::make('after_data')->columnSpanFull(),
                        KeyValue::make('context')->columnSpanFull(),
                    ]),
            ]);
    }
}

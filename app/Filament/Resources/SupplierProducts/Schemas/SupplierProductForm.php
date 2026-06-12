<?php

namespace App\Filament\Resources\SupplierProducts\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SupplierProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Supplier product')
                    ->schema([
                        Grid::make(3)->schema([
                            Select::make('supplier_id')
                                ->relationship('supplier', 'company_name')
                                ->searchable()
                                ->preload()
                                ->required(),
                            Select::make('supplier_feed_id')
                                ->relationship('feed', 'feed_name')
                                ->searchable()
                                ->preload(),
                            Select::make('product_id')
                                ->relationship('product', 'name')
                                ->searchable()
                                ->preload(),
                            TextInput::make('supplier_sku')->maxLength(255),
                            TextInput::make('ean')->maxLength(255),
                            TextInput::make('mpn')->maxLength(255),
                            TextInput::make('name')->maxLength(255),
                            TextInput::make('brand_name')->maxLength(255),
                            TextInput::make('category_name')->maxLength(255),
                            TextInput::make('price')->numeric()->prefix('BGN'),
                            TextInput::make('quantity')->numeric(),
                            TextInput::make('currency')->maxLength(3)->default('BGN'),
                            TextInput::make('payload_hash')->required()->maxLength(255),
                            DateTimePicker::make('received_at')->required(),
                            Select::make('status')
                                ->options([
                                    'new' => 'New',
                                    'mapped' => 'Mapped',
                                    'ignored' => 'Ignored',
                                    'error' => 'Error',
                                ])
                                ->default('new')
                                ->required(),
                        ]),
                        Textarea::make('mapping_notes')->rows(3)->columnSpanFull(),
                    ]),
                Section::make('Raw feed data')
                    ->schema([
                        KeyValue::make('raw_data')
                            ->keyLabel('Feed field')
                            ->valueLabel('Raw value')
                            ->required(),
                    ])
                    ->collapsible(),
            ]);
    }
}

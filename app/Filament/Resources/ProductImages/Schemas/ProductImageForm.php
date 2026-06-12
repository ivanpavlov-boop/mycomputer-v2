<?php

namespace App\Filament\Resources\ProductImages\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductImageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Image')
                    ->schema([
                        Select::make('product_id')
                            ->relationship('product', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        FileUpload::make('path')
                            ->disk('public')
                            ->directory('products')
                            ->image()
                            ->required(),
                        Grid::make(3)->schema([
                            TextInput::make('alt_text')->maxLength(255),
                            TextInput::make('sort_order')->numeric()->default(0),
                            Toggle::make('is_primary')->default(false),
                        ]),
                    ]),
            ]);
    }
}

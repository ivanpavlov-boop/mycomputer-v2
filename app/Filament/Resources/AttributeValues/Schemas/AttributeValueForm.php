<?php

namespace App\Filament\Resources\AttributeValues\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class AttributeValueForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Опция')
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('product_attribute_id')
                                ->label('Характеристика')
                                ->relationship('attribute', 'name')
                                ->searchable()
                                ->preload()
                                ->required(),
                            TextInput::make('value')
                                ->label('Етикет на български')
                                ->required()
                                ->maxLength(255)
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug($state ?? ''))),
                            TextInput::make('value_translations.en')
                                ->label('Етикет на английски')
                                ->maxLength(255),
                            TextInput::make('slug')
                                ->label('Стойност/slug')
                                ->required()
                                ->maxLength(255),
                            TextInput::make('sort_order')
                                ->label('Ред на сортиране')
                                ->numeric()
                                ->default(0),
                        ]),
                        Toggle::make('is_active')
                            ->label('Активна')
                            ->default(true),
                    ]),
            ]);
    }
}

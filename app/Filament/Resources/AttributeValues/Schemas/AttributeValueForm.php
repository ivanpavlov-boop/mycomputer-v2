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
                    ->description('Контролирана опция за характеристики от тип избор или множествен избор. Не създава стойности по продукти автоматично.')
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('product_attribute_id')
                                ->label('Характеристика')
                                ->relationship('attribute', 'name')
                                ->searchable()
                                ->preload()
                                ->required(),
                            TextInput::make('value')
                                ->label('Етикет BG')
                                ->required()
                                ->maxLength(255)
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug($state ?? ''))),
                            TextInput::make('value_translations.en')
                                ->label('Етикет EN')
                                ->maxLength(255),
                            TextInput::make('slug')
                                ->label('Стойност')
                                ->helperText('Стабилна стойност за тази опция. Трябва да е уникална в рамките на избраната характеристика.')
                                ->required()
                                ->maxLength(255)
                                ->dehydrateStateUsing(fn (?string $state): string => Str::slug((string) $state)),
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

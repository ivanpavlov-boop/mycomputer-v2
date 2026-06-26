<?php

namespace App\Filament\Resources\ProductAttributes\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ProductAttributeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Attribute')
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('attribute_group_id')
                                ->relationship('group', 'name')
                                ->searchable()
                                ->preload()
                                ->required(),
                            Select::make('type')
                                ->options([
                                    'select' => 'Select',
                                    'text' => 'Text',
                                    'number' => 'Number',
                                    'boolean' => 'Boolean',
                                ])
                                ->default('select')
                                ->required(),
                            TextInput::make('name')
                                ->required()
                                ->maxLength(255)
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug($state ?? ''))),
                            TextInput::make('slug')->required()->unique(ignoreRecord: true)->maxLength(255),
                            TextInput::make('unit')->maxLength(255),
                            TextInput::make('sort_order')->numeric()->default(0),
                        ]),
                        Toggle::make('is_filterable')->default(true),
                        Toggle::make('is_required')->default(false),
                        Toggle::make('is_active')->default(true),
                    ]),
                Section::make('English localization')
                    ->description('Translate the attribute label only. Technical units and numeric values remain shared.')
                    ->schema([
                        TextInput::make('name_translations.en')
                            ->label('English attribute label')
                            ->maxLength(255),
                    ])
                    ->collapsible()
                    ->collapsed(),
            ]);
    }
}

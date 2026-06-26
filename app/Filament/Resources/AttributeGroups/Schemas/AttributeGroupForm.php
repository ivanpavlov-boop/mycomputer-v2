<?php

namespace App\Filament\Resources\AttributeGroups\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class AttributeGroupForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Attribute group')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('name')
                                ->required()
                                ->maxLength(255)
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug($state ?? ''))),
                            TextInput::make('slug')->required()->unique(ignoreRecord: true)->maxLength(255),
                            TextInput::make('sort_order')->numeric()->default(0),
                        ]),
                        Textarea::make('description')->rows(3)->columnSpanFull(),
                        Toggle::make('is_active')->default(true),
                    ]),
                Section::make('English localization')
                    ->description('Translate labels for storefront filters; numeric values and units stay technical.')
                    ->schema([
                        TextInput::make('name_translations.en')
                            ->label('English group label')
                            ->maxLength(255),
                        Textarea::make('description_translations.en')
                            ->label('English description')
                            ->rows(2),
                    ])
                    ->collapsible()
                    ->collapsed(),
            ]);
    }
}

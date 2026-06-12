<?php

namespace App\Filament\Resources\Categories\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Category')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('name')
                                ->required()
                                ->maxLength(255)
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug($state ?? ''))),
                            TextInput::make('slug')
                                ->required()
                                ->unique(ignoreRecord: true)
                                ->maxLength(255),
                            Select::make('parent_id')
                                ->relationship('parent', 'name')
                                ->searchable()
                                ->preload()
                                ->nullable(),
                            TextInput::make('sort_order')
                                ->numeric()
                                ->default(0),
                            TextInput::make('icon')->maxLength(255),
                        ]),
                        Textarea::make('description')->rows(3)->columnSpanFull(),
                        FileUpload::make('image_path')
                            ->disk('public')
                            ->directory('categories')
                            ->image(),
                        Toggle::make('is_active')->default(true),
                    ]),
                Section::make('SEO')
                    ->schema([
                        TextInput::make('meta_title')->maxLength(255),
                        Textarea::make('meta_description')->rows(2),
                        Textarea::make('meta_keywords')->rows(2),
                    ])
                    ->collapsible(),
            ]);
    }
}

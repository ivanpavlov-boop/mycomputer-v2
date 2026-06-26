<?php

namespace App\Filament\Resources\Brands\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class BrandForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Brand')
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
                            TextInput::make('website')->url()->maxLength(255),
                            TextInput::make('sort_order')->numeric()->default(0),
                        ]),
                        FileUpload::make('logo_path')
                            ->disk('public')
                            ->directory('brands')
                            ->image(),
                        Textarea::make('description')->rows(3)->columnSpanFull(),
                        Toggle::make('is_active')->default(true),
                    ]),
                Section::make('English localization')
                    ->description('English brand content is optional. Technical brand identifiers remain shared.')
                    ->schema([
                        Textarea::make('description_translations.en')
                            ->label('English description')
                            ->rows(3)
                            ->columnSpanFull(),
                        Grid::make(2)->schema([
                            TextInput::make('meta_title_translations.en')
                                ->label('English SEO title')
                                ->maxLength(255),
                            Textarea::make('meta_description_translations.en')
                                ->label('English SEO description')
                                ->rows(2),
                        ]),
                    ])
                    ->collapsible()
                    ->collapsed(),
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

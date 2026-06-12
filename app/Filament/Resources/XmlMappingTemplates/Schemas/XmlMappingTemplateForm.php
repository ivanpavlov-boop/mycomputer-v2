<?php

namespace App\Filament\Resources\XmlMappingTemplates\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class XmlMappingTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Template')
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('supplier_id')
                                ->relationship('supplier', 'company_name')
                                ->searchable()
                                ->preload()
                                ->helperText('Leave empty to make this template available to all suppliers.'),
                            TextInput::make('name')->required()->maxLength(255),
                            TextInput::make('root_path')
                                ->required()
                                ->default('products.product')
                                ->helperText('Dot path or XPath to the repeating product node. Example: products.product'),
                        ]),
                        Textarea::make('description')->rows(2)->columnSpanFull(),
                        Toggle::make('is_active')->default(true),
                    ]),
                Section::make('Field mapping')
                    ->schema([
                        KeyValue::make('field_map')
                            ->keyLabel('Supplier product field')
                            ->valueLabel('XML path')
                            ->required()
                            ->helperText('Examples: supplier_sku => code, name => name, price => price, quantity => stock'),
                    ]),
                Section::make('Validation and defaults')
                    ->schema([
                        KeyValue::make('validation_rules')
                            ->keyLabel('Field')
                            ->valueLabel('Rules')
                            ->helperText('Examples: supplier_sku => required, price => nullable|numeric'),
                        KeyValue::make('defaults')
                            ->keyLabel('Field')
                            ->valueLabel('Default value'),
                    ])
                    ->collapsible(),
            ]);
    }
}

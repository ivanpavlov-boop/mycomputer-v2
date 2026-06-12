<?php

namespace App\Filament\Resources\SupplierFeeds\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SupplierFeedForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Feed')
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('supplier_id')
                                ->relationship('supplier', 'company_name')
                                ->searchable()
                                ->preload()
                                ->required(),
                            TextInput::make('feed_name')->required()->maxLength(255),
                            Select::make('feed_type')
                                ->options([
                                    'xml' => 'XML',
                                    'csv' => 'CSV',
                                    'api' => 'API',
                                ])
                                ->default('xml')
                                ->required(),
                            TextInput::make('feed_url')->url()->required()->maxLength(255),
                            TextInput::make('username')->maxLength(255),
                            TextInput::make('password')
                                ->password()
                                ->revealable()
                                ->dehydrated(fn (?string $state): bool => filled($state)),
                            Select::make('update_interval')
                                ->options([
                                    'manual' => 'Manual',
                                    'hourly' => 'Hourly',
                                    '6h' => 'Every 6 hours',
                                    '12h' => 'Every 12 hours',
                                    'daily' => 'Daily',
                                ])
                                ->default('manual')
                                ->required(),
                            Select::make('status')
                                ->options([
                                    'active' => 'Active',
                                    'paused' => 'Paused',
                                    'failed' => 'Failed',
                                ])
                                ->default('active')
                                ->required(),
                        ]),
                    ]),
                Section::make('Import mapping')
                    ->schema([
                        KeyValue::make('mapping')
                            ->keyLabel('Internal field')
                            ->valueLabel('Feed path'),
                    ]),
                Section::make('Last sync')
                    ->schema([
                        Grid::make(2)->schema([
                            DateTimePicker::make('last_sync_at'),
                        ]),
                        Textarea::make('last_error')->rows(3)->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(),
            ]);
    }
}

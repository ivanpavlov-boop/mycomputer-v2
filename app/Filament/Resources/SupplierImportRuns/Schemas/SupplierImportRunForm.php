<?php

namespace App\Filament\Resources\SupplierImportRuns\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SupplierImportRunForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Run summary')
                ->schema([
                    Grid::make(3)->schema([
                        Select::make('supplier_id')
                            ->relationship('supplier', 'company_name')
                            ->disabled(),
                        Select::make('supplier_feed_id')
                            ->relationship('feed', 'feed_name')
                            ->disabled(),
                        TextInput::make('status')->disabled(),
                        TextInput::make('trigger_type')->disabled(),
                        TextInput::make('import_type')->disabled(),
                        TextInput::make('duration_seconds')->disabled(),
                        DateTimePicker::make('started_at')->disabled(),
                        DateTimePicker::make('finished_at')->disabled(),
                    ]),
                ]),
            Section::make('Metrics')
                ->schema([
                    Grid::make(4)->schema([
                        TextInput::make('products_seen')->numeric()->disabled(),
                        TextInput::make('products_created')->numeric()->disabled(),
                        TextInput::make('products_updated')->numeric()->disabled(),
                        TextInput::make('products_skipped')->numeric()->disabled(),
                        TextInput::make('products_failed')->numeric()->disabled(),
                        TextInput::make('products_out_of_stock')->numeric()->disabled(),
                        TextInput::make('attributes_mapped')->numeric()->disabled(),
                        TextInput::make('attributes_unmapped')->numeric()->disabled(),
                        TextInput::make('availability_mapped')->numeric()->disabled(),
                        TextInput::make('availability_unmapped')->numeric()->disabled(),
                    ]),
                ]),
            Section::make('Warnings and errors')
                ->schema([
                    Textarea::make('warnings')
                        ->formatStateUsing(fn ($state): string => json_encode($state ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                        ->disabled()
                        ->rows(5),
                    Textarea::make('errors')
                        ->formatStateUsing(fn ($state): string => json_encode($state ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                        ->disabled()
                        ->rows(5),
                ])
                ->columns(2),
            Section::make('Report')
                ->schema([
                    Textarea::make('report')
                        ->formatStateUsing(fn ($state): string => json_encode($state ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                        ->disabled()
                        ->rows(12),
                ])
                ->collapsible(),
        ]);
    }
}

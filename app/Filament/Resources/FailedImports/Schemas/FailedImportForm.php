<?php

namespace App\Filament\Resources\FailedImports\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FailedImportForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Failed import')
                    ->schema([
                        Grid::make(3)->schema([
                            Select::make('import_job_id')->relationship('importJob', 'id')->searchable(),
                            Select::make('supplier_id')->relationship('supplier', 'company_name')->searchable()->preload()->required(),
                            Select::make('supplier_feed_id')->relationship('feed', 'feed_name')->searchable()->preload(),
                            TextInput::make('supplier_sku'),
                            TextInput::make('row_number')->numeric(),
                            Select::make('error_type')->options([
                                'validation' => 'Validation',
                                'xml' => 'XML',
                                'network' => 'Network',
                                'runtime' => 'Runtime',
                            ])->default('validation')->required(),
                        ]),
                        Textarea::make('error_message')->required()->rows(3)->columnSpanFull(),
                        KeyValue::make('raw_data')->columnSpanFull(),
                    ]),
            ]);
    }
}

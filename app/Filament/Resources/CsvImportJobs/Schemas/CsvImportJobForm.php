<?php

namespace App\Filament\Resources\CsvImportJobs\Schemas;

use App\Support\Catalog\ProductCsvSchema;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CsvImportJobForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('CSV import')
                ->schema([
                    Grid::make(3)->schema([
                        Select::make('type')
                            ->options(ProductCsvSchema::importTypeOptions())
                            ->required(),
                        Select::make('mode')
                            ->options(ProductCsvSchema::modeOptions())
                            ->default('create-or-update')
                            ->required(),
                        Select::make('status')
                            ->options([
                                'pending' => 'Pending',
                                'previewed' => 'Previewed',
                                'running' => 'Running',
                                'completed' => 'Completed',
                                'completed_with_errors' => 'Completed with errors',
                                'failed' => 'Failed',
                            ])
                            ->default('pending')
                            ->required(),
                        FileUpload::make('file_path')
                            ->disk('imports')
                            ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                            ->maxSize(10240)
                            ->required(),
                        TextInput::make('original_filename')->maxLength(255),
                        TextInput::make('total_rows')->numeric()->default(0),
                        TextInput::make('processed_rows')->numeric()->default(0),
                        TextInput::make('failed_rows')->numeric()->default(0),
                    ]),
                    KeyValue::make('mapping')
                        ->keyLabel('CSV column')
                        ->valueLabel('System column')
                        ->columnSpanFull(),
                    Textarea::make('error_message')->rows(3)->columnSpanFull(),
                ]),
            Section::make('Preview')
                ->schema([
                    KeyValue::make('preview_data')
                        ->keyLabel('Row')
                        ->valueLabel('Mapped data'),
                ])
                ->collapsible()
                ->collapsed(),
        ]);
    }
}

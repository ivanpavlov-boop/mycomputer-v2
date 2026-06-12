<?php

namespace App\Filament\Resources\ImportJobs\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ImportJobForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Import job')
                    ->schema([
                        Grid::make(3)->schema([
                            Select::make('supplier_id')
                                ->relationship('supplier', 'company_name')
                                ->searchable()
                                ->preload()
                                ->required(),
                            Select::make('supplier_feed_id')
                                ->relationship('feed', 'feed_name')
                                ->searchable()
                                ->preload()
                                ->required(),
                            Select::make('xml_mapping_template_id')
                                ->relationship('mappingTemplate', 'name')
                                ->searchable()
                                ->preload()
                                ->required(),
                            Select::make('type')->options(['xml' => 'XML'])->default('xml')->required(),
                            Select::make('mode')
                                ->options([
                                    'preview' => 'Preview',
                                    'manual' => 'Manual',
                                    'scheduled' => 'Scheduled',
                                    'queued' => 'Queued',
                                ])
                                ->default('manual')
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
                            TextInput::make('preview_limit')->numeric()->default(20)->required(),
                            TextInput::make('total_rows')->numeric()->default(0),
                            TextInput::make('processed_rows')->numeric()->default(0),
                            TextInput::make('failed_rows')->numeric()->default(0),
                            DateTimePicker::make('started_at'),
                            DateTimePicker::make('finished_at'),
                        ]),
                        Textarea::make('error_message')->rows(3)->columnSpanFull(),
                    ]),
                Section::make('Preview data')
                    ->schema([
                        KeyValue::make('preview_data')
                            ->keyLabel('Row')
                            ->valueLabel('Mapped preview'),
                    ])
                    ->collapsible()
                    ->collapsed(),
            ]);
    }
}

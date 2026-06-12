<?php

namespace App\Filament\Resources\ImportHistories\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ImportHistoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Log entry')
                    ->schema([
                        Grid::make(3)->schema([
                            Select::make('import_job_id')->relationship('importJob', 'id')->searchable(),
                            Select::make('supplier_id')->relationship('supplier', 'company_name')->searchable()->preload()->required(),
                            Select::make('supplier_feed_id')->relationship('feed', 'feed_name')->searchable()->preload(),
                            TextInput::make('event')->required(),
                            Select::make('level')->options([
                                'info' => 'Info',
                                'warning' => 'Warning',
                                'error' => 'Error',
                            ])->default('info')->required(),
                        ]),
                        Textarea::make('message')->rows(3)->columnSpanFull(),
                        KeyValue::make('context')->columnSpanFull(),
                    ]),
            ]);
    }
}

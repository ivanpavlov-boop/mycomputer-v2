<?php

namespace App\Filament\Resources\ImportJobs\Tables;

use App\Jobs\ProcessXmlSupplierFeed;
use App\Models\ImportJob;
use App\Services\Imports\XmlImportEngine;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ImportJobsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('supplier.company_name')->searchable()->sortable(),
                TextColumn::make('feed.feed_name')->searchable()->sortable(),
                TextColumn::make('mappingTemplate.name')->label('Mapping')->searchable()->toggleable(),
                TextColumn::make('mode')->badge()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('total_rows')->sortable(),
                TextColumn::make('processed_rows')->sortable(),
                TextColumn::make('failed_rows')->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('supplier')->relationship('supplier', 'company_name')->searchable()->preload(),
                SelectFilter::make('status')->options([
                    'pending' => 'Pending',
                    'previewed' => 'Previewed',
                    'running' => 'Running',
                    'completed' => 'Completed',
                    'completed_with_errors' => 'Completed with errors',
                    'failed' => 'Failed',
                ]),
            ])
            ->recordActions([
                Action::make('preview')
                    ->icon('heroicon-o-eye')
                    ->action(function (ImportJob $record, XmlImportEngine $engine): void {
                        $preview = $engine->preview($record->feed, $record->mappingTemplate, $record->preview_limit);

                        $record->update([
                            'mode' => 'preview',
                            'status' => 'previewed',
                            'preview_data' => collect($preview)
                                ->mapWithKeys(fn (array $row, int $index): array => [
                                    'row_'.($index + 1) => json_encode($row, JSON_THROW_ON_ERROR),
                                ])
                                ->all(),
                        ]);

                        Notification::make()
                            ->title('Import preview generated')
                            ->success()
                            ->send();
                    }),
                Action::make('queue')
                    ->label('Queue sync')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->action(function (ImportJob $record): void {
                        $record->update([
                            'mode' => 'queued',
                            'status' => 'pending',
                        ]);

                        ProcessXmlSupplierFeed::dispatch($record->id);

                        Notification::make()
                            ->title('XML import queued')
                            ->success()
                            ->send();
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

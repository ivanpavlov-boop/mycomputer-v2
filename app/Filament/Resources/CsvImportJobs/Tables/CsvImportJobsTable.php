<?php

namespace App\Filament\Resources\CsvImportJobs\Tables;

use App\Jobs\ProcessCsvImportJob;
use App\Models\CsvImportJob;
use App\Services\Csv\CsvImportService;
use App\Support\Catalog\ProductCsvSchema;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\URL;

class CsvImportJobsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('type')->badge()->sortable(),
                TextColumn::make('mode')->badge()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('original_filename')->searchable()->limit(35),
                TextColumn::make('total_rows')->sortable(),
                TextColumn::make('processed_rows')->sortable(),
                TextColumn::make('failed_rows')->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')->options(ProductCsvSchema::importTypeOptions()),
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
                    ->action(function (CsvImportJob $record, CsvImportService $service): void {
                        $service->preview($record);

                        Notification::make()->title('CSV preview generated')->success()->send();
                    }),
                Action::make('queue')
                    ->label('Queue import')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->action(function (CsvImportJob $record): void {
                        $record->update(['status' => 'pending']);
                        ProcessCsvImportJob::dispatch($record->id);

                        Notification::make()->title('CSV import queued')->success()->send();
                    }),
                Action::make('downloadFailures')
                    ->label('Failed rows')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (CsvImportJob $record): string => URL::signedRoute('csv.import-failures.download', $record))
                    ->openUrlInNewTab()
                    ->visible(fn (CsvImportJob $record): bool => $record->failed_rows > 0),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

<?php

namespace App\Filament\Resources\CsvExportJobs\Tables;

use App\Jobs\ProcessCsvExportJob;
use App\Models\CsvExportJob;
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

class CsvExportJobsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('type')->badge()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('total_rows')->sortable(),
                TextColumn::make('processed_rows')->sortable(),
                TextColumn::make('file_path')->limit(35)->toggleable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')->options(ProductCsvSchema::exportTypeOptions()),
                SelectFilter::make('status')->options([
                    'pending' => 'Pending',
                    'running' => 'Running',
                    'completed' => 'Completed',
                    'failed' => 'Failed',
                ]),
            ])
            ->recordActions([
                Action::make('queue')
                    ->label('Queue export')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->action(function (CsvExportJob $record): void {
                        $record->update(['status' => 'pending']);
                        ProcessCsvExportJob::dispatch($record->id);

                        Notification::make()->title('CSV export queued')->success()->send();
                    }),
                Action::make('download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (CsvExportJob $record): string => URL::signedRoute('csv.exports.download', $record))
                    ->openUrlInNewTab()
                    ->visible(fn (CsvExportJob $record): bool => filled($record->file_path)),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

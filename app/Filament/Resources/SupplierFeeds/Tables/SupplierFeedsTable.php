<?php

namespace App\Filament\Resources\SupplierFeeds\Tables;

use App\Jobs\ProcessXmlSupplierFeed;
use App\Models\ImportJob;
use App\Models\SupplierFeed;
use App\Models\XmlMappingTemplate;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SupplierFeedsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('feed_name')->searchable()->sortable(),
                TextColumn::make('supplier.company_name')->searchable()->sortable(),
                TextColumn::make('feed_type')->badge()->sortable(),
                TextColumn::make('update_interval')->badge()->sortable(),
                TextColumn::make('last_sync_at')->dateTime()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
            ])
            ->filters([
                SelectFilter::make('feed_type')->options([
                    'xml' => 'XML',
                    'csv' => 'CSV',
                    'api' => 'API',
                ]),
                SelectFilter::make('supplier')->relationship('supplier', 'company_name')->searchable()->preload(),
                SelectFilter::make('status')->options([
                    'active' => 'Active',
                    'paused' => 'Paused',
                    'failed' => 'Failed',
                ]),
            ])
            ->recordActions([
                Action::make('queueXmlSync')
                    ->label('Queue XML sync')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (SupplierFeed $record): bool => $record->feed_type === 'xml')
                    ->requiresConfirmation()
                    ->action(function (SupplierFeed $record): void {
                        $template = XmlMappingTemplate::query()
                            ->where(function ($query) use ($record): void {
                                $query
                                    ->where('supplier_id', $record->supplier_id)
                                    ->orWhereNull('supplier_id');
                            })
                            ->where('is_active', true)
                            ->latest('supplier_id')
                            ->first();

                        if (! $template) {
                            Notification::make()
                                ->title('No active XML mapping template found')
                                ->danger()
                                ->send();

                            return;
                        }

                        $job = ImportJob::query()->create([
                            'supplier_id' => $record->supplier_id,
                            'supplier_feed_id' => $record->id,
                            'xml_mapping_template_id' => $template->id,
                            'type' => 'xml',
                            'mode' => 'manual',
                            'status' => 'pending',
                        ]);

                        ProcessXmlSupplierFeed::dispatch($job->id);

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

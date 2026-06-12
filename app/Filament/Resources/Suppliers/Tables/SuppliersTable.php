<?php

namespace App\Filament\Resources\Suppliers\Tables;

use App\Models\Supplier;
use App\Services\Suppliers\SupplierImportOrchestrator;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SuppliersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('company_name')->searchable()->sortable(),
                TextColumn::make('contact_person')->searchable()->toggleable(),
                TextColumn::make('email')->searchable()->toggleable(),
                TextColumn::make('phone')->toggleable(),
                TextColumn::make('priority')->sortable(),
                TextColumn::make('sync_strategy')->badge()->sortable(),
                TextColumn::make('schedule_type')->badge()->sortable(),
                TextColumn::make('next_import_at')->dateTime()->sortable(),
                TextColumn::make('last_import_at')->dateTime()->sortable()->toggleable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('feeds_count')->counts('feeds')->label('Feeds')->sortable(),
                TextColumn::make('supplier_products_count')->counts('supplierProducts')->label('Raw Products')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'active' => 'Active',
                    'inactive' => 'Inactive',
                    'on_hold' => 'On hold',
                ]),
                SelectFilter::make('schedule_type')->options([
                    'twice_daily' => 'Twice daily',
                    'daily' => 'Daily',
                    'hourly' => 'Hourly',
                    'manual_only' => 'Manual only',
                    'custom' => 'Custom',
                ]),
            ])
            ->recordActions([
                Action::make('run_import')
                    ->label('Run import')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => (bool) auth()->user()?->can('run supplier imports'))
                    ->action(function (Supplier $record, SupplierImportOrchestrator $orchestrator): void {
                        $run = $orchestrator->dispatch($record, 'manual');

                        Notification::make()
                            ->title("Supplier import queued as run #{$run->id}")
                            ->success()
                            ->send();
                    }),
                Action::make('force_import')
                    ->label('Force import')
                    ->icon('heroicon-o-bolt')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => (bool) auth()->user()?->can('force supplier imports'))
                    ->action(function (Supplier $record, SupplierImportOrchestrator $orchestrator): void {
                        $run = $orchestrator->dispatch($record, 'force', true);

                        Notification::make()
                            ->title("Forced supplier import queued as run #{$run->id}")
                            ->warning()
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

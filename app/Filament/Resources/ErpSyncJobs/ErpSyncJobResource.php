<?php

namespace App\Filament\Resources\ErpSyncJobs;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\ErpSyncJobs\Pages\EditErpSyncJob;
use App\Filament\Resources\ErpSyncJobs\Pages\ListErpSyncJobs;
use App\Jobs\CreateErpInvoiceJob;
use App\Jobs\PullStockFromErpJob;
use App\Jobs\SyncCustomerToErpJob;
use App\Jobs\SyncOrderToErpJob;
use App\Jobs\SyncPaymentToErpJob;
use App\Models\ErpSyncJob;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class ErpSyncJobResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = ErpSyncJob::class;

    protected static ?string $permission = 'view erp logs';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static ?string $navigationLabel = 'ERP Sync Jobs';

    protected static string|UnitEnum|null $navigationGroup = 'ERP';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('provider_id')->relationship('provider', 'name')->searchable()->preload(),
            Select::make('sync_type')->options(array_combine(ErpSyncJob::SYNC_TYPES, ErpSyncJob::SYNC_TYPES))->required(),
            Select::make('entity_type')->options(array_combine(ErpSyncJob::ENTITY_TYPES, ErpSyncJob::ENTITY_TYPES))->required(),
            TextInput::make('entity_id')->numeric()->required(),
            Select::make('status')->options(array_combine(ErpSyncJob::STATUSES, ErpSyncJob::STATUSES))->required(),
            TextInput::make('external_id'),
            Textarea::make('last_error')->columnSpanFull(),
            KeyValue::make('payload')->columnSpanFull(),
            KeyValue::make('response')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('provider.name')->placeholder('None')->sortable(),
                TextColumn::make('sync_type')->badge()->sortable(),
                TextColumn::make('entity_type')->badge()->sortable(),
                TextColumn::make('entity_id')->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('attempts')->numeric()->sortable(),
                TextColumn::make('external_id')->searchable(),
                TextColumn::make('synced_at')->dateTime()->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(array_combine(ErpSyncJob::STATUSES, ErpSyncJob::STATUSES)),
                SelectFilter::make('entity_type')->options(array_combine(ErpSyncJob::ENTITY_TYPES, ErpSyncJob::ENTITY_TYPES)),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('retry')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (): bool => (bool) auth()->user()?->can('retry erp sync'))
                    ->action(fn (ErpSyncJob $record) => static::dispatchRetry($record)),
                Action::make('markSynced')->icon(Heroicon::OutlinedCheckCircle)->action(fn (ErpSyncJob $record) => $record->update(['status' => 'success', 'synced_at' => now()])),
                Action::make('skip')->icon(Heroicon::OutlinedForward)->action(fn (ErpSyncJob $record) => $record->update(['status' => 'skipped'])),
                DeleteAction::make(),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->defaultSort('created_at', 'desc');
    }

    public static function dispatchRetry(ErpSyncJob $record): void
    {
        $record->update(['status' => 'pending', 'last_error' => null]);

        match ($record->entity_type) {
            'customer' => SyncCustomerToErpJob::dispatch($record->id),
            'order' => SyncOrderToErpJob::dispatch($record->id),
            'invoice' => CreateErpInvoiceJob::dispatch($record->id),
            'payment' => SyncPaymentToErpJob::dispatch($record->id),
            'stock' => PullStockFromErpJob::dispatch($record->id),
            default => null,
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => ListErpSyncJobs::route('/'),
            'edit' => EditErpSyncJob::route('/{record}/edit'),
        ];
    }
}

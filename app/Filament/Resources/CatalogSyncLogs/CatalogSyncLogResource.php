<?php

namespace App\Filament\Resources\CatalogSyncLogs;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\CatalogSyncLogs\Pages\ListCatalogSyncLogs;
use App\Filament\Resources\CatalogSyncLogs\Pages\ViewCatalogSyncLog;
use App\Models\CatalogSyncBatch;
use App\Models\CatalogSyncLog;
use App\Models\Supplier;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class CatalogSyncLogResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = CatalogSyncLog::class;

    protected static ?string $permission = 'manage suppliers';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Catalog Sync Logs';

    protected static string|UnitEnum|null $navigationGroup = 'Supplier Imports';

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Log')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('batch.batch_uuid')->label('Batch')->copyable()->placeholder('None'),
                        TextEntry::make('action')->badge(),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('reason')->placeholder('None'),
                        TextEntry::make('supplier.company_name')->label('Supplier')->placeholder('None'),
                        TextEntry::make('supplier_product_id')->placeholder('None'),
                        TextEntry::make('product_id')->placeholder('None'),
                        TextEntry::make('created_at')->dateTime(),
                    ]),
                    TextEntry::make('error_message')->placeholder('None')->columnSpanFull(),
                ]),
            Section::make('Old values')
                ->schema([
                    TextEntry::make('old_values')
                        ->label('Old values')
                        ->state(fn (CatalogSyncLog $record): string => json_encode($record->old_values ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                        ->placeholder('{}')
                        ->columnSpanFull(),
                ]),
            Section::make('New values')
                ->schema([
                    TextEntry::make('new_values')
                        ->label('New values')
                        ->state(fn (CatalogSyncLog $record): string => json_encode($record->new_values ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                        ->placeholder('{}')
                        ->columnSpanFull(),
                ]),
            Section::make('Metadata')
                ->schema([
                    TextEntry::make('metadata')
                        ->label('Metadata')
                        ->state(fn (CatalogSyncLog $record): string => json_encode($record->metadata ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                        ->placeholder('{}')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'batch:id,batch_uuid',
            'supplier:id,company_name',
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('batch.batch_uuid')->label('Batch')->copyable()->searchable()->toggleable(),
                TextColumn::make('action')->badge()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('reason')->searchable()->toggleable(),
                TextColumn::make('supplier.company_name')->label('Supplier')->sortable()->searchable(),
                TextColumn::make('supplier_product_id')->sortable()->toggleable(),
                TextColumn::make('product_id')->sortable()->toggleable(),
                TextColumn::make('error_message')->limit(80)->searchable()->toggleable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('action')->options([
                    CatalogSyncLog::ACTION_CREATE => CatalogSyncLog::ACTION_CREATE,
                    CatalogSyncLog::ACTION_UPDATE => CatalogSyncLog::ACTION_UPDATE,
                ]),
                SelectFilter::make('status')->options([
                    CatalogSyncLog::STATUS_SUCCESS => CatalogSyncLog::STATUS_SUCCESS,
                    CatalogSyncLog::STATUS_SKIPPED => CatalogSyncLog::STATUS_SKIPPED,
                    CatalogSyncLog::STATUS_FAILED => CatalogSyncLog::STATUS_FAILED,
                ]),
                SelectFilter::make('supplier_id')
                    ->label('Supplier')
                    ->options(fn (): array => Supplier::query()->orderBy('company_name')->pluck('company_name', 'id')->all()),
                SelectFilter::make('catalog_sync_batch_id')
                    ->label('Batch')
                    ->options(fn (): array => CatalogSyncBatch::query()->latest()->limit(100)->pluck('batch_uuid', 'id')->all()),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canView(Model $record): bool
    {
        return static::canAccessResource();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCatalogSyncLogs::route('/'),
            'view' => ViewCatalogSyncLog::route('/{record}'),
        ];
    }
}

<?php

namespace App\Filament\Resources\CatalogSyncBatches;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\CatalogSyncBatches\Pages\ListCatalogSyncBatches;
use App\Filament\Resources\CatalogSyncBatches\Pages\ViewCatalogSyncBatch;
use App\Models\CatalogSyncBatch;
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

class CatalogSyncBatchResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = CatalogSyncBatch::class;

    protected static ?string $permission = null;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Catalog Sync Batches';

    protected static string|UnitEnum|null $navigationGroup = 'Supplier Imports';

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Batch')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('batch_uuid')->label('Batch UUID')->copyable(),
                        TextEntry::make('mode')->badge(),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('supplier.company_name')->label('Supplier')->placeholder('None'),
                        TextEntry::make('user.email')->label('User')->placeholder('System'),
                        TextEntry::make('selected_count'),
                        TextEntry::make('created_count'),
                        TextEntry::make('updated_count'),
                        TextEntry::make('skipped_count'),
                        TextEntry::make('failed_count'),
                        TextEntry::make('started_at')->dateTime(),
                        TextEntry::make('completed_at')->dateTime()->placeholder('Not completed'),
                    ]),
                ]),
            Section::make('Metadata')
                ->schema([
                    TextEntry::make('metadata')
                        ->label('Metadata')
                        ->state(fn (CatalogSyncBatch $record): string => json_encode($record->metadata ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                        ->placeholder('{}')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['supplier:id,company_name', 'user:id,email']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('batch_uuid')->label('Batch UUID')->copyable()->searchable()->toggleable(),
                TextColumn::make('mode')->badge()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('supplier.company_name')->label('Supplier')->sortable()->searchable(),
                TextColumn::make('user.email')->label('User')->sortable()->searchable(),
                TextColumn::make('selected_count')->numeric()->sortable(),
                TextColumn::make('created_count')->numeric()->sortable(),
                TextColumn::make('updated_count')->numeric()->sortable(),
                TextColumn::make('skipped_count')->numeric()->sortable(),
                TextColumn::make('failed_count')->numeric()->sortable(),
                TextColumn::make('started_at')->dateTime()->sortable()->toggleable(),
                TextColumn::make('completed_at')->dateTime()->sortable()->toggleable(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    CatalogSyncBatch::STATUS_RUNNING => CatalogSyncBatch::STATUS_RUNNING,
                    CatalogSyncBatch::STATUS_COMPLETED => CatalogSyncBatch::STATUS_COMPLETED,
                    CatalogSyncBatch::STATUS_PARTIAL => CatalogSyncBatch::STATUS_PARTIAL,
                    CatalogSyncBatch::STATUS_FAILED => CatalogSyncBatch::STATUS_FAILED,
                ]),
                SelectFilter::make('mode')->options([
                    CatalogSyncBatch::MODE_MANUAL_SELECTED_CREATE => CatalogSyncBatch::MODE_MANUAL_SELECTED_CREATE,
                    CatalogSyncBatch::MODE_MANUAL_SELECTED_UPDATE_PRICE_STOCK => CatalogSyncBatch::MODE_MANUAL_SELECTED_UPDATE_PRICE_STOCK,
                ]),
                SelectFilter::make('supplier_id')
                    ->label('Supplier')
                    ->options(fn (): array => Supplier::query()->orderBy('company_name')->pluck('company_name', 'id')->all()),
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
            'index' => ListCatalogSyncBatches::route('/'),
            'view' => ViewCatalogSyncBatch::route('/{record}'),
        ];
    }

    protected static function canAccessResource(): bool
    {
        return (bool) auth()->user()?->canViewAuditLogs();
    }
}

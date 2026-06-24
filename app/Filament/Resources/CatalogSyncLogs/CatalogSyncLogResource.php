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

    protected static ?string $permission = null;

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
                ->label('Field comparison')
                ->schema([
                    TextEntry::make('value_comparison')
                        ->label('Old and new values')
                        ->state(fn (CatalogSyncLog $record): string => static::formatValueComparison($record))
                        ->html()
                        ->columnSpanFull(),
                ]),
            Section::make('Metadata summary')
                ->schema([
                    TextEntry::make('metadata_summary')
                        ->label('Metadata summary')
                        ->state(fn (CatalogSyncLog $record): string => static::formatMetadataSummary($record))
                        ->html()
                        ->columnSpanFull(),
                ]),
            Section::make('Raw old values')
                ->collapsed()
                ->schema([
                    TextEntry::make('old_values')
                        ->label('Raw old values')
                        ->state(fn (CatalogSyncLog $record): string => json_encode($record->old_values ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                        ->placeholder('{}')
                        ->columnSpanFull(),
                ]),
            Section::make('Raw new values')
                ->collapsed()
                ->schema([
                    TextEntry::make('new_values')
                        ->label('Raw new values')
                        ->state(fn (CatalogSyncLog $record): string => json_encode($record->new_values ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                        ->placeholder('{}')
                        ->columnSpanFull(),
                ]),
            Section::make('Raw metadata')
                ->collapsed()
                ->schema([
                    TextEntry::make('metadata')
                        ->label('Raw metadata')
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

    protected static function canAccessResource(): bool
    {
        return (bool) auth()->user()?->canViewAuditLogs();
    }

    protected static function formatValueComparison(CatalogSyncLog $record): string
    {
        $oldValues = $record->old_values ?? [];
        $newValues = $record->new_values ?? [];
        $labels = static::valueLabels();
        $keys = collect(array_keys($labels))
            ->filter(fn (string $key): bool => array_key_exists($key, $oldValues) || array_key_exists($key, $newValues))
            ->values();

        if ($keys->isEmpty()) {
            return '<div class="text-sm text-gray-500 dark:text-gray-400">No old or new values were recorded.</div>';
        }

        $rows = $keys->map(function (string $key) use ($oldValues, $newValues, $labels): string {
            $old = $oldValues[$key] ?? null;
            $new = $newValues[$key] ?? null;
            $changed = static::normalizeComparableValue($old) !== static::normalizeComparableValue($new);
            $rowClass = $changed
                ? 'background: #fefce8;'
                : '';
            $badge = $changed
                ? '<span style="display: inline-flex; margin-left: 0.5rem; border-radius: 9999px; background: #fef3c7; color: #92400e; padding: 0.125rem 0.5rem; font-size: 0.75rem; font-weight: 600;">changed</span>'
                : '';

            return '<tr style="'.$rowClass.'">'
                .'<td style="padding: 0.5rem 0.75rem; font-weight: 600;">'.e($labels[$key]).$badge.'</td>'
                .'<td style="padding: 0.5rem 0.75rem;">'.e(static::formatDisplayValue($old)).'</td>'
                .'<td style="padding: 0.5rem 0.75rem;">'.e(static::formatDisplayValue($new)).'</td>'
                .'</tr>';
        })->implode('');

        return '<div style="max-width: 100%; overflow-x: auto;">'
            .'<table data-catalog-sync-log-value-comparison style="width: 100%; min-width: 680px; border-collapse: collapse; font-size: 0.875rem;">'
            .'<thead><tr style="background: #f9fafb;">'
            .'<th style="padding: 0.5rem 0.75rem; text-align: left;">Field</th>'
            .'<th style="padding: 0.5rem 0.75rem; text-align: left;">Old value</th>'
            .'<th style="padding: 0.5rem 0.75rem; text-align: left;">New value</th>'
            .'</tr></thead>'
            .'<tbody>'.$rows.'</tbody>'
            .'</table>'
            .'</div>';
    }

    protected static function formatMetadataSummary(CatalogSyncLog $record): string
    {
        $metadata = $record->metadata ?? [];
        $labels = [
            'match_type' => 'Match type',
            'sync_action' => 'Sync action',
            'sync_reason' => 'Sync reason',
            'match_confidence' => 'Match confidence',
        ];
        $keys = collect(array_keys($labels))
            ->filter(fn (string $key): bool => array_key_exists($key, $metadata))
            ->values();

        if ($keys->isEmpty()) {
            return '<div class="text-sm text-gray-500 dark:text-gray-400">No common sync metadata was recorded.</div>';
        }

        $rows = $keys->map(fn (string $key): string => '<tr>'
            .'<td style="padding: 0.5rem 0.75rem; font-weight: 600;">'.e($labels[$key]).'</td>'
            .'<td style="padding: 0.5rem 0.75rem;">'.e(static::formatDisplayValue($metadata[$key])).'</td>'
            .'</tr>')->implode('');

        return '<div style="max-width: 100%; overflow-x: auto;">'
            .'<table data-catalog-sync-log-metadata-summary style="width: 100%; min-width: 480px; border-collapse: collapse; font-size: 0.875rem;">'
            .'<thead><tr style="background: #f9fafb;">'
            .'<th style="padding: 0.5rem 0.75rem; text-align: left;">Metadata</th>'
            .'<th style="padding: 0.5rem 0.75rem; text-align: left;">Value</th>'
            .'</tr></thead>'
            .'<tbody>'.$rows.'</tbody>'
            .'</table>'
            .'</div>';
    }

    /**
     * @return array<string, string>
     */
    protected static function valueLabels(): array
    {
        return [
            'price' => 'Price',
            'regular_price' => 'Regular price',
            'reguar_price' => 'Regular price',
            'final_selling_price' => 'Final selling price',
            'supplier_price_raw' => 'Supplier cost',
            'purchase_price' => 'Purchase price',
            'recommended_price' => 'Recommended price',
            'quantity' => 'Quantity',
            'stock_status' => 'Stock status',
            'availability_status_id' => 'Availability status ID',
            'external_availability_label' => 'External availability label',
            'external_availability_status' => 'External availability status',
            'selected_supplier_offer_id' => 'Selected supplier offer',
            'supplier_id' => 'Supplier',
            'supplier_sku' => 'Supplier SKU',
        ];
    }

    protected static function formatDisplayValue(mixed $value): string
    {
        if ($value === null) {
            return '-';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '-';
        }

        return (string) $value;
    }

    protected static function normalizeComparableValue(mixed $value): string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        return (string) $value;
    }
}

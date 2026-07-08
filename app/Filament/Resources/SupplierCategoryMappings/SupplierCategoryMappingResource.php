<?php

namespace App\Filament\Resources\SupplierCategoryMappings;

use App\Filament\Resources\SupplierCategoryMappings\Pages\CreateSupplierCategoryMapping;
use App\Filament\Resources\SupplierCategoryMappings\Pages\EditSupplierCategoryMapping;
use App\Filament\Resources\SupplierCategoryMappings\Pages\ListSupplierCategoryMappings;
use App\Models\SupplierCategoryMapping;
use App\Models\SupplierProduct;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class SupplierCategoryMappingResource extends Resource
{
    protected static ?string $model = SupplierCategoryMapping::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static ?string $navigationLabel = 'Картографиране на категории от доставчици';

    protected static string|UnitEnum|null $navigationGroup = 'Таксономия';

    public static function getModelLabel(): string
    {
        return 'картографиране на категория от доставчик';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Картографиране на категории от доставчици';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Категория от доставчик')
                ->description('Запис за преглед. Не прилага картографиране към продукти и не създава каталогови категории.')
                ->schema([
                    Grid::make(2)->schema([
                        Select::make('supplier_id')
                            ->label('Доставчик')
                            ->relationship('supplier', 'company_name')
                            ->searchable()
                            ->preload()
                            ->disabled(fn (?SupplierCategoryMapping $record): bool => $record !== null)
                            ->dehydrated()
                            ->helperText('Системно открит източник. Промяна не прилага нищо към продукти.'),
                        TextInput::make('supplier_key')
                            ->label('Ключ на доставчик')
                            ->maxLength(255)
                            ->disabled(fn (?SupplierCategoryMapping $record): bool => $record !== null)
                            ->dehydrated(),
                        TextInput::make('supplier_name')
                            ->label('Име на доставчик')
                            ->maxLength(255)
                            ->disabled(fn (?SupplierCategoryMapping $record): bool => $record !== null)
                            ->dehydrated(),
                        TextInput::make('supplier_category_name')
                            ->label('Категория от доставчик')
                            ->required()
                            ->maxLength(255)
                            ->disabled(fn (?SupplierCategoryMapping $record): bool => $record !== null)
                            ->dehydrated(),
                        TextInput::make('supplier_category_slug')
                            ->label('Slug')
                            ->maxLength(255)
                            ->disabled(fn (?SupplierCategoryMapping $record): bool => $record !== null)
                            ->dehydrated(),
                        TextInput::make('supplier_category_path')
                            ->label('Път')
                            ->maxLength(255)
                            ->disabled(fn (?SupplierCategoryMapping $record): bool => $record !== null)
                            ->dehydrated(),
                        TextInput::make('supplier_category_external_id')
                            ->label('Външен ID')
                            ->maxLength(255)
                            ->disabled(fn (?SupplierCategoryMapping $record): bool => $record !== null)
                            ->dehydrated(),
                    ]),
                ]),
            Section::make('Вътрешно картографиране')
                ->description('Само подготвителни данни за бъдеща ръчна фаза. Няма действие за прилагане към продукти.')
                ->schema([
                    Grid::make(2)->schema([
                        Select::make('canonical_product_family_id')
                            ->label('Вътрешно продуктово семейство')
                            ->relationship('canonicalProductFamily', 'name_bg')
                            ->searchable()
                            ->preload(),
                        Select::make('target_category_id')
                            ->label('Бъдеща целева категория')
                            ->relationship('targetCategory', 'name')
                            ->searchable()
                            ->preload()
                            ->helperText('Опционално поле за бъдещо планиране. Одобряването не изисква целева категория и не променя категории.'),
                        Select::make('status')
                            ->label('Статус')
                            ->options(self::statusOptions())
                            ->default(SupplierCategoryMapping::STATUS_PENDING_REVIEW)
                            ->required()
                            ->disabled()
                            ->dehydrated(fn (?SupplierCategoryMapping $record): bool => $record === null)
                            ->helperText('Статусът се променя само чрез действията за преглед. Това не променя продукти или категории.'),
                        Select::make('confidence')
                            ->label('Увереност')
                            ->options(self::confidenceOptions()),
                        DateTimePicker::make('reviewed_at')
                            ->label('Прегледано на')
                            ->disabled()
                            ->dehydrated(false),
                        Select::make('reviewed_by')
                            ->label('Прегледано от')
                            ->relationship('reviewer', 'name')
                            ->searchable()
                            ->preload()
                            ->disabled()
                            ->dehydrated(false),
                    ]),
                    Textarea::make('match_reason')
                        ->label('Причина за съвпадение')
                        ->rows(3)
                        ->columnSpanFull(),
                    Textarea::make('notes')
                        ->label('Бележки')
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => self::reviewQueueQuery($query))
            ->columns([
                TextColumn::make('supplier.company_name')->label('Доставчик')->searchable()->sortable(),
                TextColumn::make('supplier_name')->label('Име на доставчик')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('supplier_category_name')->label('Категория')->searchable()->sortable()->limit(36)->tooltip(fn (SupplierCategoryMapping $record): ?string => $record->supplier_category_name),
                TextColumn::make('supplier_category_slug')->label('Slug')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('supplier_category_path')->label('Път')->searchable()->limit(44)->tooltip(fn (SupplierCategoryMapping $record): ?string => $record->supplier_category_path)->toggleable(),
                TextColumn::make('staged_product_count')
                    ->label('Продукти в staging')
                    ->numeric()
                    ->sortable(query: fn (Builder $query, string $direction): Builder => self::sortByStagedProductCount($query, $direction)),
                TextColumn::make('canonicalProductFamily.code')->label('Семейство')->badge()->placeholder('Няма')->sortable(),
                TextColumn::make('targetCategory.name')->label('Бъдеща категория')->placeholder('Не е избрана')->toggleable(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (?string $state): string => self::statusColor($state))
                    ->formatStateUsing(fn (?string $state): string => self::statusOptions()[$state] ?? (string) $state)
                    ->sortable(query: fn (Builder $query, string $direction): Builder => self::sortByStatus($query, $direction)),
                TextColumn::make('confidence')
                    ->label('Увереност')
                    ->badge()
                    ->color(fn (?string $state): string => self::confidenceColor($state))
                    ->formatStateUsing(fn (?string $state): string => self::confidenceOptions()[$state] ?? 'Няма')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => self::sortByConfidence($query, $direction)),
                TextColumn::make('match_reason')->label('Причина')->limit(60)->tooltip(fn (SupplierCategoryMapping $record): ?string => $record->match_reason)->toggleable(),
                TextColumn::make('notes_indicator')
                    ->label('Бележки')
                    ->state(fn (SupplierCategoryMapping $record): string => filled($record->notes) ? 'Има' : '-')
                    ->badge()
                    ->color(fn (SupplierCategoryMapping $record): string => filled($record->notes) ? 'info' : 'gray')
                    ->toggleable(),
                TextColumn::make('reviewed_at')->label('Прегледано')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reviewer.name')->label('Прегледано от')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options(self::statusOptions())
                    ->attribute('status')
                    ->indicateUsing(fn (array $state): array => self::statusFilterIndicators($state))
                    ->query(fn (Builder $query, array $data): Builder => self::applyStatusFilter($query, $data)),
                SelectFilter::make('supplier')->label('Доставчик')->relationship('supplier', 'company_name')->searchable()->preload(),
                SelectFilter::make('canonicalProductFamily')->label('Семейство')->relationship('canonicalProductFamily', 'name_bg')->searchable()->preload(),
                SelectFilter::make('confidence')->label('Увереност')->options(self::confidenceOptions()),
                Filter::make('pending_review')
                    ->label('Само за преглед')
                    ->query(fn (Builder $query): Builder => $query->where('status', SupplierCategoryMapping::STATUS_PENDING_REVIEW)),
                Filter::make('without_canonical_family')
                    ->label('Без семейство')
                    ->query(fn (Builder $query): Builder => $query->whereNull('canonical_product_family_id')),
                Filter::make('unknown_family')
                    ->label('Unknown семейство')
                    ->query(fn (Builder $query): Builder => $query->whereHas('canonicalProductFamily', fn (Builder $query): Builder => $query->where('code', 'unknown'))),
                Filter::make('without_target_category')
                    ->label('Без бъдеща категория')
                    ->query(fn (Builder $query): Builder => $query->whereNull('target_category_id')),
                Filter::make('approved_without_target_category')
                    ->label('Одобрени без бъдеща категория')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('status', SupplierCategoryMapping::STATUS_APPROVED)
                        ->whereNull('target_category_id')),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Одобри')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (): bool => self::canManageTaxonomy())
                    ->disabled(fn (SupplierCategoryMapping $record): bool => ! self::canQuickApprove($record))
                    ->requiresConfirmation()
                    ->modalHeading('Одобряване на supplier mapping')
                    ->modalDescription('Одобрява само този review запис. Не създава категории, не мести продукти и не прилага Catalog Sync.')
                    ->modalSubmitActionLabel('Одобри')
                    ->successNotificationTitle('Mapping-ът е одобрен.')
                    ->action(fn (SupplierCategoryMapping $record): bool => self::approveMapping($record)),
                Action::make('reject')
                    ->label('Отхвърли')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (): bool => self::canManageTaxonomy())
                    ->schema([
                        Textarea::make('notes')
                            ->label('Бележка')
                            ->maxLength(1000)
                            ->rows(3),
                    ])
                    ->modalHeading('Отхвърляне на supplier mapping')
                    ->modalDescription('Отхвърля само този review запис. Не променя продукти, категории или Catalog Sync.')
                    ->modalSubmitActionLabel('Отхвърли')
                    ->successNotificationTitle('Mapping-ът е отхвърлен.')
                    ->action(fn (SupplierCategoryMapping $record, array $data): bool => self::markMapping($record, SupplierCategoryMapping::STATUS_REJECTED, $data['notes'] ?? null)),
                Action::make('ignore')
                    ->label('Игнорирай')
                    ->icon('heroicon-o-no-symbol')
                    ->color('gray')
                    ->visible(fn (): bool => self::canManageTaxonomy())
                    ->schema([
                        Textarea::make('notes')
                            ->label('Бележка')
                            ->maxLength(1000)
                            ->rows(3),
                    ])
                    ->modalHeading('Игнориране на supplier mapping')
                    ->modalDescription('Игнорира само този review запис. Не променя продукти, категории или Catalog Sync.')
                    ->modalSubmitActionLabel('Игнорирай')
                    ->successNotificationTitle('Mapping-ът е игнориран.')
                    ->action(fn (SupplierCategoryMapping $record, array $data): bool => self::markMapping($record, SupplierCategoryMapping::STATUS_IGNORED, $data['notes'] ?? null)),
                Action::make('reset_pending')
                    ->label('Върни за преглед')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (SupplierCategoryMapping $record): bool => self::canManageTaxonomy() && $record->status !== SupplierCategoryMapping::STATUS_PENDING_REVIEW)
                    ->requiresConfirmation()
                    ->modalHeading('Връщане за преглед')
                    ->modalDescription('Връща само review статуса. Не променя продукти, категории или sync данни.')
                    ->modalSubmitActionLabel('Върни за преглед')
                    ->successNotificationTitle('Mapping-ът е върнат за преглед.')
                    ->action(fn (SupplierCategoryMapping $record): bool => self::resetMapping($record)),
                EditAction::make()->label('Редакция')->visible(fn (): bool => self::canManageTaxonomy()),
                DeleteAction::make()->label('Изтрий')->visible(fn (): bool => self::canManageTaxonomy()),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupplierCategoryMappings::route('/'),
            'create' => CreateSupplierCategoryMapping::route('/create'),
            'edit' => EditSupplierCategoryMapping::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return static::canViewTaxonomy();
    }

    public static function canView(Model $record): bool
    {
        return static::canViewTaxonomy();
    }

    public static function canCreate(): bool
    {
        return static::canManageTaxonomy();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canManageTaxonomy();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canManageTaxonomy();
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewTaxonomy();
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            SupplierCategoryMapping::STATUS_PENDING_REVIEW => 'За преглед',
            SupplierCategoryMapping::STATUS_APPROVED => 'Одобрено',
            SupplierCategoryMapping::STATUS_REJECTED => 'Отхвърлено',
            SupplierCategoryMapping::STATUS_IGNORED => 'Игнорирано',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function confidenceOptions(): array
    {
        return [
            SupplierCategoryMapping::CONFIDENCE_LOW => 'Ниска',
            SupplierCategoryMapping::CONFIDENCE_MEDIUM => 'Средна',
            SupplierCategoryMapping::CONFIDENCE_HIGH => 'Висока',
        ];
    }

    public static function canQuickApprove(SupplierCategoryMapping $record): bool
    {
        $family = $record->canonicalProductFamily;

        return $record->canonical_product_family_id !== null
            && $family?->code !== 'unknown';
    }

    public static function approveMapping(SupplierCategoryMapping $record): bool
    {
        if (! self::canQuickApprove($record)) {
            return false;
        }

        return self::markMapping($record, SupplierCategoryMapping::STATUS_APPROVED);
    }

    public static function markMapping(SupplierCategoryMapping $record, string $status, ?string $notes = null): bool
    {
        if (! in_array($status, [
            SupplierCategoryMapping::STATUS_APPROVED,
            SupplierCategoryMapping::STATUS_REJECTED,
            SupplierCategoryMapping::STATUS_IGNORED,
        ], true)) {
            return false;
        }

        $updates = [
            'status' => $status,
            'reviewed_at' => now(),
            'reviewed_by' => auth()->id(),
        ];

        if ($notes !== null) {
            $updates['notes'] = trim($notes);
        }

        return $record->forceFill($updates)->save();
    }

    public static function resetMapping(SupplierCategoryMapping $record): bool
    {
        return $record->forceFill([
            'status' => SupplierCategoryMapping::STATUS_PENDING_REVIEW,
            'reviewed_at' => null,
            'reviewed_by' => null,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function applyStatusFilter(Builder $query, array $data): Builder
    {
        $statuses = self::resolveStatusFilterValues($data);

        if ($statuses === []) {
            return $query;
        }

        return count($statuses) === 1
            ? $query->where('supplier_category_mappings.status', $statuses[0])
            : $query->whereIn('supplier_category_mappings.status', $statuses);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, string>
     */
    protected static function statusFilterIndicators(array $state): array
    {
        $labels = collect(self::resolveStatusFilterValues($state))
            ->map(fn (string $status): string => self::statusOptions()[$status])
            ->all();

        if ($labels === []) {
            return [];
        }

        return ['value' => 'Статус: '.implode(', ', $labels)];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    protected static function resolveStatusFilterValues(array $data): array
    {
        $selected = $data['value'] ?? $data['values'] ?? null;

        if ($selected === null && array_is_list($data)) {
            $selected = $data;
        }

        $validStatuses = array_keys(self::statusOptions());

        return collect(is_array($selected) ? $selected : [$selected])
            ->flatten()
            ->filter(fn (mixed $value): bool => is_scalar($value))
            ->map(fn (mixed $value): string => (string) $value)
            ->filter(fn (string $value): bool => in_array($value, $validStatuses, true))
            ->unique()
            ->values()
            ->all();
    }

    protected static function reviewQueueQuery(Builder $query): Builder
    {
        $stagedProductCount = SupplierProduct::query()
            ->selectRaw('count(*)')
            ->whereColumn('supplier_products.supplier_id', 'supplier_category_mappings.supplier_id')
            ->whereColumn('supplier_products.category_name', 'supplier_category_mappings.supplier_category_name');

        return $query
            ->select('supplier_category_mappings.*')
            ->selectSub($stagedProductCount, 'staged_product_count')
            ->with(['supplier', 'canonicalProductFamily', 'targetCategory', 'reviewer'])
            ->orderByRaw(
                'CASE supplier_category_mappings.status WHEN ? THEN 0 WHEN ? THEN 1 WHEN ? THEN 2 WHEN ? THEN 3 ELSE 4 END',
                [
                    SupplierCategoryMapping::STATUS_PENDING_REVIEW,
                    SupplierCategoryMapping::STATUS_REJECTED,
                    SupplierCategoryMapping::STATUS_IGNORED,
                    SupplierCategoryMapping::STATUS_APPROVED,
                ],
            )
            ->orderByDesc('staged_product_count')
            ->orderByRaw(
                'CASE supplier_category_mappings.confidence WHEN ? THEN 0 WHEN ? THEN 1 WHEN ? THEN 2 ELSE 3 END',
                [
                    SupplierCategoryMapping::CONFIDENCE_HIGH,
                    SupplierCategoryMapping::CONFIDENCE_MEDIUM,
                    SupplierCategoryMapping::CONFIDENCE_LOW,
                ],
            )
            ->latest('supplier_category_mappings.created_at');
    }

    protected static function sortByStagedProductCount(Builder $query, string $direction): Builder
    {
        return $query
            ->reorder()
            ->orderBy('staged_product_count', $direction)
            ->orderBy('supplier_category_mappings.id');
    }

    protected static function sortByStatus(Builder $query, string $direction): Builder
    {
        $knownStatuses = [
            SupplierCategoryMapping::STATUS_APPROVED,
            SupplierCategoryMapping::STATUS_PENDING_REVIEW,
            SupplierCategoryMapping::STATUS_REJECTED,
            SupplierCategoryMapping::STATUS_IGNORED,
        ];

        $statusOrder = $direction === 'asc'
            ? [
                SupplierCategoryMapping::STATUS_IGNORED,
                SupplierCategoryMapping::STATUS_REJECTED,
                SupplierCategoryMapping::STATUS_PENDING_REVIEW,
                SupplierCategoryMapping::STATUS_APPROVED,
            ]
            : $knownStatuses;

        return $query
            ->reorder()
            ->orderByRaw(
                'CASE WHEN supplier_category_mappings.status IN (?, ?, ?, ?) THEN 0 ELSE 1 END ASC',
                $knownStatuses,
            )
            ->orderByRaw(
                'CASE supplier_category_mappings.status WHEN ? THEN 0 WHEN ? THEN 1 WHEN ? THEN 2 WHEN ? THEN 3 ELSE 4 END ASC',
                $statusOrder,
            )
            ->orderBy('supplier_category_mappings.id');
    }

    protected static function sortByConfidence(Builder $query, string $direction): Builder
    {
        $confidenceOrder = $direction === 'desc'
            ? [
                SupplierCategoryMapping::CONFIDENCE_HIGH,
                SupplierCategoryMapping::CONFIDENCE_MEDIUM,
                SupplierCategoryMapping::CONFIDENCE_LOW,
            ]
            : [
                SupplierCategoryMapping::CONFIDENCE_LOW,
                SupplierCategoryMapping::CONFIDENCE_MEDIUM,
                SupplierCategoryMapping::CONFIDENCE_HIGH,
            ];

        return $query
            ->reorder()
            ->orderByRaw(
                'CASE WHEN supplier_category_mappings.confidence IN (?, ?, ?) THEN 0 ELSE 1 END ASC',
                [
                    SupplierCategoryMapping::CONFIDENCE_LOW,
                    SupplierCategoryMapping::CONFIDENCE_MEDIUM,
                    SupplierCategoryMapping::CONFIDENCE_HIGH,
                ],
            )
            ->orderByRaw(
                'CASE supplier_category_mappings.confidence WHEN ? THEN 0 WHEN ? THEN 1 WHEN ? THEN 2 ELSE 3 END ASC',
                $confidenceOrder,
            )
            ->orderBy('supplier_category_mappings.id');
    }

    protected static function statusColor(?string $status): string
    {
        return match ($status) {
            SupplierCategoryMapping::STATUS_APPROVED => 'success',
            SupplierCategoryMapping::STATUS_REJECTED => 'danger',
            SupplierCategoryMapping::STATUS_IGNORED => 'gray',
            SupplierCategoryMapping::STATUS_PENDING_REVIEW => 'warning',
            default => 'gray',
        };
    }

    protected static function confidenceColor(?string $confidence): string
    {
        return match ($confidence) {
            SupplierCategoryMapping::CONFIDENCE_HIGH => 'success',
            SupplierCategoryMapping::CONFIDENCE_MEDIUM => 'warning',
            SupplierCategoryMapping::CONFIDENCE_LOW => 'gray',
            default => 'gray',
        };
    }

    protected static function canViewTaxonomy(): bool
    {
        $user = auth()->user();

        return $user?->isActiveAdminAccount() && (
            $user->isSuperAdmin()
            || $user->hasPrimaryRole(User::ROLE_VIEWER_AUDITOR)
        );
    }

    protected static function canManageTaxonomy(): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }
}

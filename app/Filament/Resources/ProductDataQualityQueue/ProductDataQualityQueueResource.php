<?php

namespace App\Filament\Resources\ProductDataQualityQueue;

use App\Filament\Resources\ProductDataQualityQueue\Pages\ListProductDataQualityQueue;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use App\Models\ProductQualityFlag;
use App\Models\User;
use App\Services\Products\ProductDataQualityScanner;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ProductDataQualityQueueResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'Product Data Quality Queue';

    protected static ?string $modelLabel = 'Product data quality item';

    protected static ?string $pluralModelLabel = 'Product Data Quality Queue';

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        $scanner = app(ProductDataQualityScanner::class);

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $scanner
                ->applyQueueScope($query)
                ->with(['thumbnailImage', 'category', 'brand', 'assignedTo', 'images', 'attributes', 'activeQualityFlagAssignments.flag'])
                ->withCount('activeQualityFlagAssignments'))
            ->defaultSort('created_at', 'desc')
            ->defaultSortOptionLabel('Newest first')
            ->columns([
                ImageColumn::make('thumbnail')
                    ->label('Снимка')
                    ->state(fn (Product $record): ?string => $record->thumbnailUrl())
                    ->defaultImageUrl(self::placeholderImageUrl())
                    ->size(48)
                    ->square(),
                TextColumn::make('image_status')
                    ->label('Image status')
                    ->state(fn (Product $record): string => $record->thumbnailUrl() ? 'Has image' : 'Missing image')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Missing image' ? 'warning' : 'success'),
                TextColumn::make('name')
                    ->label('Product')
                    ->description(fn (Product $record): string => sprintf(
                        'SKU: %s | ID: %d',
                        filled($record->sku) ? $record->sku : 'missing',
                        $record->getKey(),
                    ))
                    ->searchable()
                    ->sortable()
                    ->limit(48)
                    ->tooltip(fn (Product $record): ?string => $record->name),
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->toggleable(),
                TextColumn::make('id')
                    ->label('ID')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('source')->label('Source')->badge()->sortable(),
                TextColumn::make('workflow_status')
                    ->label('Workflow')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Product::workflowStatusLabel($state))
                    ->color(fn (?string $state): string => match ($state) {
                        Product::WORKFLOW_PUBLISHED => 'success',
                        Product::WORKFLOW_APPROVED => 'info',
                        Product::WORKFLOW_PENDING_REVIEW => 'warning',
                        Product::WORKFLOW_CHANGES_REQUESTED => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('visibility_status')
                    ->label('Visibility')
                    ->state(fn (Product $record): string => self::isPubliclyVisible($record) ? 'Public' : 'Hidden')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Public' ? 'success' : 'gray'),
                TextColumn::make('product_status')->label('Product status')->badge()->sortable(),
                TextColumn::make('stock_status')->badge()->sortable()->toggleable(),
                TextColumn::make('price')->money(Product::CATALOG_CURRENCY)->sortable(),
                TextColumn::make('quantity')->sortable(),
                TextColumn::make('category.name')->label('Category')->sortable()->toggleable(),
                TextColumn::make('brand.name')->label('Brand')->sortable()->toggleable(),
                TextColumn::make('active_quality_flag_assignments_count')
                    ->label('Flag count')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray')
                    ->sortable(),
                TextColumn::make('detected_issues')
                    ->label('Detected issues')
                    ->state(fn (Product $record): array => $scanner->issueLabels($record))
                    ->badge()
                    ->separator(',')
                    ->placeholder('-')
                    ->limitList(4)
                    ->expandableLimitedList(),
                TextColumn::make('active_quality_flags')
                    ->label('Quality flags')
                    ->state(fn (Product $record): array => $scanner->activeFlagLabels($record))
                    ->badge()
                    ->separator(',')
                    ->placeholder('-')
                    ->limitList(3)
                    ->expandableLimitedList(),
                TextColumn::make('assignedTo.name')->label('Assigned')->toggleable(),
                TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('issue_type')
                    ->label('Issue type')
                    ->options(ProductDataQualityScanner::issueOptions())
                    ->query(fn (Builder $query, array $data): Builder => $scanner->applyIssueQuery($query, $data['value'] ?? null)),
                SelectFilter::make('quality_flag')
                    ->label('Quality flag')
                    ->options(fn (): array => ProductQualityFlag::query()->active()->ordered()->pluck('label_bg', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null)
                        ? $query
                        : $query->whereHas('activeQualityFlagAssignments', fn (Builder $query): Builder => $query->where('product_quality_flag_id', $data['value']))),
                SelectFilter::make('severity')
                    ->options(ProductQualityFlag::severityOptions())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null)
                        ? $query
                        : $query->whereHas('activeQualityFlagAssignments.flag', fn (Builder $query): Builder => $query->where('severity', $data['value']))),
                SelectFilter::make('source')
                    ->options([
                        Product::SOURCE_MANUAL => 'Manual',
                        Product::SOURCE_SUPPLIER_IMPORT => 'Supplier import',
                    ]),
                SelectFilter::make('workflow_status')
                    ->label('Workflow')
                    ->options(Product::workflowStatusOptions()),
                SelectFilter::make('product_status')
                    ->label('Product status')
                    ->options([
                        'draft' => 'Draft',
                        'active' => 'Active',
                        'hidden' => 'Hidden',
                        'archived' => 'Archived',
                        'discontinued' => 'Discontinued',
                    ]),
                SelectFilter::make('visibility')
                    ->label('Visibility')
                    ->options([
                        'public' => 'Public',
                        'hidden' => 'Hidden',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'public' => $query
                            ->where('active', true)
                            ->whereNotNull('published_at')
                            ->where('workflow_status', Product::WORKFLOW_PUBLISHED),
                        'hidden' => $query->where(fn (Builder $query): Builder => $query
                            ->where('active', false)
                            ->orWhereNull('published_at')
                            ->orWhere('workflow_status', '!=', Product::WORKFLOW_PUBLISHED)),
                        default => $query,
                    }),
                SelectFilter::make('stock_status')
                    ->options(Product::stockStatusOptions()),
                SelectFilter::make('category')->relationship('category', 'name')->searchable()->preload(),
                SelectFilter::make('brand')->relationship('brand', 'name')->searchable()->preload(),
                SelectFilter::make('assigned_to')->relationship('assignedTo', 'name')->searchable()->preload(),
                SelectFilter::make('responsible_role')
                    ->label('Responsible role')
                    ->options(User::roleOptions())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null)
                        ? $query
                        : $query->whereHas('activeQualityFlagAssignments.flag', fn (Builder $query): Builder => $query->where('responsible_role', $data['value']))),
                TernaryFilter::make('missing_image')
                    ->label('Missing image')
                    ->queries(
                        true: fn (Builder $query): Builder => $scanner->applyIssueQuery($query, ProductDataQualityScanner::ISSUE_MISSING_IMAGE),
                        false: fn (Builder $query): Builder => $query->has('images'),
                    ),
                TernaryFilter::make('has_quality_flags')
                    ->label('Assigned quality flags')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas('activeQualityFlagAssignments'),
                        false: fn (Builder $query): Builder => $query->doesntHave('activeQualityFlagAssignments'),
                    ),
                TernaryFilter::make('missing_seo')
                    ->label('Missing SEO')
                    ->queries(
                        true: fn (Builder $query): Builder => $scanner->applyIssueQuery($query, ProductDataQualityScanner::ISSUE_MISSING_SEO),
                        false: fn (Builder $query): Builder => $query
                            ->whereNotNull('meta_title')
                            ->where('meta_title', '!=', '')
                            ->whereNotNull('meta_description')
                            ->where('meta_description', '!=', ''),
                    ),
                TernaryFilter::make('missing_en_translation')
                    ->label('Missing EN translation')
                    ->queries(
                        true: fn (Builder $query): Builder => $scanner->applyIssueQuery($query, ProductDataQualityScanner::ISSUE_MISSING_EN_TRANSLATION),
                        false: fn (Builder $query): Builder => $query
                            ->whereNotNull('name_translations->en')
                            ->where('name_translations->en', '!=', '')
                            ->whereNotNull('description_translations->en')
                            ->where('description_translations->en', '!=', '')
                            ->whereNotNull('meta_title_translations->en')
                            ->where('meta_title_translations->en', '!=', ''),
                    ),
                TernaryFilter::make('missing_category')
                    ->label('Missing category')
                    ->queries(
                        true: fn (Builder $query): Builder => $scanner->applyIssueQuery($query, ProductDataQualityScanner::ISSUE_MISSING_CATEGORY),
                        false: fn (Builder $query): Builder => $query->whereNotNull('category_id'),
                    ),
            ])
            ->recordActions([
                Action::make('editProduct')
                    ->label('Edit product')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->url(fn (Product $record): string => ProductResource::getUrl('edit', ['record' => $record])),
                Action::make('openProduct')
                    ->label('Open in new tab')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (Product $record): string => ProductResource::getUrl('edit', ['record' => $record]))
                    ->openUrlInNewTab(),
                Action::make('reviewFlags')
                    ->label('Review flags')
                    ->icon(Heroicon::OutlinedFlag)
                    ->url(fn (Product $record): string => ProductResource::getUrl('edit', ['record' => $record]))
                    ->visible(fn (Product $record): bool => (int) ($record->active_quality_flag_assignments_count ?? 0) > 0),
            ])
            ->emptyStateHeading('Няма продукти за преглед')
            ->emptyStateDescription('Когато продукт има липсваща информация или активен quality flag, ще се появи тук за ръчна проверка.')
            ->emptyStateIcon(Heroicon::OutlinedClipboardDocumentCheck);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductDataQualityQueue::route('/'),
        ];
    }

    public static function canViewAny(): bool
    {
        return static::canAccessResource();
    }

    public static function canView(Model $record): bool
    {
        return static::canAccessResource();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccessResource();
    }

    protected static function canAccessResource(): bool
    {
        $user = auth()->user();

        return (bool) $user?->isActiveAdminAccount()
            && $user->hasPrimaryRole([
                User::ROLE_SUPER_ADMIN,
                User::ROLE_CATALOG_MANAGER,
                User::ROLE_PRODUCT_EDITOR,
                User::ROLE_PRODUCT_DATA_ENTRY,
                User::ROLE_SEO_MARKETING,
                User::ROLE_VIEWER_AUDITOR,
            ]);
    }

    protected static function placeholderImageUrl(): string
    {
        return 'data:image/svg+xml;utf8,'.rawurlencode(
            '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 48 48"><rect width="48" height="48" rx="6" fill="#f3f4f6"/><path d="M14 32h20l-6-8-5 6-3-4-6 6Z" fill="#9ca3af"/><circle cx="18" cy="18" r="4" fill="#d1d5db"/><text x="24" y="43" text-anchor="middle" font-family="Arial" font-size="6" fill="#6b7280">no image</text></svg>'
        );
    }

    protected static function isPubliclyVisible(Product $product): bool
    {
        return (bool) $product->active
            && $product->published_at !== null
            && $product->workflow_status === Product::WORKFLOW_PUBLISHED;
    }
}

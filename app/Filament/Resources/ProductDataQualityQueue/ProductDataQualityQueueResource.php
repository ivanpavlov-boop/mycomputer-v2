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

    protected static ?string $navigationLabel = 'Опашка за качество на продуктови данни';

    protected static ?string $modelLabel = 'Продукт за преглед на качеството';

    protected static ?string $pluralModelLabel = 'Опашка за качество на продуктови данни';

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
            ->defaultSortOptionLabel('Най-нови първо')
            ->columns([
                ImageColumn::make('thumbnail')
                    ->label('Снимка')
                    ->state(fn (Product $record): ?string => $record->thumbnailUrl())
                    ->defaultImageUrl(self::placeholderImageUrl())
                    ->size(48)
                    ->square(),
                TextColumn::make('image_status')
                    ->label('Статус на снимка')
                    ->state(fn (Product $record): string => $record->thumbnailUrl() ? 'Има снимка' : 'Липсва снимка')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Липсва снимка' ? 'warning' : 'success'),
                TextColumn::make('name')
                    ->label('Продукт')
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
                TextColumn::make('source')->label('Източник')->badge()->sortable(),
                TextColumn::make('workflow_status')
                    ->label('Работен статус')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Product::workflowStatusLabel($state))
                    ->color(fn (?string $state): string => Product::workflowStatusColor($state))
                    ->sortable(),
                TextColumn::make('visibility_status')
                    ->label('Видимост')
                    ->state(fn (Product $record): string => self::isPubliclyVisible($record) ? 'Публичен' : 'Скрит')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Публичен' ? 'success' : 'gray'),
                TextColumn::make('product_status')->label('Статус на продукта')->badge()->sortable(),
                TextColumn::make('stock_status')->label('Статус на наличност')->badge()->sortable()->toggleable(),
                TextColumn::make('price')->label('Цена')->money(Product::CATALOG_CURRENCY)->sortable(),
                TextColumn::make('quantity')->label('Количество')->sortable(),
                TextColumn::make('category.name')->label('Категория')->sortable()->toggleable(),
                TextColumn::make('brand.name')->label('Бранд')->sortable()->toggleable(),
                TextColumn::make('active_quality_flag_assignments_count')
                    ->label('Брой флагове')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray')
                    ->sortable(),
                TextColumn::make('detected_issues')
                    ->label('Открити проблеми')
                    ->state(fn (Product $record): array => self::detectedIssueLabels($record, $scanner))
                    ->badge()
                    ->separator(',')
                    ->placeholder('-')
                    ->limitList(4)
                    ->expandableLimitedList(),
                TextColumn::make('active_quality_flags')
                    ->label('Флагове за качество')
                    ->state(fn (Product $record): array => $scanner->activeFlagLabels($record))
                    ->badge()
                    ->separator(',')
                    ->placeholder('-')
                    ->limitList(3)
                    ->expandableLimitedList(),
                TextColumn::make('assignedTo.name')->label('Отговорник')->toggleable(),
                TextColumn::make('updated_at')->label('Обновен на')->dateTime()->sortable()->toggleable(),
                TextColumn::make('created_at')->label('Създаден на')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('issue_type')
                    ->label('Тип проблем')
                    ->options(self::issueOptions())
                    ->query(fn (Builder $query, array $data): Builder => $scanner->applyIssueQuery($query, $data['value'] ?? null)),
                SelectFilter::make('quality_flag')
                    ->label('Флаг за качество')
                    ->options(fn (): array => ProductQualityFlag::query()->active()->ordered()->pluck('label_bg', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null)
                        ? $query
                        : $query->whereHas('activeQualityFlagAssignments', fn (Builder $query): Builder => $query->where('product_quality_flag_id', $data['value']))),
                SelectFilter::make('severity')
                    ->label('Важност')
                    ->options(self::severityOptions())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null)
                        ? $query
                        : $query->whereHas('activeQualityFlagAssignments.flag', fn (Builder $query): Builder => $query->where('severity', $data['value']))),
                SelectFilter::make('source')
                    ->label('Източник')
                    ->options([
                        Product::SOURCE_MANUAL => 'Ръчно',
                        Product::SOURCE_SUPPLIER_IMPORT => 'От доставчик',
                    ]),
                SelectFilter::make('workflow_status')
                    ->label('Работен статус')
                    ->options(Product::workflowStatusOptions()),
                SelectFilter::make('product_status')
                    ->label('Статус на продукта')
                    ->options([
                        'draft' => 'Чернова',
                        'active' => 'Активен',
                        'hidden' => 'Скрит',
                        'archived' => 'Архивиран',
                        'discontinued' => 'Спрян',
                    ]),
                SelectFilter::make('visibility')
                    ->label('Видимост')
                    ->options([
                        'public' => 'Публичен',
                        'hidden' => 'Скрит',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'public' => $query->whereIn('products.id', Product::query()->published()->select('products.id')),
                        'hidden' => $query->whereNotIn('products.id', Product::query()->published()->select('products.id')),
                        default => $query,
                    }),
                SelectFilter::make('stock_status')
                    ->label('Статус на наличност')
                    ->options(Product::stockStatusOptions()),
                SelectFilter::make('category')->label('Категория')->relationship('category', 'name')->searchable()->preload(),
                SelectFilter::make('brand')->label('Бранд')->relationship('brand', 'name')->searchable()->preload(),
                SelectFilter::make('assigned_to')->label('Отговорник')->relationship('assignedTo', 'name')->searchable()->preload(),
                SelectFilter::make('responsible_role')
                    ->label('Отговорна роля')
                    ->options(self::roleOptions())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null)
                        ? $query
                        : $query->whereHas('activeQualityFlagAssignments.flag', fn (Builder $query): Builder => $query->where('responsible_role', $data['value']))),
                TernaryFilter::make('missing_image')
                    ->label('Липсва снимка')
                    ->queries(
                        true: fn (Builder $query): Builder => $scanner->applyIssueQuery($query, ProductDataQualityScanner::ISSUE_MISSING_IMAGE),
                        false: fn (Builder $query): Builder => $query->has('images'),
                    ),
                TernaryFilter::make('has_quality_flags')
                    ->label('Назначени флагове за качество')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas('activeQualityFlagAssignments'),
                        false: fn (Builder $query): Builder => $query->doesntHave('activeQualityFlagAssignments'),
                    ),
                TernaryFilter::make('missing_seo')
                    ->label('Липсва SEO')
                    ->queries(
                        true: fn (Builder $query): Builder => $scanner->applyIssueQuery($query, ProductDataQualityScanner::ISSUE_MISSING_SEO),
                        false: fn (Builder $query): Builder => $query
                            ->whereNotNull('meta_title')
                            ->where('meta_title', '!=', '')
                            ->whereNotNull('meta_description')
                            ->where('meta_description', '!=', ''),
                    ),
                TernaryFilter::make('missing_en_translation')
                    ->label('Липсва EN превод')
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
                    ->label('Липсва категория')
                    ->queries(
                        true: fn (Builder $query): Builder => $scanner->applyIssueQuery($query, ProductDataQualityScanner::ISSUE_MISSING_CATEGORY),
                        false: fn (Builder $query): Builder => $query->whereNotNull('category_id'),
                    ),
            ])
            ->recordActions([
                Action::make('editProduct')
                    ->label('Редакция на продукт')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->url(fn (Product $record): string => ProductResource::getUrl('edit', ['record' => $record])),
                Action::make('openProduct')
                    ->label('Отвори в нов таб')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (Product $record): string => ProductResource::getUrl('edit', ['record' => $record]))
                    ->openUrlInNewTab(),
                Action::make('reviewFlags')
                    ->label('Преглед на флагове')
                    ->icon(Heroicon::OutlinedFlag)
                    ->url(fn (Product $record): string => ProductResource::getUrl('edit', ['record' => $record]))
                    ->visible(fn (Product $record): bool => (int) ($record->active_quality_flag_assignments_count ?? 0) > 0),
            ])
            ->emptyStateHeading('Няма продукти за преглед')
            ->emptyStateDescription('Когато продукт има липсваща информация или активен флаг за качество, ще се появи тук за ръчна проверка.')
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
        return $product->isPubliclyVisible();
    }

    /**
     * @return array<string, string>
     */
    protected static function issueOptions(): array
    {
        return ProductDataQualityScanner::issueOptions();
    }

    /**
     * @return array<string, string>
     */
    protected static function severityOptions(): array
    {
        return [
            ProductQualityFlag::SEVERITY_LOW => 'Ниска',
            ProductQualityFlag::SEVERITY_MEDIUM => 'Средна',
            ProductQualityFlag::SEVERITY_HIGH => 'Висока',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected static function roleOptions(): array
    {
        return [
            User::ROLE_SUPER_ADMIN => 'Супер администратор',
            User::ROLE_CATALOG_MANAGER => 'Каталог',
            User::ROLE_PRODUCT_EDITOR => 'Редактор на продукти',
            User::ROLE_PRODUCT_DATA_ENTRY => 'Въвеждане на продукти',
            User::ROLE_PRICING_MANAGER => 'Цени',
            User::ROLE_INVENTORY_MANAGER => 'Наличност',
            User::ROLE_SEO_MARKETING => 'SEO / Маркетинг',
            User::ROLE_ORDER_MANAGER => 'Поръчки',
            User::ROLE_VIEWER_AUDITOR => 'Преглед / одит',
        ];
    }

    /**
     * @return array<int, string>
     */
    protected static function detectedIssueLabels(Product $product, ProductDataQualityScanner $scanner): array
    {
        $labels = self::issueOptions();

        return collect($scanner->detectedIssues($product))
            ->map(fn (array $issue): string => $labels[$issue['code']] ?? $issue['label'])
            ->all();
    }
}

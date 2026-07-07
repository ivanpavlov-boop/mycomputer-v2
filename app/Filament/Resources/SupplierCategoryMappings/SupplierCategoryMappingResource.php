<?php

namespace App\Filament\Resources\SupplierCategoryMappings;

use App\Filament\Resources\SupplierCategoryMappings\Pages\CreateSupplierCategoryMapping;
use App\Filament\Resources\SupplierCategoryMappings\Pages\EditSupplierCategoryMapping;
use App\Filament\Resources\SupplierCategoryMappings\Pages\ListSupplierCategoryMappings;
use App\Models\SupplierCategoryMapping;
use App\Models\User;
use BackedEnum;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class SupplierCategoryMappingResource extends Resource
{
    protected static ?string $model = SupplierCategoryMapping::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static ?string $navigationLabel = 'Картографиране на категории от доставчици';

    protected static string|UnitEnum|null $navigationGroup = 'Каталог характеристики';

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
                            ->preload(),
                        TextInput::make('supplier_key')
                            ->label('Ключ на доставчик')
                            ->maxLength(255),
                        TextInput::make('supplier_name')
                            ->label('Име на доставчик')
                            ->maxLength(255),
                        TextInput::make('supplier_category_name')
                            ->label('Категория от доставчик')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('supplier_category_slug')
                            ->label('Slug')
                            ->maxLength(255),
                        TextInput::make('supplier_category_path')
                            ->label('Път')
                            ->maxLength(255),
                        TextInput::make('supplier_category_external_id')
                            ->label('Външен ID')
                            ->maxLength(255),
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
                            ->preload(),
                        Select::make('status')
                            ->label('Статус')
                            ->options(self::statusOptions())
                            ->default(SupplierCategoryMapping::STATUS_PENDING_REVIEW)
                            ->required(),
                        Select::make('confidence')
                            ->label('Увереност')
                            ->options(self::confidenceOptions()),
                        DateTimePicker::make('reviewed_at')
                            ->label('Прегледано на'),
                        Select::make('reviewed_by')
                            ->label('Прегледано от')
                            ->relationship('reviewer', 'name')
                            ->searchable()
                            ->preload(),
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
            ->columns([
                TextColumn::make('supplier.company_name')->label('Доставчик')->searchable()->sortable(),
                TextColumn::make('supplier_name')->label('Име на доставчик')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('supplier_category_name')->label('Категория')->searchable()->sortable(),
                TextColumn::make('supplier_category_path')->label('Път')->searchable()->toggleable(),
                TextColumn::make('canonicalProductFamily.code')->label('Семейство')->badge()->sortable(),
                TextColumn::make('targetCategory.name')->label('Целева категория')->toggleable(),
                TextColumn::make('status')->label('Статус')->badge()->formatStateUsing(fn (?string $state): string => self::statusOptions()[$state] ?? (string) $state)->sortable(),
                TextColumn::make('confidence')->label('Увереност')->badge()->formatStateUsing(fn (?string $state): string => self::confidenceOptions()[$state] ?? 'Няма')->sortable(),
                TextColumn::make('match_reason')->label('Причина')->limit(60)->toggleable(),
                TextColumn::make('reviewed_at')->label('Прегледано')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label('Статус')->options(self::statusOptions()),
                SelectFilter::make('supplier')->label('Доставчик')->relationship('supplier', 'company_name')->searchable()->preload(),
                SelectFilter::make('canonicalProductFamily')->label('Семейство')->relationship('canonicalProductFamily', 'name_bg')->searchable()->preload(),
            ])
            ->recordActions([
                EditAction::make()->label('Редакция'),
                DeleteAction::make()->label('Изтрий'),
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

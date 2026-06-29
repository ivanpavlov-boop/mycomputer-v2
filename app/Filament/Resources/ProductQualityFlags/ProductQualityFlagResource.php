<?php

namespace App\Filament\Resources\ProductQualityFlags;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\ProductQualityFlags\Pages\CreateProductQualityFlag;
use App\Filament\Resources\ProductQualityFlags\Pages\EditProductQualityFlag;
use App\Filament\Resources\ProductQualityFlags\Pages\ListProductQualityFlags;
use App\Models\ProductQualityFlag;
use App\Models\User;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ProductQualityFlagResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = ProductQualityFlag::class;

    protected static ?string $permission = 'manage products';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static ?string $navigationLabel = 'Флагове за качество';

    protected static ?string $modelLabel = 'Флаг за качество';

    protected static ?string $pluralModelLabel = 'Флагове за качество';

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
                TextInput::make('code')
                    ->label('Код')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(100),
                TextInput::make('label_bg')
                    ->label('Етикет на български')
                    ->required()
                    ->maxLength(255),
                TextInput::make('label_en')
                    ->label('Етикет на английски')
                    ->maxLength(255),
                Select::make('severity')
                    ->label('Важност')
                    ->options(self::severityOptions())
                    ->default(ProductQualityFlag::SEVERITY_MEDIUM)
                    ->required(),
                Select::make('responsible_role')
                    ->label('Отговорна роля')
                    ->options(self::roleOptions())
                    ->searchable(),
                Select::make('type')
                    ->label('Тип')
                    ->options(self::typeOptions())
                    ->searchable(),
                Toggle::make('is_active')
                    ->label('Активен')
                    ->default(true),
                TextInput::make('sort_order')
                    ->label('Ред на сортиране')
                    ->numeric()
                    ->default(0)
                    ->required(),
                Textarea::make('description_bg')
                    ->label('Описание на български')
                    ->rows(3)
                    ->columnSpanFull(),
                Textarea::make('description_en')
                    ->label('Описание на английски')
                    ->rows(3)
                    ->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label('Код')->searchable()->sortable(),
                TextColumn::make('label_bg')->label('Етикет')->searchable()->sortable(),
                TextColumn::make('severity')
                    ->label('Важност')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::severityOptions()[$state] ?? 'Няма')
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Тип')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::typeOptions()[$state] ?? 'Няма')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('responsible_role')
                    ->label('Отговорна роля')
                    ->formatStateUsing(fn (?string $state): string => self::roleOptions()[$state] ?? 'Няма')
                    ->toggleable(),
                IconColumn::make('is_active')->label('Активен')->boolean()->sortable(),
                TextColumn::make('sort_order')->label('Ред на сортиране')->sortable()->toggleable(),
                TextColumn::make('created_at')->label('Създаден на')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->label('Обновен на')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('severity')->label('Важност')->options(self::severityOptions()),
                SelectFilter::make('type')->label('Тип')->options(self::typeOptions()),
                SelectFilter::make('responsible_role')->label('Отговорна роля')->options(self::roleOptions()),
                TernaryFilter::make('is_active')->label('Активен'),
            ])
            ->recordActions([
                EditAction::make()->label('Редакция'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->label('Изтрий избраните'),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductQualityFlags::route('/'),
            'create' => CreateProductQualityFlag::route('/create'),
            'edit' => EditProductQualityFlag::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return static::canAccessResource();
    }

    public static function canCreate(): bool
    {
        return static::canAccessResource();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canAccessResource();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canAccessResource();
    }

    public static function canDeleteAny(): bool
    {
        return static::canAccessResource();
    }

    protected static function canAccessResource(): bool
    {
        return (bool) auth()->user()?->canManageProductQualityFlags();
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
    protected static function typeOptions(): array
    {
        return [
            ProductQualityFlag::TYPE_MEDIA => 'Медия',
            ProductQualityFlag::TYPE_CONTENT => 'Съдържание',
            ProductQualityFlag::TYPE_SEO => 'SEO',
            ProductQualityFlag::TYPE_DATA => 'Каталог',
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
}

<?php

namespace App\Filament\Resources\CanonicalProductFamilies;

use App\Filament\Resources\CanonicalProductFamilies\Pages\CreateCanonicalProductFamily;
use App\Filament\Resources\CanonicalProductFamilies\Pages\EditCanonicalProductFamily;
use App\Filament\Resources\CanonicalProductFamilies\Pages\ListCanonicalProductFamilies;
use App\Models\CanonicalProductFamily;
use App\Models\User;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class CanonicalProductFamilyResource extends Resource
{
    protected static ?string $model = CanonicalProductFamily::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static ?string $navigationLabel = 'Вътрешни продуктови семейства';

    protected static string|UnitEnum|null $navigationGroup = 'Таксономия';

    public static function getModelLabel(): string
    {
        return 'вътрешно продуктово семейство';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Вътрешни продуктови семейства';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Вътрешно семейство')
                ->description('Канонична класификация за бъдещи шаблони. Не променя продукти или категории.')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('code')
                            ->label('Код')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(100),
                        TextInput::make('sort_order')
                            ->label('Ред')
                            ->numeric()
                            ->default(0)
                            ->required(),
                        TextInput::make('name_bg')
                            ->label('Име BG')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('name_en')
                            ->label('Име EN')
                            ->maxLength(255),
                        Toggle::make('active')
                            ->label('Активно')
                            ->default(true),
                    ]),
                    Textarea::make('description_bg')
                        ->label('Описание BG')
                        ->rows(3)
                        ->columnSpanFull(),
                    Textarea::make('description_en')
                        ->label('Описание EN')
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
                TextColumn::make('name_bg')->label('Име BG')->searchable()->sortable(),
                TextColumn::make('name_en')->label('Име EN')->searchable()->toggleable(),
                IconColumn::make('active')->label('Активно')->boolean()->sortable(),
                TextColumn::make('sort_order')->label('Ред')->sortable(),
                TextColumn::make('supplier_category_mappings_count')
                    ->counts('supplierCategoryMappings')
                    ->label('Картографирания')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('active')->label('Активно'),
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
            'index' => ListCanonicalProductFamilies::route('/'),
            'create' => CreateCanonicalProductFamily::route('/create'),
            'edit' => EditCanonicalProductFamily::route('/{record}/edit'),
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

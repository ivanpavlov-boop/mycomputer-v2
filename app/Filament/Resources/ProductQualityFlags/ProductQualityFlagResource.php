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

    protected static ?string $navigationLabel = 'Product Quality Flags';

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
                TextInput::make('code')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(100),
                TextInput::make('label_bg')
                    ->label('Bulgarian label')
                    ->required()
                    ->maxLength(255),
                TextInput::make('label_en')
                    ->label('English label')
                    ->maxLength(255),
                Select::make('severity')
                    ->options(ProductQualityFlag::severityOptions())
                    ->default(ProductQualityFlag::SEVERITY_MEDIUM)
                    ->required(),
                Select::make('responsible_role')
                    ->options(User::roleOptions())
                    ->searchable(),
                Select::make('type')
                    ->options(ProductQualityFlag::typeOptions())
                    ->searchable(),
                Toggle::make('is_active')
                    ->default(true),
                TextInput::make('sort_order')
                    ->numeric()
                    ->default(0)
                    ->required(),
                Textarea::make('description_bg')
                    ->label('Bulgarian description')
                    ->rows(3)
                    ->columnSpanFull(),
                Textarea::make('description_en')
                    ->label('English description')
                    ->rows(3)
                    ->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('label_bg')->label('Label')->searchable()->sortable(),
                TextColumn::make('severity')->badge()->sortable(),
                TextColumn::make('type')->badge()->sortable()->toggleable(),
                TextColumn::make('responsible_role')
                    ->formatStateUsing(fn (?string $state): string => User::roleLabel($state))
                    ->toggleable(),
                IconColumn::make('is_active')->boolean()->sortable(),
                TextColumn::make('sort_order')->sortable()->toggleable(),
                TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('severity')->options(ProductQualityFlag::severityOptions()),
                SelectFilter::make('type')->options(ProductQualityFlag::typeOptions()),
                SelectFilter::make('responsible_role')->options(User::roleOptions()),
                TernaryFilter::make('is_active'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
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
}

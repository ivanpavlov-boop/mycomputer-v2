<?php

namespace App\Filament\Resources\ProductCompareLists;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\ProductCompareLists\Pages\EditProductCompareList;
use App\Filament\Resources\ProductCompareLists\Pages\ListProductCompareLists;
use App\Models\Product;
use App\Models\ProductCompareList;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class ProductCompareListResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = ProductCompareList::class;

    protected static ?string $permission = 'view customers';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?string $navigationLabel = 'Compare Lists';

    protected static string|UnitEnum|null $navigationGroup = 'Customers';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('user_id')
                ->relationship('user', 'email')
                ->searchable()
                ->preload(),
            TextInput::make('session_id')->disabled(),
            TextInput::make('name')->maxLength(120),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.email')->label('User')->searchable()->sortable(),
                TextColumn::make('session_id')->searchable()->toggleable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('items_count')->counts('items')->label('Products')->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('user_id')
                    ->relationship('user', 'email')
                    ->searchable()
                    ->preload(),
                Filter::make('product_id')
                    ->schema([
                        Select::make('product_id')
                            ->label('Product')
                            ->options(fn (): array => Product::query()->orderBy('name')->limit(50)->pluck('name', 'id')->all())
                            ->searchable(),
                    ])
                    ->query(fn ($query, array $data) => $query->when(
                        $data['product_id'] ?? null,
                        fn ($query, int $productId) => $query->whereHas('items', fn ($query) => $query->where('product_id', $productId))
                    )),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductCompareLists::route('/'),
            'edit' => EditProductCompareList::route('/{record}/edit'),
        ];
    }
}

<?php

namespace App\Filament\Resources\Wishlists;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\Wishlists\Pages\EditWishlist;
use App\Filament\Resources\Wishlists\Pages\ListWishlists;
use App\Models\Product;
use App\Models\Wishlist;
use BackedEnum;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class WishlistResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = Wishlist::class;

    protected static ?string $permission = 'view customers';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static ?string $navigationLabel = 'Wishlists';

    protected static string|UnitEnum|null $navigationGroup = 'Customers';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('user_id')
                ->relationship('user', 'email')
                ->searchable()
                ->preload()
                ->required(),
            TextInput::make('name')->required()->maxLength(120),
            Checkbox::make('is_default'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.email')->label('User')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                IconColumn::make('is_default')->boolean()->sortable(),
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
            'index' => ListWishlists::route('/'),
            'edit' => EditWishlist::route('/{record}/edit'),
        ];
    }
}

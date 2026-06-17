<?php

namespace App\Filament\Resources\QuoteRequestItems;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\QuoteRequestItems\Pages\CreateQuoteRequestItem;
use App\Filament\Resources\QuoteRequestItems\Pages\EditQuoteRequestItem;
use App\Filament\Resources\QuoteRequestItems\Pages\ListQuoteRequestItems;
use App\Models\Product;
use App\Models\QuoteRequest;
use App\Models\QuoteRequestItem;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class QuoteRequestItemResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = QuoteRequestItem::class;

    protected static ?string $permission = 'manage quotes';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static ?string $navigationLabel = 'Quote Items';

    protected static string|UnitEnum|null $navigationGroup = 'B2B';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('quote_request_id')->label('Quote')->options(fn () => QuoteRequest::query()->pluck('quote_number', 'id'))->searchable()->required(),
            Select::make('product_id')->label('Product')->options(fn () => Product::query()->pluck('name', 'id'))->searchable(),
            TextInput::make('product_name')->required(),
            TextInput::make('sku'),
            TextInput::make('quantity')->numeric()->required(),
            TextInput::make('requested_price')->numeric()->prefix('EUR'),
            TextInput::make('offered_price')->numeric()->prefix('EUR'),
            TextInput::make('line_total')->numeric()->prefix('EUR'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('quote.quote_number')->searchable(),
            TextColumn::make('product_name')->searchable(),
            TextColumn::make('sku')->searchable(),
            TextColumn::make('quantity')->sortable(),
            TextColumn::make('offered_price')->money('EUR')->sortable(),
            TextColumn::make('line_total')->money('EUR')->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQuoteRequestItems::route('/'),
            'create' => CreateQuoteRequestItem::route('/create'),
            'edit' => EditQuoteRequestItem::route('/{record}/edit'),
        ];
    }
}

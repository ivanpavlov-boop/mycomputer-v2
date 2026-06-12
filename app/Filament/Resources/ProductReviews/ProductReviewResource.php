<?php

namespace App\Filament\Resources\ProductReviews;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\ProductReviews\Pages\EditProductReview;
use App\Filament\Resources\ProductReviews\Pages\ListProductReviews;
use App\Models\Product;
use App\Models\ProductReview;
use App\Services\Reviews\ReviewModerationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use UnitEnum;

class ProductReviewResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = ProductReview::class;

    protected static ?string $permission = 'manage products';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    protected static ?string $navigationLabel = 'Product Reviews';

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Review')->schema([
                Grid::make(3)->schema([
                    Select::make('product_id')->relationship('product', 'name')->searchable()->preload()->required(),
                    Select::make('user_id')->relationship('user', 'email')->searchable()->preload(),
                    Select::make('order_id')->relationship('order', 'order_number')->searchable()->preload(),
                    TextInput::make('customer_name')->required(),
                    TextInput::make('customer_email')->email()->required(),
                    Select::make('rating')->options([1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5])->required(),
                    Select::make('status')->options(array_combine(ProductReview::STATUSES, ProductReview::STATUSES))->required(),
                    TextInput::make('title')->maxLength(160),
                ]),
                Textarea::make('comment')->required()->rows(4)->columnSpanFull(),
                Textarea::make('pros')->rows(2)->columnSpanFull(),
                Textarea::make('cons')->rows(2)->columnSpanFull(),
                Textarea::make('rejection_reason')->rows(2)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product.name')->searchable()->sortable()->limit(40),
                TextColumn::make('customer_name')->searchable(),
                TextColumn::make('customer_email')->searchable()->toggleable(),
                TextColumn::make('rating')->badge()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                IconColumn::make('is_verified_purchase')->boolean()->sortable(),
                TextColumn::make('reports_count')->counts('reports')->label('Reports')->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(array_combine(ProductReview::STATUSES, ProductReview::STATUSES)),
                SelectFilter::make('rating')->options([1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5]),
                Filter::make('product_id')
                    ->schema([
                        Select::make('product_id')
                            ->label('Product')
                            ->options(fn (): array => Product::query()->orderBy('name')->limit(50)->pluck('name', 'id')->all())
                            ->searchable(),
                    ])
                    ->query(fn ($query, array $data) => $query->when($data['product_id'] ?? null, fn ($query, int $productId) => $query->where('product_id', $productId))),
                SelectFilter::make('is_verified_purchase')->options([1 => 'Verified', 0 => 'Not verified']),
                TrashedFilter::make(),
            ])
            ->recordActions([
                Action::make('approve')
                    ->icon('heroicon-o-check-circle')
                    ->visible(fn (ProductReview $record): bool => $record->status !== 'approved')
                    ->requiresConfirmation()
                    ->action(fn (ProductReview $record, ReviewModerationService $moderation) => $moderation->approve($record)),
                Action::make('reject')
                    ->icon('heroicon-o-x-circle')
                    ->schema([Textarea::make('reason')->maxLength(1000)])
                    ->action(fn (ProductReview $record, array $data, ReviewModerationService $moderation) => $moderation->reject($record, $data['reason'] ?? null)),
                Action::make('spam')
                    ->icon('heroicon-o-shield-exclamation')
                    ->requiresConfirmation()
                    ->action(fn (ProductReview $record, ReviewModerationService $moderation) => $moderation->spam($record)),
                DeleteAction::make(),
                RestoreAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductReviews::route('/'),
            'edit' => EditProductReview::route('/{record}/edit'),
        ];
    }
}

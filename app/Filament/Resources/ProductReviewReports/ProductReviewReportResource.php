<?php

namespace App\Filament\Resources\ProductReviewReports;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\ProductReviewReports\Pages\EditProductReviewReport;
use App\Filament\Resources\ProductReviewReports\Pages\ListProductReviewReports;
use App\Models\ProductReviewReport;
use App\Services\Reviews\ReviewModerationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class ProductReviewReportResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = ProductReviewReport::class;

    protected static ?string $permission = 'manage products';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static ?string $navigationLabel = 'Review Reports';

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Report')->schema([
                Grid::make(2)->schema([
                    Select::make('product_review_id')->relationship('review', 'title')->searchable()->preload()->required(),
                    Select::make('user_id')->relationship('user', 'email')->searchable()->preload(),
                    Select::make('status')->options(array_combine(ProductReviewReport::STATUSES, ProductReviewReport::STATUSES))->required(),
                    Textarea::make('reason')->required(),
                ]),
                Textarea::make('message')->rows(3)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('review.product.name')->label('Product')->limit(40)->searchable(),
                TextColumn::make('review.customer_name')->label('Reviewer')->searchable(),
                TextColumn::make('reason')->searchable()->limit(40),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(array_combine(ProductReviewReport::STATUSES, ProductReviewReport::STATUSES)),
            ])
            ->recordActions([
                Action::make('markReviewed')
                    ->label('Reviewed')
                    ->icon('heroicon-o-check-circle')
                    ->action(fn (ProductReviewReport $record, ReviewModerationService $moderation) => $moderation->markReportReviewed($record)),
                Action::make('dismiss')
                    ->icon('heroicon-o-x-circle')
                    ->action(fn (ProductReviewReport $record, ReviewModerationService $moderation) => $moderation->dismissReport($record)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductReviewReports::route('/'),
            'edit' => EditProductReviewReport::route('/{record}/edit'),
        ];
    }
}

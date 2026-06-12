<?php

namespace App\Filament\Resources\FeedExports;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\FeedExports\Pages\ListFeedExports;
use App\Models\FeedExport;
use App\Services\Marketing\FacebookCatalogService;
use App\Services\Marketing\MerchantFeedService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class FeedExportResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = FeedExport::class;

    protected static ?string $permission = 'manage marketing';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRss;

    protected static ?string $navigationLabel = 'Feed Exports';

    protected static string|UnitEnum|null $navigationGroup = 'Marketing';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('feed_type')->badge()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('products_count')->numeric()->sortable(),
                TextColumn::make('file_path')->copyable(),
                TextColumn::make('generated_at')->dateTime()->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('feed_type')->options(array_combine(FeedExport::TYPES, FeedExport::TYPES)),
                SelectFilter::make('status')->options(array_combine(FeedExport::STATUSES, FeedExport::STATUSES)),
            ])
            ->headerActions([
                Action::make('generate_google')
                    ->label('Generate Google Merchant')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->action(function (): void {
                        app(MerchantFeedService::class)->generate();
                        Notification::make()->title('Google Merchant feed generated')->success()->send();
                    }),
                Action::make('generate_facebook')
                    ->label('Generate Facebook Catalog')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->action(function (): void {
                        app(FacebookCatalogService::class)->generate();
                        Notification::make()->title('Facebook catalog feed generated')->success()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFeedExports::route('/'),
        ];
    }
}

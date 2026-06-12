<?php

namespace App\Filament\Pages;

use App\Services\Search\Contracts\SearchServiceInterface;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class SearchManagementPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static ?string $navigationLabel = 'Search Dashboard';

    protected static string|UnitEnum|null $navigationGroup = 'Search';

    protected string $view = 'filament.pages.search-management-page';

    public function getStatus(): array
    {
        return app(SearchServiceInterface::class)->status();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reindex')
                ->label('Reindex products')
                ->icon(Heroicon::OutlinedArrowPath)
                ->requiresConfirmation()
                ->action(function (): void {
                    $count = app(SearchServiceInterface::class)->reindex();

                    Notification::make()
                        ->title("Queued {$count} products for indexing")
                        ->success()
                        ->send();
                }),
            Action::make('flush')
                ->label('Flush index')
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->requiresConfirmation()
                ->action(function (): void {
                    app(SearchServiceInterface::class)->flush();

                    Notification::make()
                        ->title('Search index flushed')
                        ->success()
                        ->send();
                }),
        ];
    }
}

<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\RequestAdminPasswordReset;
use App\Filament\Pages\Auth\ResetAdminPassword;
use App\Filament\Widgets\AiUsageStats;
use App\Filament\Widgets\ProductSyncStats;
use App\Filament\Widgets\RecentReviews;
use App\Filament\Widgets\RecentSupplierFeeds;
use App\Filament\Widgets\ReviewStats;
use App\Filament\Widgets\SupplierImportStats;
use App\Filament\Widgets\SupplierStats;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->passwordReset(
                requestAction: RequestAdminPasswordReset::class,
                resetAction: ResetAdminPassword::class,
            )
            ->colors([
                'primary' => Color::Amber,
            ])
            ->maxContentWidth(Width::Full)
            ->renderHook(
                PanelsRenderHook::STYLES_AFTER,
                fn (): HtmlString => new HtmlString(<<<'HTML'
                    <style>
                        .fi-main-ctn > .fi-main.fi-width-full {
                            width: calc(100% - 2rem);
                        }

                        @media (min-width: 1024px) {
                            .fi-main-ctn > .fi-main.fi-width-full {
                                width: calc(100% - 3rem);
                            }
                        }
                    </style>
                    HTML),
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                SupplierStats::class,
                SupplierImportStats::class,
                ProductSyncStats::class,
                AiUsageStats::class,
                ReviewStats::class,
                RecentReviews::class,
                RecentSupplierFeeds::class,
                FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}

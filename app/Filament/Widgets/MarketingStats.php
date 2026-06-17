<?php

namespace App\Filament\Widgets;

use App\Models\AbandonedCartRecord;
use App\Models\MarketingEvent;
use App\Models\Order;
use App\Models\OrderBundleItem;
use App\Models\Product;
use App\Models\ProductBundle;
use App\Models\Promotion;
use App\Models\PromotionRedemption;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class MarketingStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $abandoned = AbandonedCartRecord::query()->count();
        $recovered = AbandonedCartRecord::query()->where('status', 'recovered')->count();
        $recoveryRate = $abandoned > 0 ? round(($recovered / $abandoned) * 100, 1).'%' : '0%';

        return [
            Stat::make('Total revenue', number_format((float) Order::query()->sum('grand_total'), 2).' EUR'),
            Stat::make('Orders today', Order::query()->whereDate('created_at', today())->count()),
            Stat::make('Tracked events', MarketingEvent::query()->count()),
            Stat::make('Abandoned carts', $abandoned),
            Stat::make('Recovered carts', $recovered),
            Stat::make('Recovery rate', $recoveryRate),
            Stat::make('Recovered revenue', number_format((float) AbandonedCartRecord::query()->sum('recovered_revenue'), 2).' EUR'),
            Stat::make('Pending recovery emails', AbandonedCartRecord::query()
                ->whereNotIn('status', ['recovered', 'expired', 'suppressed'])
                ->where('emails_sent', '<', 3)
                ->count()),
            Stat::make('Active promotions', Promotion::query()->available()->count()),
            Stat::make('Promotion discounts', number_format((float) PromotionRedemption::query()->sum('discount_amount'), 2).' EUR'),
            Stat::make('Active bundles', ProductBundle::query()->available()->count()),
            Stat::make('Bundle revenue', number_format((float) OrderBundleItem::query()->sum('total_price'), 2).' EUR'),
            Stat::make('Products without images', Product::query()->published()->doesntHave('images')->count()),
        ];
    }
}

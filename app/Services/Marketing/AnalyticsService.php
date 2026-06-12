<?php

namespace App\Services\Marketing;

use App\Models\AbandonedCartRecord;
use App\Models\Category;
use App\Models\MarketingEvent;
use App\Models\Order;
use App\Models\Product;

class AnalyticsService
{
    public function dashboard(): array
    {
        $ordersToday = Order::query()->whereDate('created_at', today())->count();
        $totalRevenue = (float) Order::query()->sum('grand_total');
        $events = MarketingEvent::query()->count();
        $abandoned = AbandonedCartRecord::query()->count();
        $recovered = AbandonedCartRecord::query()->where('status', 'recovered')->count();

        return [
            'total_revenue' => $totalRevenue,
            'orders_today' => $ordersToday,
            'conversion_rate' => null,
            'conversion_rate_note' => 'Placeholder until session and consent-based attribution are enabled.',
            'top_categories' => Category::query()->withCount('products')->orderByDesc('products_count')->limit(5)->get(['id', 'name', 'slug']),
            'top_products' => Product::query()->published()->orderByDesc('bestseller')->orderByDesc('updated_at')->limit(5)->get(['id', 'name', 'slug', 'sku']),
            'most_searched_terms' => MarketingEvent::query()
                ->where('event_name', 'search')
                ->latest()
                ->limit(10)
                ->get()
                ->map(fn (MarketingEvent $event): ?string => $event->payload['query'] ?? $event->payload['q'] ?? null)
                ->filter()
                ->values(),
            'abandoned_carts' => [
                'total' => $abandoned,
                'recovered' => $recovered,
                'recovery_rate' => $abandoned > 0 ? round(($recovered / $abandoned) * 100, 2) : 0,
                'recovered_revenue' => (float) AbandonedCartRecord::query()->sum('recovered_revenue'),
                'pending_emails' => AbandonedCartRecord::query()
                    ->whereNotIn('status', ['recovered', 'expired', 'suppressed'])
                    ->where('emails_sent', '<', 3)
                    ->count(),
            ],
            'tracked_events' => $events,
        ];
    }
}

<?php

namespace App\Services\Marketing\Providers;

use App\Models\ConversionLog;
use App\Models\MarketingEvent;
use App\Services\Marketing\Contracts\MarketingProviderInterface;

class GoogleAnalyticsProvider implements MarketingProviderInterface
{
    public function source(): string
    {
        return 'ga4';
    }

    public function supports(string $eventName): bool
    {
        return in_array($eventName, [
            'page_view',
            'view_item',
            'view_item_list',
            'search',
            'add_to_cart',
            'remove_from_cart',
            'begin_checkout',
            'add_payment_info',
            'purchase',
            'sign_up',
            'login',
        ], true);
    }

    public function track(MarketingEvent $event): array
    {
        return ['status' => 'skipped', 'reason' => 'GA4 Measurement Protocol is not configured yet.'];
    }

    public function convert(ConversionLog $conversion): array
    {
        return ['status' => 'skipped', 'reason' => 'GA4 server-side conversion dispatch is not configured yet.'];
    }
}

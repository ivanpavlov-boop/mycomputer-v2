<?php

namespace App\Services\Marketing\Providers;

use App\Models\ConversionLog;
use App\Models\MarketingEvent;
use App\Services\Marketing\Contracts\MarketingProviderInterface;

class GoogleMerchantProvider implements MarketingProviderInterface
{
    public function source(): string
    {
        return 'merchant';
    }

    public function supports(string $eventName): bool
    {
        return in_array($eventName, ['feed_generated', 'product_disapproved', 'product_synced'], true);
    }

    public function track(MarketingEvent $event): array
    {
        return ['status' => 'skipped', 'reason' => 'Merchant Center API sync is not configured yet.'];
    }

    public function convert(ConversionLog $conversion): array
    {
        return ['status' => 'skipped', 'reason' => 'Merchant conversion logging is feed-only for now.'];
    }
}

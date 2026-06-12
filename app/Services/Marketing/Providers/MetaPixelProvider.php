<?php

namespace App\Services\Marketing\Providers;

use App\Models\ConversionLog;
use App\Models\MarketingEvent;
use App\Services\Marketing\Contracts\MarketingProviderInterface;

class MetaPixelProvider implements MarketingProviderInterface
{
    public function source(): string
    {
        return 'meta';
    }

    public function supports(string $eventName): bool
    {
        return in_array($eventName, [
            'PageView',
            'ViewContent',
            'Search',
            'AddToCart',
            'InitiateCheckout',
            'AddPaymentInfo',
            'Purchase',
            'CompleteRegistration',
        ], true);
    }

    public function track(MarketingEvent $event): array
    {
        return ['status' => 'skipped', 'reason' => 'Client-side Meta Pixel dispatch is handled by Nuxt when consent is granted.'];
    }

    public function convert(ConversionLog $conversion): array
    {
        return ['status' => 'skipped', 'reason' => 'Use MetaConversionApiProvider for server-side conversions.'];
    }
}

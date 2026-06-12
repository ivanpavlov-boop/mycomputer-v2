<?php

namespace App\Services\Marketing\Providers;

use App\Models\ConversionLog;
use App\Models\MarketingEvent;
use App\Services\Marketing\Contracts\MarketingProviderInterface;

class MetaConversionApiProvider implements MarketingProviderInterface
{
    public function source(): string
    {
        return 'meta';
    }

    public function supports(string $eventName): bool
    {
        return in_array($eventName, ['Purchase', 'AddToCart', 'InitiateCheckout', 'ViewContent'], true);
    }

    public function track(MarketingEvent $event): array
    {
        return ['status' => 'skipped', 'reason' => 'Meta CAPI is conversion-log driven.'];
    }

    public function convert(ConversionLog $conversion): array
    {
        return ['status' => 'skipped', 'reason' => 'META_CAPI_TOKEN and META_PIXEL_ID are not configured yet.'];
    }
}

<?php

namespace App\Services\Shipping;

use App\Models\ShippingProvider;
use App\Services\Shipping\Contracts\ShippingProviderInterface;
use App\Services\Shipping\Providers\EcontShippingProvider;
use App\Services\Shipping\Providers\ManualShippingProvider;
use App\Services\Shipping\Providers\SpeedyShippingProvider;

class ShippingService
{
    public function provider(ShippingProvider|string $provider): ShippingProviderInterface
    {
        $code = $provider instanceof ShippingProvider ? $provider->code : $provider;

        return match ($code) {
            'speedy' => new SpeedyShippingProvider,
            'econt' => new EcontShippingProvider,
            default => new ManualShippingProvider,
        };
    }

    public function activeProvider(string $code): ShippingProvider
    {
        return ShippingProvider::query()
            ->where('code', $code)
            ->where('status', 'active')
            ->firstOrFail();
    }
}

<?php

namespace App\Services\Shipping;

use App\Models\ShippingMethod;
use App\Models\ShippingOffice;

class ShippingPriceService
{
    public function __construct(private readonly ShippingService $shippingService) {}

    public function calculate(array $data, float $subtotal = 0): array
    {
        $provider = $this->shippingService->activeProvider($data['provider'] ?? 'manual');
        $method = ShippingMethod::query()
            ->whereBelongsTo($provider, 'provider')
            ->where('code', $data['shipping_method'] ?? $data['delivery_type'])
            ->where('status', 'active')
            ->firstOrFail();

        if (($data['delivery_type'] ?? null) === 'office') {
            $office = ShippingOffice::query()
                ->whereBelongsTo($provider, 'provider')
                ->where('id', $data['office_id'] ?? null)
                ->where('status', 'active')
                ->firstOrFail();
            $data['office'] = $office->toArray();
        }

        $price = (float) $method->price;
        if ($method->free_shipping_threshold !== null && $subtotal >= (float) $method->free_shipping_threshold) {
            $price = 0.0;
        }

        return $this->shippingService->provider($provider)->calculatePrice(array_merge($data, [
            'method_price' => $price,
            'shipping_method_code' => $method->code,
            'subtotal' => $subtotal,
        ])) + [
            'shipping_provider_id' => $provider->id,
            'shipping_method_id' => $method->id,
        ];
    }
}

<?php

namespace Database\Seeders;

use App\Models\ShippingProvider;
use App\Services\Shipping\ShippingOfficeService;
use Illuminate\Database\Seeder;

class ShippingSeeder extends Seeder
{
    public function run(): void
    {
        $providers = [
            ['name' => 'Manual Delivery', 'code' => 'manual'],
            ['name' => 'Speedy', 'code' => 'speedy'],
            ['name' => 'Econt', 'code' => 'econt'],
        ];

        foreach ($providers as $providerData) {
            $provider = ShippingProvider::query()->updateOrCreate(
                ['code' => $providerData['code']],
                ['name' => $providerData['name'], 'status' => 'active', 'settings' => ['mock' => true]],
            );

            foreach ([['office', 'Office delivery', 0], ['address', 'Address delivery', 8.99]] as [$type, $name, $price]) {
                $provider->methods()->updateOrCreate(
                    ['code' => $type],
                    [
                        'name' => $name,
                        'type' => $type,
                        'status' => 'active',
                        'price' => $provider->code === 'manual' && $type === 'office' ? 0 : $price,
                        'free_shipping_threshold' => 500,
                        'sort_order' => $type === 'office' ? 1 : 2,
                    ],
                );
            }

            if ($provider->code !== 'manual') {
                app(ShippingOfficeService::class)->sync($provider);
            }
        }
    }
}

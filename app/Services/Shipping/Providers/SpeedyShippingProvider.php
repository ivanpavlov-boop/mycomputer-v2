<?php

namespace App\Services\Shipping\Providers;

class SpeedyShippingProvider extends ManualShippingProvider
{
    public function getOffices(): array
    {
        return [
            ['office_id' => 'SPD-SOF-001', 'name' => 'Speedy Sofia Center', 'city' => 'Sofia', 'postcode' => '1000', 'address' => 'bul. Vitosha 1'],
            ['office_id' => 'SPD-PDV-001', 'name' => 'Speedy Plovdiv Central', 'city' => 'Plovdiv', 'postcode' => '4000', 'address' => 'ul. Ivan Vazov 10'],
        ];
    }
}

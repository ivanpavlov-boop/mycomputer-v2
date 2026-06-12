<?php

namespace App\Services\Shipping\Providers;

class EcontShippingProvider extends ManualShippingProvider
{
    public function getOffices(): array
    {
        return [
            ['office_id' => 'ECONT-SOF-001', 'name' => 'Econt Sofia Mladost', 'city' => 'Sofia', 'postcode' => '1784', 'address' => 'Mladost 1'],
            ['office_id' => 'ECONT-VAR-001', 'name' => 'Econt Varna Center', 'city' => 'Varna', 'postcode' => '9000', 'address' => 'ul. Knyaz Boris I 20'],
        ];
    }
}

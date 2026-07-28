<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use App\Models\PaymentProvider;
use Illuminate\Database\Seeder;

class PaymentSeeder extends Seeder
{
    public function run(): void
    {
        $manual = PaymentProvider::query()->firstOrCreate(
            ['code' => 'manual'],
            ['name' => 'Manual Payments', 'status' => 'active', 'settings' => ['mock' => true]],
        );

        $methods = [
            ['name' => 'Наложен платеж', 'code' => 'cash_on_delivery', 'type' => 'offline', 'description' => 'Плащане при доставка.', 'instructions' => null, 'sort_order' => 1],
            ['name' => 'Банков превод', 'code' => 'bank_transfer', 'type' => 'offline', 'description' => 'Плащане по банков път.', 'instructions' => 'Очаквайте банкови данни и основание за плащане в потвърждението.', 'sort_order' => 2],
            ['name' => 'Карта', 'code' => 'card', 'type' => 'online', 'description' => 'Плащане с карта, placeholder за myPOS/BORICA/Stripe.', 'instructions' => null, 'sort_order' => 3],
            ['name' => 'Покупка на изплащане', 'code' => 'leasing', 'type' => 'leasing', 'description' => 'Изпращане на заявка за покупка на изплащане. Наш служител ще се свърже с клиента.', 'instructions' => 'Получихме заявката Ви за покупка на изплащане. Наш служител ще се свърже с Вас.', 'sort_order' => 4],
        ];

        foreach ($methods as $method) {
            if ($method['code'] === 'card') {
                $method['description'] = 'Requires a separately approved card provider configuration.';
            }

            PaymentMethod::query()->firstOrCreate(
                ['code' => $method['code']],
                [
                    'payment_provider_id' => $manual->id,
                    'status' => in_array($method['code'], ['card', 'leasing'], true) ? 'inactive' : 'active',
                    'settings' => match ($method['code']) {
                        'card' => ['requires_provider_configuration' => true],
                        'leasing' => ['mode' => 'manual_leasing_application'],
                        default => ['mock' => true],
                    },
                ] + $method,
            );
        }
    }
}

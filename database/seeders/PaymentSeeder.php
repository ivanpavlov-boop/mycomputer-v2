<?php

namespace Database\Seeders;

use App\Models\PaymentProvider;
use Illuminate\Database\Seeder;

class PaymentSeeder extends Seeder
{
    public function run(): void
    {
        $manual = PaymentProvider::query()->updateOrCreate(
            ['code' => 'manual'],
            ['name' => 'Manual Payments', 'status' => 'active', 'settings' => ['mock' => true]],
        );

        $methods = [
            ['name' => 'Наложен платеж', 'code' => 'cash_on_delivery', 'type' => 'offline', 'description' => 'Плащане при доставка.', 'instructions' => null, 'sort_order' => 1],
            ['name' => 'Банков превод', 'code' => 'bank_transfer', 'type' => 'offline', 'description' => 'Плащане по банков път.', 'instructions' => 'Очаквайте банкови данни и основание за плащане в потвърждението.', 'sort_order' => 2],
            ['name' => 'Карта', 'code' => 'card', 'type' => 'online', 'description' => 'Плащане с карта, placeholder за myPOS/BORICA/Stripe.', 'instructions' => null, 'sort_order' => 3],
            ['name' => 'Лизинг', 'code' => 'leasing', 'type' => 'leasing', 'description' => 'Лизингова заявка, placeholder за TBI/UniCredit/BNP.', 'instructions' => 'След поръчка ще продължите към лизингова заявка.', 'sort_order' => 4],
        ];

        foreach ($methods as $method) {
            $manual->methods()->updateOrCreate(
                ['code' => $method['code']],
                ['status' => 'active', 'settings' => ['mock' => true]] + $method,
            );
        }
    }
}

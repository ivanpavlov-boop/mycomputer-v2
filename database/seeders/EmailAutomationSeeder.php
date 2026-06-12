<?php

namespace Database\Seeders;

use App\Models\EmailAutomation;
use Illuminate\Database\Seeder;

class EmailAutomationSeeder extends Seeder
{
    public function run(): void
    {
        $automations = [
            ['Welcome email', 'account_registered', ['template' => 'welcome', 'delay_minutes' => 0]],
            ['Abandoned cart reminder 1', 'abandoned_cart', ['template' => 'abandoned_cart_1', 'delay_hours' => 1]],
            ['Abandoned cart reminder 2', 'abandoned_cart', ['template' => 'abandoned_cart_2', 'delay_hours' => 24]],
            ['Abandoned cart reminder 3', 'abandoned_cart', ['template' => 'abandoned_cart_3', 'delay_hours' => 72]],
            ['Order created', 'order_created', ['template' => 'order_created', 'delay_minutes' => 0]],
            ['Order paid', 'order_paid', ['template' => 'order_paid', 'delay_minutes' => 0]],
            ['Order shipped', 'order_shipped', ['template' => 'order_shipped', 'delay_minutes' => 0]],
            ['Order delivered', 'order_delivered', ['template' => 'order_delivered', 'delay_minutes' => 0]],
            ['Order cancelled', 'order_cancelled', ['template' => 'order_cancelled', 'delay_minutes' => 0]],
            ['Review request', 'review_request', ['template' => 'review_request', 'delay_days' => 7]],
            ['Wishlist reminder', 'wishlist_reminder', ['template' => 'wishlist_reminder', 'delay_days' => 30]],
            ['Price drop alert', 'price_drop', ['template' => 'price_drop']],
            ['Back in stock alert', 'back_in_stock', ['template' => 'back_in_stock']],
        ];

        foreach ($automations as [$name, $trigger, $configuration]) {
            EmailAutomation::query()->updateOrCreate(
                ['name' => $name, 'trigger' => $trigger],
                ['enabled' => true, 'configuration' => $configuration],
            );
        }
    }
}

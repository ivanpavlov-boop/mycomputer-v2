<?php

namespace Database\Seeders;

use App\Models\AvailabilityStatus;
use Illuminate\Database\Seeder;

class AvailabilityStatusSeeder extends Seeder
{
    public function run(): void
    {
        $statuses = [
            ['code' => 'in_stock', 'name' => 'In Stock', 'color' => 'green', 'icon' => 'check', 'allow_purchase' => true, 'show_stock_quantity' => true, 'is_default' => true, 'sort_order' => 10],
            ['code' => 'limited_stock', 'name' => 'Limited Stock', 'color' => 'orange', 'icon' => 'warning', 'allow_purchase' => true, 'show_stock_quantity' => true, 'sort_order' => 20],
            ['code' => 'incoming', 'name' => 'Incoming', 'color' => 'blue', 'icon' => 'truck', 'allow_purchase' => false, 'show_stock_quantity' => false, 'sort_order' => 30],
            ['code' => 'preorder', 'name' => 'Preorder', 'color' => 'blue', 'icon' => 'clock', 'allow_purchase' => true, 'show_stock_quantity' => false, 'sort_order' => 40],
            ['code' => 'on_request', 'name' => 'On Request', 'color' => 'yellow', 'icon' => 'package', 'allow_purchase' => false, 'show_stock_quantity' => false, 'sort_order' => 50],
            ['code' => 'out_of_stock', 'name' => 'Out Of Stock', 'color' => 'red', 'icon' => 'warning', 'allow_purchase' => false, 'show_stock_quantity' => false, 'sort_order' => 60],
            ['code' => 'discontinued', 'name' => 'Discontinued', 'color' => 'red', 'icon' => 'warning', 'allow_purchase' => false, 'show_stock_quantity' => false, 'sort_order' => 70],
        ];

        foreach ($statuses as $status) {
            AvailabilityStatus::query()->updateOrCreate(
                ['code' => $status['code']],
                ['badge_style' => 'soft', 'is_active' => true] + $status,
            );
        }
    }
}

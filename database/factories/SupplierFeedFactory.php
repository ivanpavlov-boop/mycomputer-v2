<?php

namespace Database\Factories;

use App\Models\Supplier;
use App\Models\SupplierFeed;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierFeed>
 */
class SupplierFeedFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'feed_name' => 'Main XML Feed',
            'feed_type' => 'xml',
            'feed_url' => $this->faker->url(),
            'username' => null,
            'password' => null,
            'update_interval' => 'manual',
            'mapping' => [
                'sku' => 'product.code',
                'name' => 'product.name',
                'price' => 'product.price',
                'stock' => 'product.stock',
            ],
            'status' => 'active',
        ];
    }
}

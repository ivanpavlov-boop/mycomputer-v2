<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(4, true);

        return [
            'category_id' => Category::factory(),
            'brand_id' => Brand::factory(),
            'supplier_id' => Supplier::factory(),
            'sku' => strtoupper(fake()->unique()->bothify('MC-####??')),
            'supplier_sku' => strtoupper(fake()->bothify('SUP-####??')),
            'ean' => fake()->ean13(),
            'mpn' => strtoupper(fake()->bothify('MPN-####??')),
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'short_description' => fake()->sentence(),
            'description' => fake()->paragraph(),
            'weight' => fake()->randomFloat(3, 0.1, 10),
            'purchase_price' => fake()->randomFloat(2, 30, 3500),
            'price' => fake()->randomFloat(2, 49, 4999),
            'promo_price' => null,
            'quantity' => fake()->numberBetween(0, 50),
            'reserved_quantity' => 0,
            'stock_status' => 'in_stock',
            'product_status' => 'active',
            'warranty_months' => fake()->randomElement([12, 24, 36]),
            'active' => true,
            'featured' => fake()->boolean(20),
            'new_product' => fake()->boolean(20),
            'bestseller' => fake()->boolean(10),
            'meta_title' => Str::title($name),
            'meta_description' => fake()->sentence(),
            'specifications' => [],
            'published_at' => now(),
        ];
    }
}

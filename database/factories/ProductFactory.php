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
        $name = $this->faker->unique()->words(4, true);

        return [
            'category_id' => Category::factory(),
            'brand_id' => Brand::factory(),
            'supplier_id' => Supplier::factory(),
            'sku' => strtoupper($this->faker->unique()->bothify('MC-####??')),
            'supplier_sku' => strtoupper($this->faker->bothify('SUP-####??')),
            'ean' => $this->faker->ean13(),
            'mpn' => strtoupper($this->faker->bothify('MPN-####??')),
            'name' => Str::title($name),
            'lock_name' => false,
            'slug' => Str::slug($name),
            'short_description' => $this->faker->sentence(),
            'description' => $this->faker->paragraph(),
            'lock_descriptions' => false,
            'weight' => $this->faker->randomFloat(3, 0.1, 10),
            'purchase_price' => $this->faker->randomFloat(2, 30, 3500),
            'regular_price' => null,
            'source' => Product::SOURCE_MANUAL,
            'apply_pricing_rules' => false,
            'price_source' => Product::PRICE_SOURCE_MANUAL,
            'price' => $this->faker->randomFloat(2, 49, 4999),
            'promo_price' => null,
            'sale_price' => null,
            'sale_price_starts_at' => null,
            'sale_price_ends_at' => null,
            'sale_price_source' => null,
            'quantity' => $this->faker->numberBetween(0, 50),
            'reserved_quantity' => 0,
            'stock_status' => 'in_stock',
            'product_status' => 'active',
            'workflow_status' => Product::WORKFLOW_PUBLISHED,
            'warranty_months' => $this->faker->randomElement([12, 24, 36]),
            'active' => true,
            'featured' => $this->faker->boolean(20),
            'new_product' => $this->faker->boolean(20),
            'bestseller' => $this->faker->boolean(10),
            'meta_title' => Str::title($name),
            'meta_description' => $this->faker->sentence(),
            'lock_seo' => false,
            'specifications' => [],
            'published_at' => now(),
        ];
    }

    public function manualDraft(): static
    {
        return $this->state(fn (): array => [
            'source' => Product::SOURCE_MANUAL,
            'workflow_status' => Product::WORKFLOW_DRAFT,
            'product_status' => 'draft',
            'active' => false,
            'published_at' => null,
        ]);
    }

    public function supplierPublished(): static
    {
        return $this->state(fn (): array => [
            'source' => Product::SOURCE_SUPPLIER_IMPORT,
            'workflow_status' => Product::WORKFLOW_PUBLISHED,
            'product_status' => 'active',
            'active' => true,
            'published_at' => now(),
            'price_source' => Product::PRICE_SOURCE_SUPPLIER_IMPORT,
        ]);
    }
}

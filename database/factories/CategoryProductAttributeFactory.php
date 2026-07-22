<?php

namespace Database\Factories;

use App\Enums\CategoryAttributeFilterControl;
use App\Models\Category;
use App\Models\CategoryProductAttribute;
use App\Models\ProductAttribute;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CategoryProductAttribute>
 */
class CategoryProductAttributeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'product_attribute_id' => ProductAttribute::factory(),
            'is_required' => false,
            'is_filterable' => false,
            'filter_control_type' => CategoryAttributeFilterControl::Auto->value,
            'is_visible_on_product' => true,
            'is_comparable' => false,
            'sort_order' => $this->faker->numberBetween(0, 100),
        ];
    }
}

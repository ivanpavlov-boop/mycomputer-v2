<?php

namespace Database\Factories;

use App\Models\AttributeGroup;
use App\Models\ProductAttribute;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductAttribute>
 */
class ProductAttributeFactory extends Factory
{
    public function definition(): array
    {
        $label = $this->faker->unique()->words(2, true);
        $code = Str::slug($label, '_');

        return [
            'attribute_group_id' => AttributeGroup::factory(),
            'code' => $code,
            'name' => Str::title($label),
            'name_bg' => Str::title($label),
            'name_en' => Str::title($label),
            'slug' => Str::slug($label),
            'type' => $this->faker->randomElement(ProductAttribute::TYPES),
            'unit' => null,
            'sort_order' => $this->faker->numberBetween(0, 100),
            'is_filterable' => false,
            'is_visible_on_product' => true,
            'is_comparable' => false,
            'is_required' => false,
            'is_required_by_default' => false,
            'is_active' => true,
        ];
    }
}

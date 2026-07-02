<?php

namespace Database\Factories;

use App\Models\AttributeValue;
use App\Models\ProductAttribute;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AttributeValue>
 */
class AttributeValueFactory extends Factory
{
    public function definition(): array
    {
        $label = $this->faker->unique()->word();

        return [
            'product_attribute_id' => ProductAttribute::factory(),
            'value' => Str::title($label),
            'value_translations' => ['en' => Str::title($label)],
            'slug' => Str::slug($label),
            'sort_order' => $this->faker->numberBetween(0, 100),
            'is_active' => true,
        ];
    }
}

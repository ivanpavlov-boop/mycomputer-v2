<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductAttributeValue>
 */
class ProductAttributeValueFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'product_attribute_id' => ProductAttribute::factory(),
            'attribute_value_id' => null,
            'custom_value' => 'Manual value',
            'value_text' => 'Manual value',
            'value_number' => null,
            'value_boolean' => null,
            'value_json' => null,
            'unit' => null,
            'source' => ProductAttributeValue::SOURCE_MANUAL,
            'is_verified' => false,
            'sort_order' => 0,
            'is_filterable' => true,
        ];
    }
}

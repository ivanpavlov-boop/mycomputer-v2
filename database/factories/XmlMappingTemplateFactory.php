<?php

namespace Database\Factories;

use App\Models\Supplier;
use App\Models\XmlMappingTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<XmlMappingTemplate>
 */
class XmlMappingTemplateFactory extends Factory
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
            'name' => 'Generic XML Product Feed',
            'description' => 'Default XML product mapping.',
            'root_path' => 'products.product',
            'field_map' => [
                'supplier_sku' => 'code',
                'ean' => 'ean',
                'mpn' => 'mpn',
                'name' => 'name',
                'brand_name' => 'brand',
                'category_name' => 'category',
                'price' => 'price',
                'quantity' => 'stock',
            ],
            'validation_rules' => [
                'supplier_sku' => 'required',
                'name' => 'required',
                'price' => 'nullable|numeric',
                'quantity' => 'nullable|numeric',
            ],
            'defaults' => [
                'currency' => 'BGN',
            ],
            'is_active' => true,
        ];
    }
}

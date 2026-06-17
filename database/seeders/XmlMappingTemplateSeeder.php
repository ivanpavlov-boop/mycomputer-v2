<?php

namespace Database\Seeders;

use App\Models\Supplier;
use App\Models\XmlMappingTemplate;
use Illuminate\Database\Seeder;

class XmlMappingTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $supplier = Supplier::query()->where('slug', 'demo-distribution')->firstOrFail();

        XmlMappingTemplate::query()->updateOrCreate(
            ['supplier_id' => $supplier->id, 'name' => 'Demo XML Product Mapping'],
            [
                'description' => 'Maps Demo Distribution XML product feeds into supplier product staging rows.',
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
                    'external_availability_status' => 'availability',
                    'external_availability_label' => 'availability_label',
                ],
                'validation_rules' => [
                    'supplier_sku' => 'required',
                    'name' => 'required',
                    'price' => 'nullable|numeric',
                    'quantity' => 'nullable|numeric',
                ],
                'defaults' => [
                    'currency' => 'EUR',
                ],
                'is_active' => true,
            ],
        );
    }
}

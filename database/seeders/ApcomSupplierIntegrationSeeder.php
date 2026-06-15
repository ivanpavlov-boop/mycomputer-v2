<?php

namespace Database\Seeders;

use App\Models\AttributeAlias;
use App\Models\AvailabilityStatus;
use App\Models\AvailabilityStatusMapping;
use App\Models\CanonicalAttribute;
use App\Models\Supplier;
use App\Models\SupplierFeed;
use App\Models\XmlMappingTemplate;
use App\Services\Attributes\AttributeTextNormalizer;
use Illuminate\Database\Seeder;

class ApcomSupplierIntegrationSeeder extends Seeder
{
    public function run(): void
    {
        $supplier = Supplier::query()->where('slug', 'apcom')->firstOrFail();

        SupplierFeed::query()->updateOrCreate(
            ['supplier_id' => $supplier->id, 'feed_name' => 'APCOM XML Product Feed'],
            [
                'feed_type' => 'xml',
                'feed_url' => 'https://feeds.apcom.example/catalog.xml',
                'username' => null,
                'password' => null,
                'update_interval' => '12h',
                'mapping' => [
                    'product_node' => 'Products.Product',
                    'supplier_sku' => ['SKU', 'Code', 'ItemCode', 'ProductCode', '@sku'],
                    'ean' => ['EAN', 'Barcode', 'GTIN'],
                    'mpn' => ['MPN', 'VendorCode', 'ManufacturerCode', 'PartNumber'],
                    'name' => ['Name', 'ProductName', 'Title'],
                    'brand' => ['Brand', 'Manufacturer', 'Vendor'],
                    'category' => ['CategoryPath', 'Category', 'ProductCategory'],
                    'price' => ['Price', 'DealerPrice', 'EndUserPrice'],
                    'quantity' => ['Stock', 'Quantity', 'Qty'],
                    'availability' => ['Availability', 'AvailabilityStatus', 'StockStatus'],
                    'image' => ['Image', 'ImageURL', 'Picture', 'Images/Image'],
                ],
                'status' => 'active',
            ],
        );

        XmlMappingTemplate::query()->updateOrCreate(
            ['supplier_id' => $supplier->id, 'name' => 'APCOM XML Product Mapping'],
            [
                'description' => 'Maps APCOM XML product feed rows into supplier product staging rows.',
                'root_path' => 'Products.Product',
                'field_map' => [
                    'supplier_sku' => ['SKU', 'Code', 'ItemCode', 'ProductCode', '@sku'],
                    'ean' => ['EAN', 'Barcode', 'GTIN'],
                    'mpn' => ['MPN', 'VendorCode', 'ManufacturerCode', 'PartNumber'],
                    'name' => ['Name', 'ProductName', 'Title'],
                    'brand_name' => ['Brand', 'Manufacturer', 'Vendor'],
                    'category_name' => ['CategoryPath', 'Category', 'ProductCategory'],
                    'price' => ['Price', 'DealerPrice', 'EndUserPrice'],
                    'quantity' => ['Stock', 'Quantity', 'Qty'],
                    'external_availability_status' => ['Availability', 'AvailabilityStatus', 'StockStatus'],
                    'external_availability_label' => ['AvailabilityLabel', 'DeliveryTime'],
                    'currency' => ['Currency'],
                    'image_url' => ['Image', 'ImageURL', 'Picture', 'Images/Image'],
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
            ],
        );

        $this->availabilityMappings($supplier);
        $this->attributeAliases($supplier);
    }

    private function availabilityMappings(Supplier $supplier): void
    {
        $mappings = [
            'in_stock' => ['available', 'in stock', 'instock'],
            'limited_stock' => ['limited', 'low stock'],
            'incoming' => ['incoming', 'delivery', 'delivery 3-5 days'],
            'preorder' => ['preorder', 'pre-order'],
            'on_request' => ['on request', 'by request'],
            'out_of_stock' => ['out of stock', 'unavailable', 'not available'],
        ];

        foreach ($mappings as $statusCode => $externalStatuses) {
            $status = AvailabilityStatus::query()->where('code', $statusCode)->first();

            if (! $status) {
                continue;
            }

            foreach ($externalStatuses as $externalStatus) {
                foreach (['xml', 'supplier'] as $sourceType) {
                    AvailabilityStatusMapping::query()->updateOrCreate(
                        [
                            'source_type' => $sourceType,
                            'source_code' => $supplier->company_name,
                            'external_status' => $externalStatus,
                        ],
                        [
                            'external_status_label' => $externalStatus,
                            'availability_status_id' => $status->id,
                            'priority' => 10,
                            'is_active' => true,
                        ],
                    );
                }
            }
        }
    }

    private function attributeAliases(Supplier $supplier): void
    {
        $normalizer = app(AttributeTextNormalizer::class);
        $aliases = [
            'cpu' => ['Processor', 'CPU Model'],
            'ram' => ['RAM Memory', 'Memory Size'],
            'storage' => ['SSD', 'HDD', 'Disk Capacity'],
            'gpu' => ['Video Card', 'Graphics Adapter', 'VGA'],
            'display_size' => ['Screen', 'Display Diagonal'],
            'operating_system' => ['OS', 'Software'],
        ];

        foreach ($aliases as $attributeCode => $attributeAliases) {
            $attribute = CanonicalAttribute::query()->where('code', $attributeCode)->first();

            if (! $attribute) {
                continue;
            }

            foreach ($attributeAliases as $alias) {
                AttributeAlias::query()->updateOrCreate(
                    [
                        'canonical_attribute_id' => $attribute->id,
                        'supplier_id' => $supplier->id,
                        'normalized_alias' => $normalizer->normalize($alias),
                    ],
                    [
                        'alias' => $alias,
                        'source_type' => 'xml',
                        'confidence' => 100,
                        'is_active' => true,
                    ],
                );
            }
        }
    }
}

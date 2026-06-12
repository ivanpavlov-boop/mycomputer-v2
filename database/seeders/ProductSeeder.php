<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\AvailabilityStatus;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Supplier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $supplier = Supplier::query()->where('slug', 'demo-distribution')->firstOrFail();
        $inStockStatusId = AvailabilityStatus::query()->where('code', 'in_stock')->value('id');

        $products = [
            [
                'category' => 'business-laptops',
                'brand' => 'lenovo',
                'sku' => 'MC-LAP-001',
                'name' => 'Lenovo ThinkPad E16 Gen 2',
                'price' => 1699.00,
                'attributes' => [
                    'Processor' => 'Intel Core Ultra 5',
                    'RAM' => '16 GB',
                    'Storage' => '512 GB SSD',
                    'Display' => '16 inch',
                ],
            ],
            [
                'category' => 'processors',
                'brand' => 'amd',
                'sku' => 'MC-CPU-001',
                'name' => 'AMD Ryzen 7 9700X',
                'price' => 699.00,
                'attributes' => [
                    'Processor' => 'AMD Ryzen 7 9700X',
                    'Cores' => '8',
                    'Socket' => 'AM5',
                    'Base clock' => '3.8 GHz',
                ],
            ],
            [
                'category' => 'gaming-monitors',
                'brand' => 'samsung',
                'sku' => 'MC-MON-001',
                'name' => 'Samsung Odyssey G5 27-inch',
                'price' => 529.00,
                'attributes' => [
                    'Display' => '27 inch',
                    'Refresh rate' => '165 Hz',
                    'Resolution' => '2560x1440',
                ],
            ],
        ];

        foreach ($products as $item) {
            $product = Product::query()->updateOrCreate(
                ['sku' => $item['sku']],
                [
                    'category_id' => Category::query()->where('slug', $item['category'])->value('id'),
                    'brand_id' => Brand::query()->where('slug', $item['brand'])->value('id'),
                    'supplier_id' => $supplier->id,
                    'supplier_sku' => 'SUP-'.$item['sku'],
                    'name' => $item['name'],
                    'slug' => Str::slug($item['name']),
                    'short_description' => 'Demo catalog product for MYCOMPUTER.BG v2.',
                    'description' => 'Seed product used to validate catalog, admin panel and API structures.',
                    'ean' => null,
                    'mpn' => $item['sku'],
                    'weight' => 1.500,
                    'purchase_price' => round($item['price'] * 0.82, 2),
                    'price' => $item['price'],
                    'promo_price' => null,
                    'quantity' => 10,
                    'reserved_quantity' => 0,
                    'stock_status' => 'in_stock',
                    'availability_status_id' => $inStockStatusId,
                    'product_status' => 'active',
                    'warranty_months' => 24,
                    'active' => true,
                    'featured' => true,
                    'new_product' => true,
                    'bestseller' => $item['sku'] === 'MC-LAP-001',
                    'meta_title' => $item['name'],
                    'meta_description' => 'Buy '.$item['name'].' from MYCOMPUTER.BG.',
                    'published_at' => now(),
                ],
            );

            $product->attributeValues()->delete();
            $product->images()->delete();

            foreach ($item['attributes'] as $attributeName => $valueName) {
                $attribute = ProductAttribute::query()
                    ->where('name', $attributeName)
                    ->with('values')
                    ->firstOrFail();

                $value = $attribute->values->firstWhere('value', $valueName);

                $product->attributeValues()->create([
                    'product_attribute_id' => $attribute->id,
                    'attribute_value_id' => $value?->id,
                    'custom_value' => $value ? null : $valueName,
                    'is_filterable' => $attribute->is_filterable,
                ]);
            }

            $product->images()->create([
                'path' => 'products/placeholders/'.$product->slug.'.jpg',
                'alt_text' => $product->name,
                'sort_order' => 1,
                'is_primary' => true,
            ]);
        }

        Product::query()->where('sku', 'MC-LAP-001')->first()
            ?->accessoryProducts()
            ->syncWithoutDetaching([
                Product::query()->where('sku', 'MC-MON-001')->value('id') => ['sort_order' => 1],
            ]);
    }
}

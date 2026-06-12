<?php

namespace Database\Seeders;

use App\Models\Supplier;
use App\Models\SupplierFeed;
use Illuminate\Database\Seeder;

class SupplierFeedSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $supplier = Supplier::query()->where('slug', 'demo-distribution')->firstOrFail();

        $feed = SupplierFeed::query()->updateOrCreate(
            ['supplier_id' => $supplier->id, 'feed_name' => 'Demo XML Product Feed'],
            [
                'feed_type' => 'xml',
                'feed_url' => 'https://example-supplier.test/catalog.xml',
                'username' => null,
                'password' => null,
                'update_interval' => '6h',
                'mapping' => [
                    'product_node' => 'products.product',
                    'sku' => 'code',
                    'name' => 'name',
                    'description' => 'description',
                    'price' => 'price',
                    'quantity' => 'stock',
                    'brand' => 'brand',
                    'category' => 'category',
                    'images' => 'images.image',
                ],
                'last_sync_at' => now()->subHours(6),
                'status' => 'active',
            ],
        );

        $feed->supplierProducts()->updateOrCreate(
            ['supplier_sku' => 'SUP-MC-LAP-001', 'payload_hash' => sha1('SUP-MC-LAP-001-demo')],
            [
                'supplier_id' => $supplier->id,
                'name' => 'Lenovo ThinkPad E16 Gen 2',
                'ean' => '1234567890123',
                'mpn' => 'MC-LAP-001',
                'brand_name' => 'Lenovo',
                'category_name' => 'Business Laptops',
                'price' => 1393.18,
                'quantity' => 12,
                'currency' => 'BGN',
                'raw_data' => [
                    'code' => 'SUP-MC-LAP-001',
                    'name' => 'Lenovo ThinkPad E16 Gen 2',
                    'price' => '1393.18',
                    'stock' => '12',
                ],
                'received_at' => now()->subHours(6),
                'status' => 'new',
                'mapping_notes' => 'Demo raw feed record. It does not update catalog products directly.',
            ],
        );
    }
}

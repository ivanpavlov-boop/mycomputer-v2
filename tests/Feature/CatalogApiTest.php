<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_endpoints_return_seeded_data(): void
    {
        $this->seed();

        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'laptops');

        $this->getJson('/api/v1/brands')
            ->assertOk()
            ->assertJsonFragment(['slug' => 'lenovo']);

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonFragment(['sku' => 'MC-LAP-001']);

        $this->getJson('/api/v1/products/lenovo-thinkpad-e16-gen-2')
            ->assertOk()
            ->assertJsonPath('data.sku', 'MC-LAP-001');

        $this->assertDatabaseHas('supplier_products', [
            'supplier_sku' => 'SUP-MC-LAP-001',
            'status' => 'new',
        ]);
    }

    public function test_product_thumbnail_url_prefers_primary_image(): void
    {
        $product = Product::factory()->create();
        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'https://example.test/secondary.jpg',
            'alt_text' => 'Secondary',
            'sort_order' => 1,
            'is_primary' => false,
        ]);
        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'https://example.test/primary.jpg',
            'alt_text' => 'Primary',
            'sort_order' => 2,
            'is_primary' => true,
        ]);

        $product->load('thumbnailImage');

        $this->assertSame('https://example.test/primary.jpg', $product->thumbnailUrl());
    }
}

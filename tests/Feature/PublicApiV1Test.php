<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicApiV1Test extends TestCase
{
    use RefreshDatabase;

    public function test_product_list_api_returns_public_products(): void
    {
        $this->seed();

        $this->getJson('/api/v1/products?per_page=10')
            ->assertOk()
            ->assertJsonFragment(['sku' => 'MC-LAP-001'])
            ->assertJsonFragment(['currency' => Product::CATALOG_CURRENCY])
            ->assertJsonMissingPath('data.0.purchase_price')
            ->assertJsonMissingPath('data.0.source_payload')
            ->assertJsonMissingPath('data.0.supplier_id');
    }

    public function test_product_detail_api_returns_grouped_catalog_data(): void
    {
        $this->seed();

        $this->getJson('/api/v1/products/lenovo-thinkpad-e16-gen-2')
            ->assertOk()
            ->assertJsonPath('data.sku', 'MC-LAP-001')
            ->assertJsonPath('data.currency', Product::CATALOG_CURRENCY)
            ->assertJsonStructure([
                'data' => [
                    'category',
                    'brand',
                    'images',
                    'attributes',
                    'related_products',
                    'accessory_products',
                    'seo',
                    'structured_data',
                ],
            ])
            ->assertJsonMissingPath('data.purchase_price')
            ->assertJsonMissingPath('data.source_payload');
    }

    public function test_category_products_api_filters_products(): void
    {
        $this->seed();

        $this->getJson('/api/v1/categories/business-laptops/products?sort=price_desc')
            ->assertOk()
            ->assertJsonPath('data.0.sku', 'MC-LAP-001');
    }

    public function test_brand_products_api_filters_products(): void
    {
        $this->seed();

        $this->getJson('/api/v1/brands/lenovo/products')
            ->assertOk()
            ->assertJsonPath('data.0.sku', 'MC-LAP-001');
    }

    public function test_search_api_uses_database_fallback(): void
    {
        $this->seed();

        $this->getJson('/api/v1/search?q=Lenovo')
            ->assertOk()
            ->assertJsonPath('data.filters.engine', 'database')
            ->assertJsonPath('data.products.data.0.sku', 'MC-LAP-001');
    }

    public function test_filters_api_returns_category_filters(): void
    {
        $this->seed();

        $this->getJson('/api/v1/filters/categories/business-laptops')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'brands',
                    'price_range',
                    'stock_statuses',
                    'attributes',
                ],
            ]);
    }

    public function test_homepage_api_returns_home_sections(): void
    {
        $this->seed();

        $this->getJson('/api/v1/home')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'hero_banners',
                    'featured_categories',
                    'featured_products',
                    'new_products',
                    'bestsellers',
                    'promotional_products',
                    'latest_articles',
                ],
            ]);
    }

    public function test_compare_api_returns_shared_and_different_attributes(): void
    {
        $this->seed();

        $ids = Product::query()->published()->limit(2)->pluck('id')->all();

        $this->postJson('/api/v1/compare', ['product_ids' => $ids])
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'products',
                    'shared_attributes',
                    'differences',
                    'prices',
                    'stock_statuses',
                ],
            ]);
    }

    public function test_seo_api_returns_product_metadata(): void
    {
        $this->seed();

        $this->getJson('/api/v1/seo/product/lenovo-thinkpad-e16-gen-2')
            ->assertOk()
            ->assertJsonPath('data.schema.@type', 'Product')
            ->assertJsonPath('data.meta_title', 'Lenovo ThinkPad E16 Gen 2');
    }

    public function test_inactive_products_are_not_returned_publicly(): void
    {
        $this->seed();

        Product::query()->where('sku', 'MC-LAP-001')->update(['active' => false]);

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonMissing(['sku' => 'MC-LAP-001']);

        $this->getJson('/api/v1/products/lenovo-thinkpad-e16-gen-2')
            ->assertNotFound();
    }
}

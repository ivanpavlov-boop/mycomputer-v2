<?php

namespace Tests\Feature;

use App\Models\AttributeValue;
use App\Models\Product;
use App\Models\ProductBundle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('scout.driver', 'database');

        $this->seed();
        Product::query()
            ->where('sku', 'MC-LAP-001')
            ->update([
                'ean' => '1234567890123',
                'mpn' => 'LEN-E16-G2',
                'searchable_keywords' => 'laptop za autocad business notebook',
            ]);
    }

    public function test_search_by_product_name(): void
    {
        $this->getJson('/api/v1/search?q=ThinkPad')
            ->assertOk()
            ->assertJsonPath('data.products.data.0.sku', 'MC-LAP-001');
    }

    public function test_search_by_sku(): void
    {
        $this->getJson('/api/v1/search?q=MC-LAP-001')
            ->assertOk()
            ->assertJsonPath('data.products.data.0.sku', 'MC-LAP-001');
    }

    public function test_search_by_ean(): void
    {
        $this->getJson('/api/v1/search?q=1234567890123')
            ->assertOk()
            ->assertJsonPath('data.products.data.0.ean', '1234567890123');
    }

    public function test_search_by_brand_and_category(): void
    {
        $this->getJson('/api/v1/search?q=Lenovo')
            ->assertOk()
            ->assertJsonPath('data.products.data.0.brand.slug', 'lenovo');

        $this->getJson('/api/v1/search?q=Business')
            ->assertOk()
            ->assertJsonPath('data.products.data.0.category.slug', 'business-laptops');
    }

    public function test_filtering_by_brand_attributes_and_price(): void
    {
        $attributeValue = AttributeValue::query()
            ->whereHas('assignments.product', fn ($query) => $query->where('sku', 'MC-LAP-001'))
            ->firstOrFail();

        $this->getJson('/api/v1/search?brand=lenovo&attributes[]='.$attributeValue->slug.'&price_min=1&price_max=5000')
            ->assertOk()
            ->assertJsonPath('data.products.data.0.sku', 'MC-LAP-001')
            ->assertJsonStructure(['data' => ['available_filters' => ['brands', 'price_range', 'stock_statuses', 'attributes']]]);
    }

    public function test_autocomplete_suggestions(): void
    {
        $response = $this->getJson('/api/v1/search/suggestions?q=lenovo')
            ->assertOk()
            ->assertJsonStructure(['data' => ['suggestions']]);

        $this->assertContains('Lenovo ThinkPad E16 Gen 2', $response->json('data.suggestions'));
    }

    public function test_typo_tolerance_fallback(): void
    {
        $this->getJson('/api/v1/search?q=lenowo')
            ->assertOk()
            ->assertJsonPath('data.products.data.0.sku', 'MC-LAP-001');
    }

    public function test_pagination_and_sorting(): void
    {
        $this->getJson('/api/v1/search?per_page=1&page=1&sort=price_desc')
            ->assertOk()
            ->assertJsonPath('data.per_page', 1)
            ->assertJsonPath('data.page', 1);
    }

    public function test_inactive_products_are_not_returned_and_internal_fields_are_hidden(): void
    {
        Product::query()->where('sku', 'MC-LAP-001')->update(['active' => false]);

        $this->getJson('/api/v1/search?q=ThinkPad')
            ->assertOk()
            ->assertJsonMissing(['sku' => 'MC-LAP-001'])
            ->assertJsonMissingPath('data.products.data.0.purchase_price')
            ->assertJsonMissingPath('data.products.data.0.source_payload')
            ->assertJsonMissingPath('data.products.data.0.supplier_id');
    }

    public function test_search_returns_bundle_results_and_bundle_suggestions(): void
    {
        $product = Product::query()->where('sku', 'MC-LAP-001')->firstOrFail();
        $bundle = ProductBundle::query()->create([
            'name' => 'Lenovo Office Starter Pack',
            'slug' => 'lenovo-office-starter-pack',
            'short_description' => 'Office bundle with ThinkPad accessories',
            'description' => 'A complete Lenovo office package.',
            'status' => 'active',
            'type' => 'starter_pack',
            'pricing_type' => 'discount_percentage',
            'discount_value' => 10,
        ]);
        $bundle->items()->create([
            'product_id' => $product->id,
            'component_group' => 'laptop',
            'quantity' => 1,
        ]);

        $this->getJson('/api/v1/search?q=starter')
            ->assertOk()
            ->assertJsonPath('data.bundles.data.0.slug', 'lenovo-office-starter-pack')
            ->assertJsonPath('data.bundle_total', 1);

        $response = $this->getJson('/api/v1/search/suggestions?q=starter')
            ->assertOk();

        $this->assertContains('Lenovo Office Starter Pack', $response->json('data.suggestions'));
    }
}

<?php

namespace Tests\Feature;

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
}

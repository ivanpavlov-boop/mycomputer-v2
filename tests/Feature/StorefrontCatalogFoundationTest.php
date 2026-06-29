<?php

namespace Tests\Feature;

use App\Filament\Pages\CatalogSyncPreview;
use App\Filament\Resources\ProductDataQualityQueue\ProductDataQualityQueueResource;
use App\Filament\Resources\ProductQualityFlags\ProductQualityFlagResource;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontCatalogFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_product_api_only_returns_active_published_catalog_products(): void
    {
        $publicCategory = Category::factory()->create(['slug' => 'public-laptops', 'is_active' => true]);
        $privateCategory = Category::factory()->create(['slug' => 'private-laptops', 'is_active' => false]);

        $visible = $this->publishedProduct([
            'category_id' => $publicCategory->id,
            'sku' => 'PUBLIC-CATALOG-001',
            'slug' => 'public-catalog-product',
            'name' => 'Public Catalog Product',
        ]);

        $hiddenProducts = [
            $this->publishedProduct(['category_id' => $publicCategory->id, 'sku' => 'HIDDEN-DRAFT-WORKFLOW', 'slug' => 'hidden-draft-workflow', 'workflow_status' => Product::WORKFLOW_DRAFT]),
            $this->publishedProduct(['category_id' => $publicCategory->id, 'sku' => 'HIDDEN-PENDING-WORKFLOW', 'slug' => 'hidden-pending-workflow', 'workflow_status' => Product::WORKFLOW_PENDING_REVIEW]),
            $this->publishedProduct(['category_id' => $publicCategory->id, 'sku' => 'HIDDEN-APPROVED-WORKFLOW', 'slug' => 'hidden-approved-workflow', 'workflow_status' => Product::WORKFLOW_APPROVED]),
            $this->publishedProduct(['category_id' => $publicCategory->id, 'sku' => 'HIDDEN-INACTIVE', 'slug' => 'hidden-inactive', 'active' => false]),
            $this->publishedProduct(['category_id' => $publicCategory->id, 'sku' => 'HIDDEN-STATUS-DRAFT', 'slug' => 'hidden-status-draft', 'product_status' => 'draft']),
            $this->publishedProduct(['category_id' => $publicCategory->id, 'sku' => 'HIDDEN-BLANK-SLUG', 'slug' => '']),
            $this->publishedProduct(['category_id' => $privateCategory->id, 'sku' => 'HIDDEN-PRIVATE-CATEGORY', 'slug' => 'hidden-private-category']),
        ];

        $deleted = $this->publishedProduct([
            'category_id' => $publicCategory->id,
            'sku' => 'HIDDEN-DELETED',
            'slug' => 'hidden-deleted',
        ]);
        $deleted->delete();

        $supplier = Supplier::factory()->create();
        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'STAGED-ONLY-SKU',
            'ean' => '9876543210123',
            'mpn' => 'STAGED-MPN',
            'name' => 'Staged Supplier Product Only',
            'brand_name' => 'Staged Brand',
            'category_name' => 'Staged Category',
            'price' => 250,
            'quantity' => 3,
            'currency' => 'EUR',
            'raw_data' => ['name' => 'Staged Supplier Product Only'],
            'payload_hash' => 'staged-only-hash',
            'received_at' => now(),
            'status' => 'new',
        ]);

        $this
            ->getJson('/api/v1/products?per_page=100')
            ->assertOk()
            ->assertJsonFragment(['sku' => $visible->sku])
            ->assertJsonMissing(['sku' => 'STAGED-ONLY-SKU'])
            ->assertJsonMissing(['name' => 'Staged Supplier Product Only']);

        $this
            ->getJson('/api/v1/search?q=Public')
            ->assertOk()
            ->assertJsonFragment(['sku' => $visible->sku])
            ->assertJsonMissing(['sku' => 'STAGED-ONLY-SKU']);

        $this
            ->getJson('/api/v1/search?q=Staged')
            ->assertOk()
            ->assertJsonMissing(['sku' => 'STAGED-ONLY-SKU'])
            ->assertJsonMissing(['name' => 'Staged Supplier Product Only']);

        foreach ($hiddenProducts as $hidden) {
            $this
                ->getJson('/api/v1/products?per_page=100')
                ->assertJsonMissing(['sku' => $hidden->sku]);

            if (filled($hidden->slug)) {
                $this->getJson('/api/v1/products/'.$hidden->slug)->assertNotFound();
            }
        }

        $this->getJson('/api/v1/products/'.$visible->slug)
            ->assertOk()
            ->assertJsonPath('data.sku', $visible->sku);
    }

    public function test_public_category_listing_reads_catalog_products_without_mutating_data(): void
    {
        $category = Category::factory()->create(['slug' => 'business-laptops', 'is_active' => true]);
        $inactiveCategory = Category::factory()->create(['slug' => 'hidden-category', 'is_active' => false]);
        $product = $this->publishedProduct([
            'category_id' => $category->id,
            'sku' => 'CATEGORY-PUBLIC-001',
            'slug' => 'category-public-product',
            'name' => 'Category Public Product',
        ]);
        $supplier = Supplier::factory()->create();
        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'CATEGORY-STAGED-001',
            'name' => 'Category Staged Product',
            'currency' => 'EUR',
            'raw_data' => [],
            'payload_hash' => 'category-staged-hash',
            'received_at' => now(),
            'status' => 'new',
        ]);

        $productCount = Product::query()->count();
        $supplierProductCount = SupplierProduct::query()->count();
        $updatedAt = $product->updated_at?->toJSON();

        $this
            ->getJson('/api/v1/categories?active=1')
            ->assertOk()
            ->assertJsonFragment(['slug' => $category->slug])
            ->assertJsonMissing(['slug' => $inactiveCategory->slug]);

        $this
            ->getJson('/api/v1/categories/'.$category->slug.'/products?sort=price_asc&q=Category')
            ->assertOk()
            ->assertJsonFragment(['sku' => $product->sku])
            ->assertJsonMissing(['sku' => 'CATEGORY-STAGED-001']);

        $this->assertSame($productCount, Product::query()->count());
        $this->assertSame($supplierProductCount, SupplierProduct::query()->count());
        $this->assertSame($updatedAt, $product->fresh()->updated_at?->toJSON());
    }

    public function test_product_without_image_has_null_image_payload_for_frontend_placeholder(): void
    {
        $product = $this->publishedProduct([
            'sku' => 'NO-IMAGE-001',
            'slug' => 'no-image-product',
            'name' => 'No Image Product',
        ]);

        $payload = $this
            ->getJson('/api/v1/products?per_page=100')
            ->assertOk()
            ->json('data');

        $row = collect($payload)->firstWhere('sku', $product->sku);

        $this->assertNotNull($row);
        $this->assertNull($row['primary_image']);
    }

    public function test_admin_catalog_pages_still_render_for_super_admin(): void
    {
        $this->actingAsSuperAdmin();

        $this->get(ProductResource::getUrl())->assertOk();
        $this->get(ProductDataQualityQueueResource::getUrl())->assertOk();
        $this->get(ProductQualityFlagResource::getUrl())->assertOk();
        $this->get(CatalogSyncPreview::getUrl())->assertOk();
    }

    public function test_catalog_sync_safety_flags_remain_disabled_for_sync_all_auto_and_update(): void
    {
        $this->assertTrue((bool) config('catalog_sync.create_enabled'));
        $this->assertFalse((bool) config('catalog_sync.update_enabled'));
        $this->assertFalse((bool) config('catalog_sync.sync_all_enabled'));
        $this->assertFalse((bool) config('catalog_sync.auto_enabled'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function publishedProduct(array $overrides = []): Product
    {
        return Product::factory()->create(array_merge([
            'workflow_status' => Product::WORKFLOW_PUBLISHED,
            'product_status' => 'active',
            'active' => true,
            'published_at' => now()->subHour(),
            'stock_status' => Product::STOCK_STATUS_IN_STOCK,
            'quantity' => 5,
        ], $overrides));
    }

    private function actingAsSuperAdmin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
        ]);
        $user->assignRole(User::ROLE_SUPER_ADMIN);

        $this->actingAs($user);

        return $user;
    }
}

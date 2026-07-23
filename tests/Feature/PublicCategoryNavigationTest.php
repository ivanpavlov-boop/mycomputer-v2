<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PublicCategoryNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_active_category_tree_is_recursive_ordered_and_excludes_inactive_or_deleted_nodes(): void
    {
        $secondRoot = $this->category('Second root', 'second-root', 20);
        $firstRoot = $this->category('First root', 'first-root', 10);
        $laterChild = $this->category('Later child', 'later-child', 20, $firstRoot);
        $firstChild = $this->category('First child', 'first-child', 10, $firstRoot);
        $thirdLevel = $this->category('Third level', 'third-level', 10, $firstChild);
        $fourthLevel = $this->category('Fourth level', 'fourth-level', 10, $thirdLevel);

        $inactiveRoot = $this->category('Inactive root', 'inactive-root', 1, null, false);
        $this->category('Active under inactive', 'active-under-inactive', 1, $inactiveRoot);
        $inactiveDescendant = $this->category('Inactive descendant', 'inactive-descendant', 1, $thirdLevel, false);
        $deletedDescendant = $this->category('Deleted descendant', 'deleted-descendant', 2, $thirdLevel);
        $deletedDescendant->delete();

        $data = $this->getJson('/api/v1/navigation/categories')
            ->assertOk()
            ->json('data');

        $this->assertSame([$firstRoot->id, $secondRoot->id], array_column($data, 'id'));
        $this->assertSame([$firstChild->id, $laterChild->id], array_column($data[0]['children'], 'id'));
        $this->assertSame($thirdLevel->id, $data[0]['children'][0]['children'][0]['id']);
        $this->assertSame($fourthLevel->id, $data[0]['children'][0]['children'][0]['children'][0]['id']);

        $ids = $this->flattenIds($data);

        $this->assertCount(count(array_unique($ids)), $ids);
        $this->assertNotContains($inactiveRoot->id, $ids);
        $this->assertNotContains($inactiveDescendant->id, $ids);
        $this->assertNotContains($deletedDescendant->id, $ids);
    }

    public function test_navigation_payload_exposes_no_product_or_supplier_data(): void
    {
        $category = $this->category('Laptops', 'laptops', 1);
        Product::factory()->create(['category_id' => $category->id]);
        $supplier = Supplier::factory()->create();
        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'NAVIGATION-STAGING-001',
            'name' => 'Staging only product',
            'currency' => 'EUR',
            'raw_data' => [],
            'payload_hash' => 'navigation-staging-hash',
            'received_at' => now(),
            'status' => 'new',
        ]);

        $payload = $this->getJson('/api/v1/navigation/categories')
            ->assertOk()
            ->json('data.0');

        $this->assertSame(
            ['id', 'slug', 'name', 'icon', 'image', 'sort_order', 'children'],
            array_keys($payload),
        );
        $this->assertArrayNotHasKey('products', $payload);
        $this->assertArrayNotHasKey('supplier', $payload);
        $this->assertArrayNotHasKey('supplier_products', $payload);
    }

    public function test_navigation_request_is_read_only(): void
    {
        $category = $this->category('Monitors', 'monitors', 1);
        $product = Product::factory()->create(['category_id' => $category->id]);
        $categoryUpdatedAt = $category->updated_at?->toJSON();
        $productUpdatedAt = $product->updated_at?->toJSON();
        $categoryCount = Category::withTrashed()->count();
        $productCount = Product::withTrashed()->count();

        $this->getJson('/api/v1/navigation/categories')->assertOk();

        $this->assertSame($categoryCount, Category::withTrashed()->count());
        $this->assertSame($productCount, Product::withTrashed()->count());
        $this->assertSame($categoryUpdatedAt, $category->fresh()->updated_at?->toJSON());
        $this->assertSame($productUpdatedAt, $product->fresh()->updated_at?->toJSON());
    }

    private function category(
        string $name,
        string $slug,
        int $sortOrder,
        ?Category $parent = null,
        bool $active = true,
    ): Category {
        return Category::factory()->create([
            'parent_id' => $parent?->id,
            'name' => $name,
            'name_translations' => ['bg' => $name],
            'slug' => $slug,
            'sort_order' => $sortOrder,
            'is_active' => $active,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $categories
     * @return array<int, int>
     */
    private function flattenIds(array $categories): array
    {
        $ids = [];

        foreach ($categories as $category) {
            $ids[] = (int) $category['id'];
            array_push($ids, ...$this->flattenIds($category['children'] ?? []));
        }

        return $ids;
    }
}

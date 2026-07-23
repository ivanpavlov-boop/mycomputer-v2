<?php

namespace Tests\Feature;

use App\Models\AttributeGroup;
use App\Models\AttributeValue;
use App\Models\PcBuild;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PcBuilderTest extends TestCase
{
    use RefreshDatabase;

    private string $session = '7dcb99af-a7ee-46e9-bb15-551dd6e8d9d1';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('scout.driver', 'database');
        $this->seed();
    }

    public function test_guest_can_create_build(): void
    {
        $this->withHeader('X-PC-Build-Session', $this->session)
            ->postJson('/api/v1/pc-builder/builds', ['name' => 'Gaming build'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Gaming build')
            ->assertJsonPath('data.session_id', $this->session);
    }

    public function test_can_add_component_and_recalculate_price(): void
    {
        $build = $this->guestBuild();
        $cpu = $this->createComponent('cpu', 'Intel Core i5 LGA1700', 399, ['socket' => 'LGA1700']);

        $this->withHeader('X-PC-Build-Session', $this->session)
            ->postJson("/api/v1/pc-builder/builds/{$build->id}/items", [
                'product_id' => $cpu->id,
                'component_type' => 'cpu',
                'quantity' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('data.items.0.component_type', 'cpu')
            ->assertJsonPath('data.total_price', '798.00');
    }

    public function test_compatibility_validation_reports_successful_matches(): void
    {
        $build = $this->guestBuild();
        $this->addItem($build, 'cpu', $this->createComponent('cpu', 'AMD Ryzen AM5', 520, ['socket' => 'AM5']));
        $this->addItem($build, 'motherboard', $this->createComponent('motherboard', 'AM5 DDR5 ATX Board', 340, [
            'socket' => 'AM5',
            'memory_type' => 'DDR5',
            'form_factor' => 'ATX',
            'storage_interface' => 'M.2 SATA',
        ]));
        $this->addItem($build, 'ram', $this->createComponent('ram', 'DDR5 32GB', 180, ['memory_type' => 'DDR5']));

        $this->withHeader('X-PC-Build-Session', $this->session)
            ->getJson("/api/v1/pc-builder/builds/{$build->id}/compatibility")
            ->assertOk()
            ->assertJsonPath('data.compatible', true)
            ->assertJsonFragment(['CPU socket matches motherboard.'])
            ->assertJsonFragment(['RAM type matches motherboard.']);
    }

    public function test_compatibility_validation_reports_errors(): void
    {
        $build = $this->guestBuild();
        $this->addItem($build, 'cpu', $this->createComponent('cpu', 'Intel LGA1700 CPU', 350, ['socket' => 'LGA1700']));
        $this->addItem($build, 'motherboard', $this->createComponent('motherboard', 'AMD AM5 Board', 330, ['socket' => 'AM5']));

        $this->withHeader('X-PC-Build-Session', $this->session)
            ->getJson("/api/v1/pc-builder/builds/{$build->id}/compatibility")
            ->assertOk()
            ->assertJsonPath('data.compatible', false)
            ->assertJsonFragment(['cpu socket (LGA1700) is not compatible with motherboard socket (AM5).']);
    }

    public function test_recommendations_include_missing_components_and_hide_internal_fields(): void
    {
        $build = $this->guestBuild();
        $this->createComponent('cpu', 'Visible CPU', 299, ['socket' => 'AM5']);

        $this->withHeader('X-PC-Build-Session', $this->session)
            ->getJson("/api/v1/pc-builder/builds/{$build->id}/recommendations")
            ->assertOk()
            ->assertJsonPath('data.missing_components.0', 'cpu')
            ->assertJsonMissingPath('data.suggested_products.0.purchase_price')
            ->assertJsonMissingPath('data.suggested_products.0.source_payload');
    }

    public function test_build_can_be_added_to_cart(): void
    {
        $build = $this->guestBuild();
        $cpu = $this->createComponent('cpu', 'Cart CPU', 410, ['socket' => 'AM5']);
        $this->addItem($build, 'cpu', $cpu);

        $this->withHeaders([
            'X-PC-Build-Session' => $this->session,
            'X-Cart-Session' => $this->cartSession('cart-session'),
        ])
            ->postJson("/api/v1/pc-builder/builds/{$build->id}/add-to-cart")
            ->assertOk()
            ->assertJsonPath('data.items.0.product.sku', $cpu->sku);

        $this->assertDatabaseHas('pc_builds', ['id' => $build->id, 'status' => 'ordered']);
    }

    public function test_users_can_access_only_own_builds(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $build = PcBuild::query()->create(['user_id' => $owner->id, 'name' => 'Private build', 'status' => 'draft']);

        Sanctum::actingAs($other);

        $this->getJson("/api/v1/pc-builder/builds/{$build->id}")
            ->assertNotFound();
    }

    public function test_ai_generation_creates_draft_build(): void
    {
        $this->createComponent('gpu', 'Gaming RTX Video Card', 1200, ['recommended_psu_watts' => '750']);

        $this->withHeader('X-PC-Build-Session', $this->session)
            ->postJson('/api/v1/pc-builder/ai-generate', ['query' => 'Build me a gaming PC under 3000 EUR'])
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.session_id', $this->session);
    }

    private function guestBuild(): PcBuild
    {
        return PcBuild::query()->create([
            'session_id' => $this->session,
            'name' => 'Test build',
            'status' => 'draft',
        ]);
    }

    private function createComponent(string $type, string $name, float $price, array $attributes): Product
    {
        $product = Product::factory()->create([
            'name' => $name,
            'slug' => str($name)->slug().'-'.uniqid(),
            'sku' => strtoupper($type).'-'.uniqid(),
            'price' => $price,
            'purchase_price' => $price / 2,
            'quantity' => 10,
            'stock_status' => 'in_stock',
            'active' => true,
            'published_at' => now(),
        ]);

        foreach ($attributes as $slug => $value) {
            $this->assignAttribute($product, $slug, $value);
        }

        return $product;
    }

    private function assignAttribute(Product $product, string $slug, string $value): void
    {
        $group = AttributeGroup::query()->firstOrCreate(
            ['slug' => 'compatibility'],
            ['name' => 'Compatibility', 'sort_order' => 1, 'is_active' => true],
        );

        $attribute = ProductAttribute::query()->firstOrCreate(
            ['slug' => $slug],
            [
                'attribute_group_id' => $group->id,
                'name' => str($slug)->replace('_', ' ')->title(),
                'type' => 'text',
                'is_filterable' => true,
                'is_required' => false,
                'is_active' => true,
            ],
        );

        $attributeValue = AttributeValue::query()->firstOrCreate(
            ['product_attribute_id' => $attribute->id, 'slug' => str($value)->slug()],
            ['value' => $value, 'is_active' => true],
        );

        ProductAttributeValue::query()->create([
            'product_id' => $product->id,
            'product_attribute_id' => $attribute->id,
            'attribute_value_id' => $attributeValue->id,
            'is_filterable' => true,
        ]);
    }

    private function addItem(PcBuild $build, string $componentType, Product $product): void
    {
        $build->items()->create([
            'product_id' => $product->id,
            'component_type' => $componentType,
            'quantity' => 1,
        ]);
    }
}

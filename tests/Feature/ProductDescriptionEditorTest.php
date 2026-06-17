<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductDescriptionEditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_manually_created_product_can_be_saved_with_rich_html_description(): void
    {
        $this->actingAsProductManager();

        $description = '<h2>Main benefits</h2><p><strong>Fast charging</strong> and <em>compact</em> design.</p><ul><li>USB-C</li><li>Power Bank</li></ul><blockquote>Suitable for travel.</blockquote><table><tbody><tr><td>Power</td><td>18W</td></tr></tbody></table>';
        $shortDescription = '<p><strong>Compact</strong> wireless charger.</p>';

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Satechi Duo Wireless Charger',
                'slug' => 'satechi-duo-wireless-charger',
                'sku' => 'MANUAL-SATECHI-DUO',
                'source' => Product::SOURCE_MANUAL,
                'short_description' => $shortDescription,
                'description' => $description,
                'price' => 129.99,
                'price_source' => Product::PRICE_SOURCE_MANUAL,
                'quantity' => 5,
                'reserved_quantity' => 0,
                'product_status' => 'active',
                'stock_status' => 'in_stock',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::query()->where('sku', 'MANUAL-SATECHI-DUO')->firstOrFail();

        $this->assertStringContainsString('<h2>Main benefits</h2>', $product->description);
        $this->assertStringContainsString('<strong>Fast charging</strong>', $product->description);
        $this->assertStringContainsString('<ul>', $product->description);
        $this->assertStringContainsString('<blockquote>Suitable for travel.</blockquote>', $product->description);
        $this->assertStringContainsString('<table>', $product->description);
        $this->assertSame($shortDescription, $product->short_description);
    }

    public function test_description_persists_after_save(): void
    {
        $product = Product::factory()->create([
            'source' => Product::SOURCE_MANUAL,
            'description' => '<h2>Features</h2><p><u>USB-C</u> input.</p>',
            'short_description' => '<p>Short HTML description.</p>',
        ]);

        $product->update([
            'description' => '<h3>Updated description</h3><ol><li>First point</li><li>Second point</li></ol>',
            'short_description' => '<p><em>Updated short description.</em></p>',
        ]);

        $product->refresh();

        $this->assertSame('<h3>Updated description</h3><ol><li>First point</li><li>Second point</li></ol>', $product->description);
        $this->assertSame('<p><em>Updated short description.</em></p>', $product->short_description);
    }

    public function test_existing_empty_descriptions_still_work_and_edit_form_loads(): void
    {
        $this->actingAsProductManager();

        $product = Product::factory()->create([
            'source' => Product::SOURCE_MANUAL,
            'description' => null,
            'short_description' => null,
        ]);

        $this
            ->get(ProductResource::getUrl('edit', ['record' => $product]))
            ->assertOk();

        $product->refresh();

        $this->assertNull($product->description);
        $this->assertNull($product->short_description);
    }

    private function actingAsProductManager(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('manager');

        $this->actingAs($user);

        return $user;
    }
}

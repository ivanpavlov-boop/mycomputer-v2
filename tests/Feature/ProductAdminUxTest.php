<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\ProductResource;
use App\Models\AvailabilityStatus;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductAdminUxTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_see_product_bulk_delete_action_with_confirmation(): void
    {
        $this->actingAsRole(User::ROLE_SUPER_ADMIN);

        $component = Livewire::test(ListProducts::class)
            ->assertTableBulkActionExists('delete');

        $deleteAction = $component->instance()->getTable()->getBulkAction('delete');

        $this->assertSame('Изтрий избраните', $deleteAction?->getLabel());
        $this->assertSame('Изтриване на избрани продукти', (string) $deleteAction?->getModalHeading());
        $this->assertSame('Изтрий избраните', $deleteAction?->getModalSubmitActionLabel());
        $this->assertTrue($deleteAction?->isConfirmationRequired());
    }

    public function test_products_admin_list_create_and_edit_labels_are_bulgarian(): void
    {
        $this->actingAsRole(User::ROLE_SUPER_ADMIN);

        $outOfStock = AvailabilityStatus::query()->create([
            'code' => Product::STOCK_STATUS_OUT_OF_STOCK,
            'name' => 'Out Of Stock',
            'color' => 'red',
            'icon' => 'warning',
            'badge_style' => 'soft',
            'allow_purchase' => false,
            'show_stock_quantity' => false,
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 10,
        ]);
        $limitedStock = AvailabilityStatus::query()->create([
            'code' => Product::STOCK_STATUS_LIMITED_STOCK,
            'name' => 'Limited Stock',
            'color' => 'yellow',
            'icon' => 'package',
            'badge_style' => 'soft',
            'allow_purchase' => true,
            'show_stock_quantity' => true,
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 20,
        ]);

        $outOfStockProduct = Product::factory()->create([
            'name' => 'Bulgarian Labels Out Product',
            'sku' => 'BG-LABEL-OUT',
            'availability_status_id' => $outOfStock->id,
            'stock_status' => Product::STOCK_STATUS_OUT_OF_STOCK,
        ]);
        $limitedStockProduct = Product::factory()->create([
            'name' => 'Bulgarian Labels Limited Product',
            'sku' => 'BG-LABEL-LIMITED',
            'availability_status_id' => $limitedStock->id,
            'stock_status' => Product::STOCK_STATUS_LIMITED_STOCK,
        ]);

        $this->get(ProductResource::getUrl())
            ->assertOk()
            ->assertSee('Продукти')
            ->assertSee('CSV импорт')
            ->assertSee('CSV експорт')
            ->assertSee('Създай продукт');

        Livewire::test(ListProducts::class)
            ->assertCanSeeTableRecords([$outOfStockProduct, $limitedStockProduct])
            ->assertSee('Снимка')
            ->assertSee('Име на продукта')
            ->assertSee('Категория')
            ->assertSee('Марка')
            ->assertSee('Цена')
            ->assertSee('Вносител')
            ->assertSee('Статус')
            ->assertSee('Наличност')
            ->assertSee('Виж в сайта')
            ->assertSee('Няма наличност')
            ->assertSee('Ограничена наличност');

        $this->get(ProductResource::getUrl('create'))
            ->assertOk()
            ->assertSee('Създай продукт')
            ->assertSee('Основна информация')
            ->assertSee('Име')
            ->assertSee('Категория')
            ->assertSee('Бранд')
            ->assertSee('Работен статус')
            ->assertSee('Цени и наличност')
            ->assertSee('Промо цена')
            ->assertSee('Количество')
            ->assertSee('SEO')
            ->assertSee('Meta заглавие')
            ->assertSee('Meta описание');

        $this->get(ProductResource::getUrl('edit', ['record' => $outOfStockProduct]))
            ->assertOk()
            ->assertSee('Редакция на продукт')
            ->assertSee('Работен процес на продукта')
            ->assertSee('Флагове за качество')
            ->assertSee('Снимки');

        $outOfStockProduct->refresh();
        $limitedStockProduct->refresh();

        $this->assertSame('Bulgarian Labels Out Product', $outOfStockProduct->name);
        $this->assertSame('Bulgarian Labels Limited Product', $limitedStockProduct->name);
        $this->assertSame(Product::STOCK_STATUS_OUT_OF_STOCK, $outOfStockProduct->stock_status);
        $this->assertSame(Product::STOCK_STATUS_LIMITED_STOCK, $limitedStockProduct->stock_status);
    }

    public function test_super_admin_can_bulk_soft_delete_selected_products(): void
    {
        $this->actingAsRole(User::ROLE_SUPER_ADMIN);

        $products = Product::factory()->count(2)->create([
            'workflow_status' => Product::WORKFLOW_PUBLISHED,
            'product_status' => 'active',
            'active' => true,
            'published_at' => now(),
        ]);

        Livewire::test(ListProducts::class)
            ->callTableBulkAction('delete', $products)
            ->assertHasNoTableBulkActionErrors();

        $products->each(function (Product $product): void {
            $this->assertSoftDeleted('products', ['id' => $product->id]);
        });
    }

    public function test_unauthorized_role_cannot_bulk_delete_products(): void
    {
        $this->actingAsRole(User::ROLE_VIEWER_AUDITOR);

        $this->assertFalse(ProductResource::canDeleteAny());
        $this->assertFalse(ProductResource::canViewAny());
    }

    public function test_products_table_defaults_to_newest_products_first_with_id_tie_breaker(): void
    {
        $this->actingAsRole(User::ROLE_SUPER_ADMIN);

        $oldest = Product::factory()->create([
            'name' => 'Oldest product',
            'created_at' => Carbon::parse('2026-06-01 10:00:00'),
        ]);
        $sameTimestampLowerId = Product::factory()->create([
            'name' => 'Same timestamp lower id',
            'created_at' => Carbon::parse('2026-06-20 10:00:00'),
        ]);
        $sameTimestampHigherId = Product::factory()->create([
            'name' => 'Same timestamp higher id',
            'created_at' => Carbon::parse('2026-06-20 10:00:00'),
        ]);
        $latestByTimestamp = Product::factory()->create([
            'name' => 'Latest timestamp product',
            'created_at' => Carbon::parse('2026-06-21 10:00:00'),
        ]);

        Livewire::test(ListProducts::class)
            ->assertCanSeeTableRecords([
                $latestByTimestamp,
                $sameTimestampHigherId,
                $sameTimestampLowerId,
                $oldest,
            ], inOrder: true);
    }

    public function test_bulk_delete_does_not_change_remaining_published_products(): void
    {
        $this->actingAsRole(User::ROLE_SUPER_ADMIN);

        $deletedProduct = Product::factory()->create();
        $remainingProduct = Product::factory()->create([
            'workflow_status' => Product::WORKFLOW_PUBLISHED,
            'product_status' => 'active',
            'active' => true,
            'published_at' => now(),
        ]);

        Livewire::test(ListProducts::class)
            ->callTableBulkAction('delete', collect([$deletedProduct]))
            ->assertHasNoTableBulkActionErrors();

        $remainingProduct->refresh();

        $this->assertSame(Product::WORKFLOW_PUBLISHED, $remainingProduct->workflow_status);
        $this->assertSame('active', $remainingProduct->product_status);
        $this->assertTrue((bool) $remainingProduct->active);
        $this->assertNotNull($remainingProduct->published_at);
    }

    public function test_bulk_restore_keeps_formerly_published_products_non_public(): void
    {
        $this->actingAsRole(User::ROLE_SUPER_ADMIN);

        $products = Product::factory()->count(2)->create([
            'workflow_status' => Product::WORKFLOW_PUBLISHED,
            'product_status' => 'active',
            'active' => true,
            'published_at' => now(),
        ]);
        $products->each->delete();

        Livewire::test(ListProducts::class)
            ->filterTable('trashed', false)
            ->callTableBulkAction('restore', $products)
            ->assertHasNoTableBulkActionErrors();

        $products->each(function (Product $product): void {
            $product->refresh();

            $this->assertSame(Product::WORKFLOW_APPROVED, $product->workflow_status);
            $this->assertSame('hidden', $product->product_status);
            $this->assertFalse((bool) $product->active);
            $this->assertNotNull($product->published_at);
            $this->assertFalse($product->isPubliclyVisible());
        });
    }

    private function actingAsRole(string $role): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create(['role' => $role]);
        $user->assignRole($role);

        $this->actingAs($user);

        return $user;
    }
}

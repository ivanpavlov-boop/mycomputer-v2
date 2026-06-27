<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\ProductResource;
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

        $this->assertSame('Delete selected', $deleteAction?->getLabel());
        $this->assertSame('Delete selected products', (string) $deleteAction?->getModalHeading());
        $this->assertSame('Delete selected', $deleteAction?->getModalSubmitActionLabel());
        $this->assertTrue($deleteAction?->isConfirmationRequired());
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

    private function actingAsRole(string $role): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create(['role' => $role]);
        $user->assignRole($role);

        $this->actingAs($user);

        return $user;
    }
}

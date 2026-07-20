<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductPublishingUxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_storefront_url_uses_configured_origin_only_for_genuinely_public_products(): void
    {
        config()->set('app.url', 'https://storefront.example.test/');

        $public = Product::factory()->create(['slug' => 'public-product']);

        $this->assertSame(
            'https://storefront.example.test/p/public-product',
            $public->storefrontUrl(),
        );

        $encoded = Product::factory()->create(['slug' => 'product with/slash?']);

        $this->assertSame(
            'https://storefront.example.test/p/product%20with%2Fslash%3F',
            $encoded->storefrontUrl(),
        );

        foreach ([
            Product::WORKFLOW_DRAFT,
            Product::WORKFLOW_PENDING_REVIEW,
            Product::WORKFLOW_CHANGES_REQUESTED,
            Product::WORKFLOW_APPROVED,
        ] as $workflowStatus) {
            $product = Product::factory()->create([
                'workflow_status' => $workflowStatus,
                'product_status' => 'active',
                'active' => true,
                'published_at' => now(),
            ]);

            $this->assertNull($product->storefrontUrl(), "{$workflowStatus} must not have a storefront URL.");
        }

        $this->assertNull(Product::factory()->create(['active' => false])->storefrontUrl());
        $this->assertNull(Product::factory()->create(['product_status' => 'hidden'])->storefrontUrl());
        $this->assertNull(Product::factory()->create(['published_at' => null])->storefrontUrl());
        $this->assertNull(Product::factory()->create(['slug' => ''])->storefrontUrl());
        $this->assertNull(Product::factory()->create(['category_id' => null])->storefrontUrl());

        $inactiveCategory = Category::factory()->create(['is_active' => false]);
        $this->assertNull(Product::factory()->create(['category_id' => $inactiveCategory->id])->storefrontUrl());

        $deleted = Product::factory()->create();
        $deleted->delete();

        $this->assertNull($deleted->storefrontUrl());
    }

    public function test_successful_publish_redirects_to_product_list_with_persistent_storefront_notification(): void
    {
        config()->set('app.url', 'https://storefront.example.test');

        $publisher = $this->actingAsRole(User::ROLE_CATALOG_MANAGER);
        $product = Product::factory()->create([
            'slug' => 'approved-product',
            'workflow_status' => Product::WORKFLOW_APPROVED,
            'product_status' => 'hidden',
            'active' => false,
            'published_at' => null,
        ]);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->callAction('publish')
            ->assertHasNoActionErrors()
            ->assertRedirect(ProductResource::getUrl('index'));

        $product->refresh();

        $this->assertSame(Product::WORKFLOW_PUBLISHED, $product->workflow_status);
        $this->assertSame('active', $product->product_status);
        $this->assertTrue((bool) $product->active);
        $this->assertSame($publisher->id, $product->published_by);
        $this->assertTrue($product->isPubliclyVisible());

        $notificationPayloads = session()->get('filament.notifications', []);
        $this->assertCount(1, $notificationPayloads);

        $notification = Notification::fromArray($notificationPayloads[0]);
        $this->assertSame('Продуктът е публикуван успешно', $notification->getTitle());
        $this->assertCount(1, $notification->getActions());

        $storefrontAction = $notification->getActions()[0];
        $this->assertSame('Виж в сайта', $storefrontAction->getLabel());
        $this->assertSame($product->storefrontUrl(), $storefrontAction->getUrl());
        $this->assertTrue($storefrontAction->shouldOpenUrlInNewTab());
    }

    public function test_failed_publish_stays_on_edit_page_without_success_notification(): void
    {
        $this->actingAsRole(User::ROLE_CATALOG_MANAGER);
        $product = Product::factory()->create([
            'slug' => '',
            'workflow_status' => Product::WORKFLOW_APPROVED,
            'product_status' => 'hidden',
            'active' => false,
            'published_at' => null,
        ]);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->callAction('publish')
            ->assertHasErrors(['slug'])
            ->assertNoRedirect()
            ->unmountAction()
            ->assertActionHidden('viewStorefront');

        $product->refresh();

        $this->assertSame(Product::WORKFLOW_APPROVED, $product->workflow_status);
        $this->assertSame('hidden', $product->product_status);
        $this->assertFalse((bool) $product->active);
        $this->assertNull($product->published_at);
        $this->assertNull($product->storefrontUrl());
        $this->assertNotContains(
            'Продуктът е публикуван успешно',
            collect(session()->get('filament.notifications', []))->pluck('title')->all(),
        );
    }

    public function test_edit_page_storefront_action_is_visible_only_for_public_products(): void
    {
        $this->actingAsRole(User::ROLE_SUPER_ADMIN);

        $public = Product::factory()->create(['slug' => 'edit-public-product']);

        Livewire::test(EditProduct::class, ['record' => $public->getRouteKey()])
            ->assertActionVisible('viewStorefront')
            ->assertActionHasUrl('viewStorefront', $public->storefrontUrl())
            ->assertActionShouldOpenUrlInNewTab('viewStorefront');

        foreach ($this->nonPublicProducts() as $product) {
            Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
                ->assertActionHidden('viewStorefront');
        }

        $deleted = Product::factory()->create();
        $deleted->delete();

        Livewire::test(EditProduct::class, ['record' => $deleted->getRouteKey()])
            ->assertActionHidden('viewStorefront');
    }

    public function test_product_table_storefront_column_is_available_only_for_public_products(): void
    {
        $this->actingAsRole(User::ROLE_SUPER_ADMIN);

        $public = Product::factory()->create(['slug' => 'table-public-product']);
        $nonPublic = $this->nonPublicProducts();
        $deleted = Product::factory()->create();
        $deleted->delete();

        $table = Livewire::test(ListProducts::class)
            ->assertTableColumnStateSet('storefront', 'Виж в сайта', $public);

        $storefrontColumn = $table->instance()->getTable()->getColumn('storefront');
        $storefrontColumn->record($public);

        $this->assertSame($public->storefrontUrl(), $storefrontColumn->getUrl());
        $this->assertTrue($storefrontColumn->shouldOpenUrlInNewTab());
        $this->assertFalse($storefrontColumn->isClickDisabled());

        foreach ($nonPublic as $product) {
            $table->assertTableColumnStateSet('storefront', '—', $product);
        }

        $deletedTable = Livewire::test(ListProducts::class)
            ->filterTable('trashed', false)
            ->assertTableColumnStateSet('storefront', '—', $deleted)
            ->assertTableActionVisible('restore', $deleted)
            ->assertTableActionVisible('forceDelete', $deleted);

        $deletedStorefrontColumn = $deletedTable->instance()->getTable()->getColumn('storefront');
        $deletedStorefrontColumn->record($deleted);

        $this->assertNull($deletedStorefrontColumn->getUrl());
        $this->assertTrue($deletedStorefrontColumn->isClickDisabled());
    }

    public function test_hide_stays_on_edit_page_and_removes_storefront_actions_without_erasing_history(): void
    {
        $publisher = $this->actingAsRole(User::ROLE_CATALOG_MANAGER);
        $publishedAt = now()->subHour();
        $product = Product::factory()->create([
            'slug' => 'product-to-hide',
            'published_by' => $publisher->id,
            'published_at' => $publishedAt,
        ]);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertActionVisible('viewStorefront')
            ->callAction('hide')
            ->assertHasNoActionErrors()
            ->assertNoRedirect()
            ->assertActionHidden('viewStorefront');

        $product->refresh();

        $this->assertSame(Product::WORKFLOW_APPROVED, $product->workflow_status);
        $this->assertSame('hidden', $product->product_status);
        $this->assertFalse((bool) $product->active);
        $this->assertSame($publisher->id, $product->published_by);
        $this->assertSame($publishedAt->toDateTimeString(), $product->published_at?->toDateTimeString());
        $this->assertNull($product->storefrontUrl());

        Livewire::test(ListProducts::class)
            ->assertTableColumnStateSet('storefront', '—', $product);

        $this->getJson('/api/v1/products/'.$product->slug)->assertNotFound();
    }

    public function test_non_publish_workflow_actions_continue_without_redirects(): void
    {
        $this->actingAsRole(User::ROLE_PRODUCT_EDITOR);
        $draft = Product::factory()->manualDraft()->create();

        Livewire::test(EditProduct::class, ['record' => $draft->getRouteKey()])
            ->callAction('submitForReview')
            ->assertHasNoActionErrors()
            ->assertNoRedirect();

        $this->actingAsRole(User::ROLE_CATALOG_MANAGER);
        $pendingForChanges = Product::factory()->create([
            'workflow_status' => Product::WORKFLOW_PENDING_REVIEW,
            'product_status' => 'hidden',
            'active' => false,
            'published_at' => null,
        ]);
        $pendingForApproval = Product::factory()->create([
            'workflow_status' => Product::WORKFLOW_PENDING_REVIEW,
            'product_status' => 'hidden',
            'active' => false,
            'published_at' => null,
        ]);

        Livewire::test(EditProduct::class, ['record' => $pendingForChanges->getRouteKey()])
            ->callAction('requestChanges', ['review_notes' => 'Нужна е корекция.'])
            ->assertHasNoActionErrors()
            ->assertNoRedirect();

        Livewire::test(EditProduct::class, ['record' => $pendingForApproval->getRouteKey()])
            ->callAction('approve')
            ->assertHasNoActionErrors()
            ->assertNoRedirect();
    }

    /** @return list<Product> */
    private function nonPublicProducts(): array
    {
        $products = [];

        foreach ([
            Product::WORKFLOW_DRAFT,
            Product::WORKFLOW_PENDING_REVIEW,
            Product::WORKFLOW_CHANGES_REQUESTED,
            Product::WORKFLOW_APPROVED,
        ] as $workflowStatus) {
            $products[] = Product::factory()->create([
                'workflow_status' => $workflowStatus,
                'product_status' => $workflowStatus === Product::WORKFLOW_DRAFT ? 'draft' : 'hidden',
                'active' => false,
                'published_at' => null,
            ]);
        }

        $inactiveCategory = Category::factory()->create(['is_active' => false]);
        $products[] = Product::factory()->create(['category_id' => $inactiveCategory->id]);

        return $products;
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create([
            'role' => $role,
            'is_active' => true,
        ]);
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }
}

<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCompareList;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WishlistCompareApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_wishlist(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/account/wishlists', [
                'name' => 'Gaming build',
                'is_default' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Gaming build')
            ->assertJsonPath('data.is_default', true);
    }

    public function test_authenticated_user_can_add_product_without_duplicates(): void
    {
        $user = User::factory()->create();
        $wishlist = Wishlist::query()->create(['user_id' => $user->id, 'name' => 'Любими', 'is_default' => true]);
        $product = Product::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/account/wishlists/{$wishlist->id}/items", ['product_id' => $product->id])
            ->assertOk()
            ->assertJsonPath('data.items_count', 1);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/account/wishlists/{$wishlist->id}/items", ['product_id' => $product->id])
            ->assertOk()
            ->assertJsonPath('data.items_count', 1);

        $this->assertDatabaseCount('wishlist_items', 1);
    }

    public function test_inactive_product_cannot_be_added_to_wishlist(): void
    {
        $user = User::factory()->create();
        $wishlist = Wishlist::query()->create(['user_id' => $user->id, 'name' => 'Любими', 'is_default' => true]);
        $product = Product::factory()->create(['active' => false]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/account/wishlists/{$wishlist->id}/items", ['product_id' => $product->id])
            ->assertUnprocessable();
    }

    public function test_user_cannot_access_another_users_wishlist(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $wishlist = Wishlist::query()->create(['user_id' => $owner->id, 'name' => 'Private', 'is_default' => true]);

        $this->actingAs($other, 'sanctum')
            ->getJson("/api/v1/account/wishlists/{$wishlist->id}/items")
            ->assertNotFound();
    }

    public function test_toggle_and_delete_wishlist_item(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        $wishlistId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/account/wishlist/toggle', ['product_id' => $product->id])
            ->assertOk()
            ->assertJsonPath('data.added', true)
            ->json('data.wishlist.id');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/account/wishlist/toggle', ['product_id' => $product->id])
            ->assertOk()
            ->assertJsonPath('data.added', false);

        $this->assertDatabaseMissing('wishlist_items', ['wishlist_id' => $wishlistId, 'product_id' => $product->id]);
    }

    public function test_guest_can_add_compare_product_by_session(): void
    {
        $product = Product::factory()->create();

        $this->withHeader('X-Compare-Session', '11111111-1111-4111-8111-111111111111')
            ->postJson('/api/v1/compare/items', ['product_id' => $product->id])
            ->assertOk()
            ->assertJsonPath('data.items_count', 1)
            ->assertJsonPath('data.session_id', '11111111-1111-4111-8111-111111111111');
    }

    public function test_authenticated_user_can_add_compare_product_without_duplicates(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/compare/items', ['product_id' => $product->id])
            ->assertOk()
            ->assertJsonPath('data.items_count', 1);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/compare/items', ['product_id' => $product->id])
            ->assertOk()
            ->assertJsonPath('data.items_count', 1);

        $this->assertDatabaseCount('product_compare_items', 1);
    }

    public function test_compare_list_has_max_four_products(): void
    {
        $user = User::factory()->create();
        $products = Product::factory()->count(5)->create();

        foreach ($products->take(4) as $product) {
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/v1/compare/items', ['product_id' => $product->id])
                ->assertOk();
        }

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/compare/items', ['product_id' => $products->last()->id])
            ->assertUnprocessable();
    }

    public function test_inactive_product_cannot_be_compared(): void
    {
        $product = Product::factory()->create(['published_at' => null]);

        $this->postJson('/api/v1/compare/items', ['product_id' => $product->id])
            ->assertUnprocessable();
    }

    public function test_compare_list_can_be_cleared(): void
    {
        $product = Product::factory()->create();

        $this->withHeader('X-Compare-Session', '22222222-2222-4222-8222-222222222222')
            ->postJson('/api/v1/compare/items', ['product_id' => $product->id])
            ->assertOk();

        $this->withHeader('X-Compare-Session', '22222222-2222-4222-8222-222222222222')
            ->deleteJson('/api/v1/compare/list')
            ->assertOk()
            ->assertJsonPath('data.items_count', 0);
    }

    public function test_guest_compare_list_can_merge_into_user_list(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $guestList = ProductCompareList::query()->create(['session_id' => '33333333-3333-4333-8333-333333333333']);
        $guestList->items()->create(['product_id' => $product->id, 'sort_order' => 1]);

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Compare-Session', '33333333-3333-4333-8333-333333333333')
            ->postJson('/api/v1/compare/merge')
            ->assertOk()
            ->assertJsonPath('data.items_count', 1);

        $this->assertDatabaseHas('product_compare_lists', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('product_compare_lists', ['session_id' => '33333333-3333-4333-8333-333333333333']);
    }
}

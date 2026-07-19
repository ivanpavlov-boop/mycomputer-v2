<?php

namespace App\Services\Wishlists;

use App\Models\Product;
use App\Models\User;
use App\Models\Wishlist;

class WishlistService
{
    public function defaultFor(User $user): Wishlist
    {
        return $user->wishlists()->firstOrCreate(
            ['is_default' => true],
            ['name' => 'Любими продукти'],
        );
    }

    public function create(User $user, array $data): Wishlist
    {
        if (($data['is_default'] ?? false) === true) {
            $user->wishlists()->update(['is_default' => false]);
        }

        return $user->wishlists()->create([
            'name' => $data['name'],
            'is_default' => $data['is_default'] ?? false,
        ]);
    }

    public function update(Wishlist $wishlist, array $data): Wishlist
    {
        if (($data['is_default'] ?? false) === true) {
            $wishlist->user->wishlists()
                ->whereKeyNot($wishlist->id)
                ->update(['is_default' => false]);
        }

        $wishlist->update($data);

        return $wishlist->fresh(['items.product.brand', 'items.product.category', 'items.product.images']);
    }

    public function add(Wishlist $wishlist, Product $product): Wishlist
    {
        $this->assertPublicProduct($product);

        $wishlist->items()->firstOrCreate(['product_id' => $product->id]);

        return $wishlist->fresh(['items.product.brand', 'items.product.category', 'items.product.images']);
    }

    public function remove(Wishlist $wishlist, Product $product): Wishlist
    {
        $wishlist->items()->where('product_id', $product->id)->delete();

        return $wishlist->fresh(['items.product.brand', 'items.product.category', 'items.product.images']);
    }

    public function toggle(User $user, Product $product): array
    {
        $wishlist = $this->defaultFor($user);
        $existing = $wishlist->items()->where('product_id', $product->id)->first();

        if ($existing) {
            $existing->delete();

            return ['added' => false, 'wishlist' => $wishlist->fresh(['items.product.brand', 'items.product.category', 'items.product.images'])];
        }

        return ['added' => true, 'wishlist' => $this->add($wishlist, $product)];
    }

    private function assertPublicProduct(Product $product): void
    {
        abort_unless($product->isPubliclyVisible(), 422, 'Product is not available.');
    }
}

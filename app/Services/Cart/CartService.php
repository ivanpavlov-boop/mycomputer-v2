<?php

namespace App\Services\Cart;

use App\Models\Cart;
use App\Models\CartBundleItem;
use App\Models\CartItem;
use App\Models\Product;

class CartService
{
    public const MAX_QUANTITY = 99;

    public function __construct(private readonly CartReadinessService $readiness) {}

    public function add(Cart $cart, Product $product, int $quantity): Cart
    {
        $item = $cart->items()->where('product_id', $product->id)->first();
        $quantity = ($item?->quantity ?? 0) + $quantity;
        $this->readiness->assertProductCanBeCartQuantity($product, $quantity);
        $unitPrice = $this->price($product);

        if ($item) {
            $item->update([
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => $unitPrice * $quantity,
            ]);
        } else {
            $cart->items()->create([
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => $unitPrice * $quantity,
            ]);
        }

        return $this->recalculate($cart);
    }

    public function update(Cart $cart, CartItem $item, int $quantity): Cart
    {
        abort_unless($item->cart_id === $cart->id, 404);
        $product = $this->readiness->assertProductIdCanBeCartQuantity(
            (int) $item->product_id,
            $quantity,
        );
        $unitPrice = $this->price($product);
        $item->update([
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total_price' => $unitPrice * $quantity,
        ]);

        return $this->recalculate($cart);
    }

    public function remove(Cart $cart, CartItem $item): Cart
    {
        abort_unless($item->cart_id === $cart->id, 404);
        $item->delete();

        return $this->recalculate($cart);
    }

    public function clear(Cart $cart): Cart
    {
        $cart->items()->delete();
        $cart->bundleItems()->delete();

        return $this->recalculate($cart);
    }

    public function recalculate(Cart $cart): Cart
    {
        return app(CartPricingRefreshService::class)->refresh($cart, refreshAutomaticGifts: false)->cart;
    }

    public function subtotal(Cart $cart): float
    {
        $items = $cart->relationLoaded('items') ? $cart->items : $cart->items()->get();
        $bundleItems = $cart->relationLoaded('bundleItems') ? $cart->bundleItems : $cart->bundleItems()->get();

        return round(
            (float) $items->sum(fn (CartItem $item): float => (float) $item->total_price)
                + (float) $bundleItems->sum(fn (CartBundleItem $item): float => (float) $item->total_price),
            2,
        );
    }

    public function price(Product $product): float
    {
        return $product->effectivePrice();
    }
}

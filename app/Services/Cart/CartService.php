<?php

namespace App\Services\Cart;

use App\Exceptions\CartGiftLineImmutableException;
use App\Models\Cart;
use App\Models\CartBundleItem;
use App\Models\CartItem;
use App\Models\Product;
use App\Services\Promotions\PromotionEngineService;
use Illuminate\Support\Collection;

class CartService
{
    public const MAX_QUANTITY = 99;

    public function __construct(
        private readonly CartReadinessService $readiness,
        private readonly CartMutationService $mutations,
    ) {}

    public function add(Cart $cart, Product $product, int $quantity): Cart
    {
        return $this->addMany($cart, [(int) $product->getKey() => $quantity]);
    }

    /**
     * @param  array<int, int>  $quantitiesByProductId
     */
    public function addMany(Cart $cart, array $quantitiesByProductId): Cart
    {
        $requested = collect($quantitiesByProductId)
            ->mapWithKeys(fn (mixed $quantity, mixed $productId): array => [
                (int) $productId => (int) $quantity,
            ])
            ->filter(fn (int $quantity, int $productId): bool => $productId > 0 && $quantity > 0)
            ->sortKeys();

        return $this->mutations->run($cart, function (Cart $lockedCart) use ($requested): Cart {
            /** @var Collection<int, CartItem> $paidItems */
            $paidItems = $lockedCart->items()
                ->paid()
                ->whereIn('product_id', $requested->keys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');
            $resultingQuantities = $requested->mapWithKeys(
                fn (int $quantity, int $productId): array => [
                    $productId => (int) ($paidItems->get($productId)?->quantity ?? 0) + $quantity,
                ],
            )->all();
            $products = $this->readiness->assertProductQuantities($resultingQuantities);

            foreach ($resultingQuantities as $productId => $quantity) {
                $product = $products->get($productId);
                $item = $paidItems->get($productId);
                $unitPrice = $this->price($product);
                $values = [
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_price' => round($unitPrice * $quantity, 2),
                ];

                if ($item === null) {
                    $lockedCart->items()->create([
                        'product_id' => $productId,
                        'is_gift' => false,
                    ] + $values);
                } else {
                    $item->update($values);
                }
            }

            return $this->reconcileLocked($lockedCart);
        });
    }

    public function update(Cart $cart, CartItem $item, int $quantity): Cart
    {
        return $this->mutations->run($cart, function (Cart $lockedCart) use ($item, $quantity): Cart {
            $lockedItem = $lockedCart->items()
                ->whereKey($item->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedItem->isGiftLine()) {
                throw new CartGiftLineImmutableException;
            }

            $product = $this->readiness->assertProductIdCanBeCartQuantity(
                (int) $lockedItem->product_id,
                $quantity,
            );
            $unitPrice = $this->price($product);
            $lockedItem->update([
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => round($unitPrice * $quantity, 2),
            ]);

            return $this->reconcileLocked($lockedCart);
        });
    }

    public function remove(Cart $cart, CartItem $item): Cart
    {
        return $this->mutations->run($cart, function (Cart $lockedCart) use ($item): Cart {
            $lockedItem = $lockedCart->items()
                ->whereKey($item->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedItem->isGiftLine()) {
                throw new CartGiftLineImmutableException;
            }

            $lockedItem->delete();

            return $this->reconcileLocked($lockedCart);
        });
    }

    public function clear(Cart $cart): Cart
    {
        return $this->mutations->run($cart, function (Cart $lockedCart): Cart {
            $lockedCart->items()->delete();
            $lockedCart->bundleItems()->delete();

            return app(CartPricingRefreshService::class)
                ->refreshLocked($lockedCart, refreshAutomaticGifts: false)
                ->cart;
        });
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

    private function reconcileLocked(Cart $cart): Cart
    {
        $cart = app(CartPricingRefreshService::class)
            ->refreshLocked($cart, refreshAutomaticGifts: false)
            ->cart;
        $cart = app(PromotionEngineService::class)->applyAutomaticGiftsLocked($cart);

        return app(CartPricingRefreshService::class)
            ->refreshLocked($cart, refreshAutomaticGifts: false)
            ->cart;
    }
}

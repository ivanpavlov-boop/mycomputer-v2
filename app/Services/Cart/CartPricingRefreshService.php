<?php

namespace App\Services\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Services\Bundles\BundlePricingService;
use App\Services\Promotions\PromotionEngineService;

class CartPricingRefreshService
{
    public function __construct(
        private readonly CartService $carts,
        private readonly BundlePricingService $bundles,
        private readonly PromotionEngineService $promotions,
    ) {}

    public function refresh(Cart $cart, bool $refreshAutomaticGifts = true): CartPricingRefreshResult
    {
        $cart = $this->load($cart);
        $subtotalBefore = $this->carts->subtotal($cart);
        $giftsBefore = $this->giftState($cart);
        $promotionsBefore = $refreshAutomaticGifts
            ? $this->promotionState($this->promotions->evaluate($cart))
            : null;
        $itemChanges = [];
        $bundleChanges = [];
        $giftPricesChanged = false;
        $paidPricingChanged = false;

        foreach ($cart->items as $item) {
            if ($item->is_gift) {
                if ($this->cents($item->unit_price) !== 0 || $this->cents($item->total_price) !== 0) {
                    $item->update(['unit_price' => 0, 'total_price' => 0]);
                    $giftPricesChanged = true;
                }

                continue;
            }

            $unitPrice = $this->carts->price($item->product);
            $totalPrice = round($unitPrice * $item->quantity, 2);

            if ($this->cents($item->unit_price) === $this->cents($unitPrice)
                && $this->cents($item->total_price) === $this->cents($totalPrice)) {
                continue;
            }

            $itemChanges[] = [
                'cart_item_id' => $item->id,
                'product_id' => $item->product_id,
                'unit_price_before' => round((float) $item->unit_price, 2),
                'unit_price_after' => $unitPrice,
                'total_price_before' => round((float) $item->total_price, 2),
                'total_price_after' => $totalPrice,
            ];
            $item->update(['unit_price' => $unitPrice, 'total_price' => $totalPrice]);
            $paidPricingChanged = true;
        }

        foreach ($cart->bundleItems as $item) {
            $pricing = $this->bundles->calculate($item->bundle, $item->selected_items ?? []);
            $pricing['selected_items'] = $this->preserveSelectedItemOrder(
                $item->selected_items ?? [],
                $pricing['selected_items'],
            );
            $totalPrice = round($pricing['unit_price'] * $item->quantity, 2);
            $selectedItemsChanged = ! $this->sameSelectedItems(
                $item->selected_items ?? [],
                $pricing['selected_items'],
            );

            if (! $selectedItemsChanged
                && $this->cents($item->unit_price) === $this->cents($pricing['unit_price'])
                && $this->cents($item->total_price) === $this->cents($totalPrice)) {
                continue;
            }

            $bundleChanges[] = [
                'cart_bundle_item_id' => $item->id,
                'product_bundle_id' => $item->product_bundle_id,
                'component_prices_changed' => $selectedItemsChanged,
                'unit_price_before' => round((float) $item->unit_price, 2),
                'unit_price_after' => $pricing['unit_price'],
                'total_price_before' => round((float) $item->total_price, 2),
                'total_price_after' => $totalPrice,
            ];
            $item->update([
                'selected_items' => $pricing['selected_items'],
                'unit_price' => $pricing['unit_price'],
                'total_price' => $totalPrice,
            ]);
            $paidPricingChanged = true;
        }

        $cart = $this->load($cart->fresh());

        if ($refreshAutomaticGifts && $paidPricingChanged) {
            $cart = $this->load($this->promotions->applyAutomaticGifts($cart));
        }

        $giftsAfter = $this->giftState($cart);
        $promotionsAfter = $refreshAutomaticGifts
            ? $this->promotionState($this->promotions->evaluate($cart))
            : null;
        $subtotalAfter = $this->carts->subtotal($cart);
        $giftStateChanged = $giftsBefore !== $giftsAfter;
        $promotionStateChanged = $refreshAutomaticGifts && $promotionsBefore !== $promotionsAfter;
        $subtotalChanged = $this->cents($subtotalBefore) !== $this->cents($subtotalAfter);
        $changed = $itemChanges !== [] || $bundleChanges !== [] || $giftPricesChanged || $giftStateChanged;

        return new CartPricingRefreshResult(
            cart: $cart,
            changed: $changed,
            requiresReview: $changed || $promotionStateChanged || $subtotalChanged,
            regularItemChanges: $itemChanges,
            bundleChanges: $bundleChanges,
            giftStateChanged: $giftStateChanged,
            subtotalBefore: $subtotalBefore,
            subtotalAfter: $subtotalAfter,
        );
    }

    private function load(Cart $cart): Cart
    {
        return $cart->load([
            'items.product.brand',
            'items.product.category',
            'items.product.images',
            'items.product.availabilityStatus',
            'bundleItems.bundle.items.product',
            'bundleItems.bundle.options.product',
            'user.loyaltyAccount',
        ]);
    }

    private function giftState(Cart $cart): array
    {
        return $cart->items
            ->where('is_gift', true)
            ->map(fn (CartItem $item): array => [
                'product_id' => (int) $item->product_id,
                'quantity' => (int) $item->quantity,
                'promotion_id' => $item->promotion_id ? (int) $item->promotion_id : null,
            ])
            ->sortBy(fn (array $gift): string => implode(':', $gift))
            ->values()
            ->all();
    }

    private function promotionState(array $result): array
    {
        return [
            'ids' => collect($result['applied_promotions'])->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            'discount_total' => $this->cents($result['discount_total']),
            'shipping_discount' => $this->cents($result['shipping_discount']),
        ];
    }

    private function sameSelectedItems(array $stored, array $expected): bool
    {
        return $this->canonicalize($stored) === $this->canonicalize($expected);
    }

    private function preserveSelectedItemOrder(array $stored, array $expected): array
    {
        if (count($stored) !== count($expected)) {
            return $expected;
        }

        $remaining = $expected;
        $ordered = [];

        foreach ($stored as $storedLine) {
            $matchIndex = collect($remaining)->search(
                fn (array $expectedLine): bool => (int) ($expectedLine['product_id'] ?? 0) === (int) ($storedLine['product_id'] ?? 0)
                    && ($expectedLine['component_group'] ?? null) === ($storedLine['component_group'] ?? null),
            );

            if ($matchIndex === false) {
                return $expected;
            }

            $ordered[] = $remaining[$matchIndex];
            unset($remaining[$matchIndex]);
        }

        return $ordered;
    }

    private function canonicalize(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            } elseif (in_array($key, ['product_id', 'quantity'], true)) {
                $value[$key] = (int) $item;
            } elseif (in_array($key, ['unit_price', 'price_adjustment'], true)) {
                $value[$key] = $this->cents($item);
            }
        }

        return $value;
    }

    private function cents(float|int|string|null $value): int
    {
        return (int) round(((float) $value) * 100);
    }
}

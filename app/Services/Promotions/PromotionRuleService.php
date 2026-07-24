<?php

namespace App\Services\Promotions;

use App\Models\Cart;
use App\Models\CartBundleItem;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\PromotionRule;

class PromotionRuleService
{
    public function passes(PromotionRule $rule, Cart $cart): bool
    {
        $value = is_array($rule->value) ? ($rule->value['value'] ?? $rule->value) : $rule->value;

        return match ($rule->rule_type) {
            'minimum_order_amount' => $this->compare($this->subtotal($cart), $rule->operator, (float) $value),
            'category_id' => $this->items($cart)->contains(fn (CartItem $item): bool => (int) $item->product?->category_id === (int) $value),
            'brand_id' => $this->items($cart)->contains(fn (CartItem $item): bool => (int) $item->product?->brand_id === (int) $value),
            'product_id' => $this->items($cart)->contains(fn (CartItem $item): bool => (int) $item->product_id === (int) $value),
            'bundle_id' => $this->bundleItems($cart)->contains(fn (CartBundleItem $item): bool => (int) $item->product_bundle_id === (int) $value),
            'bundle_type' => $this->bundleItems($cart)->contains(fn (CartBundleItem $item): bool => $item->bundle?->type === (string) $value),
            'bundle_contains_product' => $this->bundleContainsProduct($cart, (int) $value),
            'bundle_contains_brand' => $this->bundleContainsBrand($cart, (int) $value),
            'quantity_min' => $this->items($cart)->sum('quantity') >= (int) $value,
            'loyalty_tier' => app(PromotionValidatorService::class)->loyaltyTier($cart) === (string) $value,
            'b2b_ready' => true,
            'per_user_limit', 'per_session_limit' => true,
            default => true,
        };
    }

    public function matchingItems(Cart $cart, string $scope, mixed $value): iterable
    {
        return $this->items($cart)->filter(fn (CartItem $item): bool => match ($scope) {
            'category_id' => (int) $item->product?->category_id === (int) $value,
            'brand_id' => (int) $item->product?->brand_id === (int) $value,
            'product_id' => (int) $item->product_id === (int) $value,
            default => true,
        });
    }

    private function subtotal(Cart $cart): float
    {
        return (float) $this->items($cart)->sum('total_price')
            + (float) $this->bundleItems($cart)->sum('total_price');
    }

    private function items(Cart $cart)
    {
        $items = $cart->relationLoaded('items')
            ? $cart->items
            : $cart->items()->with('product')->get();

        return $items->filter(fn (CartItem $item): bool => $item->isPaidLine());
    }

    private function bundleItems(Cart $cart)
    {
        return $cart->relationLoaded('bundleItems') ? $cart->bundleItems : $cart->bundleItems()->with('bundle')->get();
    }

    private function bundleContainsProduct(Cart $cart, int $productId): bool
    {
        return $this->bundleItems($cart)->contains(fn (CartBundleItem $item): bool => collect($item->selected_items)->contains(
            fn (array $line): bool => (int) ($line['product_id'] ?? 0) === $productId
        ));
    }

    private function bundleContainsBrand(Cart $cart, int $brandId): bool
    {
        $productIds = $this->bundleItems($cart)
            ->flatMap(fn (CartBundleItem $item) => collect($item->selected_items)->pluck('product_id'))
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique();

        if ($productIds->isEmpty()) {
            return false;
        }

        return Product::query()->whereIn('id', $productIds)->where('brand_id', $brandId)->exists();
    }

    private function compare(float $left, string $operator, float $right): bool
    {
        return match ($operator) {
            'gt' => $left > $right,
            'gte', '>=', 'equals_or_greater' => $left >= $right,
            'lt' => $left < $right,
            'lte', '<=' => $left <= $right,
            'equals', '=' => $left === $right,
            default => $left >= $right,
        };
    }
}

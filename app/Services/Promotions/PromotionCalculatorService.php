<?php

namespace App\Services\Promotions;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Promotion;

class PromotionCalculatorService
{
    public function calculate(Promotion $promotion, Cart $cart, float $subtotal, float $shippingPrice = 0): array
    {
        $discount = 0.0;
        $shippingDiscount = 0.0;
        $gifts = [];

        foreach ($promotion->actions as $action) {
            $config = $action->configuration ?? [];
            $amount = (float) ($config['amount'] ?? $config['value'] ?? 0);
            $scope = $config['scope'] ?? null;
            $scopeValue = $config['scope_value'] ?? null;

            match ($action->action_type) {
                'percentage_discount' => $discount += $this->percentage($cart, $subtotal, $amount, $scope, $scopeValue),
                'fixed_discount' => $discount += min($subtotal, $amount),
                'free_shipping' => $shippingDiscount = max($shippingDiscount, $shippingPrice),
                'gift_product' => $gifts[] = ['product_id' => (int) ($config['product_id'] ?? 0), 'quantity' => (int) ($config['quantity'] ?? 1)],
                'bundle_discount' => $discount += $this->bundleDiscount($cart, $config, $subtotal),
                'buy_x_get_y' => $discount += $this->buyXGetY($cart, $config),
                default => null,
            };
        }

        return [
            'promotion' => $promotion,
            'discount' => round(min($discount, $subtotal), 2),
            'shipping_discount' => round(min($shippingDiscount, $shippingPrice), 2),
            'gift_products' => array_values(array_filter($gifts, fn (array $gift): bool => $gift['product_id'] > 0)),
        ];
    }

    private function percentage(Cart $cart, float $subtotal, float $percent, ?string $scope, mixed $scopeValue): float
    {
        if ($scope) {
            $base = collect(app(PromotionRuleService::class)->matchingItems($cart, $scope, $scopeValue))
                ->sum(fn (CartItem $item): float => (float) $item->total_price);

            return $base * ($percent / 100);
        }

        return $subtotal * ($percent / 100);
    }

    private function bundleDiscount(Cart $cart, array $config, float $subtotal): float
    {
        $productIds = collect($config['product_ids'] ?? [])->map(fn ($id): int => (int) $id)->filter();
        if ($productIds->isEmpty()) {
            return 0.0;
        }

        $items = $cart->items->whereIn('product_id', $productIds);
        if ($items->pluck('product_id')->unique()->count() < $productIds->unique()->count()) {
            return 0.0;
        }

        $bundleSubtotal = (float) $items->sum('total_price');
        if (isset($config['fixed_price'])) {
            return max(0, $bundleSubtotal - (float) $config['fixed_price']);
        }

        return $bundleSubtotal * ((float) ($config['percentage'] ?? 0) / 100);
    }

    private function buyXGetY(Cart $cart, array $config): float
    {
        $buyProductId = (int) ($config['buy_product_id'] ?? $config['product_id'] ?? 0);
        $getProductId = (int) ($config['get_product_id'] ?? $buyProductId);
        $buyQty = max(1, (int) ($config['buy_quantity'] ?? 1));
        $getQty = max(1, (int) ($config['get_quantity'] ?? 1));

        $buyItem = $cart->items->firstWhere('product_id', $buyProductId);
        $getItem = $cart->items->firstWhere('product_id', $getProductId);
        if (! $buyItem || ! $getItem || $buyItem->quantity < $buyQty) {
            return 0.0;
        }

        $freeUnits = (int) floor($buyItem->quantity / $buyQty) * $getQty;

        return min($freeUnits, $getItem->quantity) * (float) $getItem->unit_price;
    }
}

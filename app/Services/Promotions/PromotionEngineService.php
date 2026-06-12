<?php

namespace App\Services\Promotions;

use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionRedemption;
use App\Services\Marketing\MarketingEventService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PromotionEngineService
{
    public function __construct(
        private readonly PromotionValidatorService $validator,
        private readonly PromotionCalculatorService $calculator,
        private readonly MarketingEventService $events,
    ) {}

    public function applyCoupon(Cart $cart, string $code): Cart
    {
        $promotion = Promotion::query()->available()->where('code', strtoupper($code))->with(['rules', 'actions'])->first();
        $cart->coupon_code = strtoupper($code);

        if (! $promotion || ! $this->validator->isAvailable($promotion, $cart->loadMissing(['items.product', 'bundleItems.bundle', 'user.loyaltyAccount']))) {
            throw ValidationException::withMessages(['coupon' => 'Coupon is invalid or cannot be applied to this cart.']);
        }

        $cart->update(['coupon_code' => strtoupper($code)]);

        $this->events->log('coupon_used', 'internal', [
            'promotion_id' => $promotion->id,
            'code' => $promotion->code,
        ], $cart->user, $cart->session_id);

        return $this->applyAutomaticGifts($cart->fresh(['items.product', 'bundleItems.bundle', 'user.loyaltyAccount']));
    }

    public function removeCoupon(Cart $cart): Cart
    {
        $cart->update(['coupon_code' => null]);
        $cart->items()->where('is_gift', true)->delete();

        return $cart->fresh(['items.product.brand', 'items.product.category', 'items.product.images', 'bundleItems.bundle']);
    }

    public function evaluate(Cart $cart, float $shippingPrice = 0): array
    {
        $cart->loadMissing(['items.product', 'bundleItems.bundle', 'user.loyaltyAccount']);
        $subtotal = (float) $cart->items->where('is_gift', false)->sum('total_price')
            + (float) $cart->bundleItems->sum('total_price');
        $applied = [];
        $totalDiscount = 0.0;
        $shippingDiscount = 0.0;
        $gifts = [];

        $promotions = Promotion::query()
            ->available()
            ->with(['rules', 'actions'])
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get()
            ->filter(fn (Promotion $promotion): bool => ! $promotion->code || strcasecmp((string) $cart->coupon_code, $promotion->code) === 0);

        foreach ($promotions as $promotion) {
            if (! $this->validator->isAvailable($promotion, $cart)) {
                continue;
            }

            $calculated = $this->calculator->calculate($promotion, $cart, max(0, $subtotal - $totalDiscount), $shippingPrice - $shippingDiscount);
            if ($calculated['discount'] <= 0 && $calculated['shipping_discount'] <= 0 && $calculated['gift_products'] === []) {
                continue;
            }

            if (! $promotion->stackable && $applied !== []) {
                $current = $totalDiscount + $shippingDiscount;
                $candidate = $calculated['discount'] + $calculated['shipping_discount'];
                if ($candidate <= $current) {
                    continue;
                }

                $applied = [];
                $totalDiscount = 0.0;
                $shippingDiscount = 0.0;
                $gifts = [];
            }

            $applied[] = [
                'id' => $promotion->id,
                'name' => $promotion->name,
                'code' => $promotion->code,
                'type' => $promotion->type,
                'discount' => $calculated['discount'],
                'shipping_discount' => $calculated['shipping_discount'],
            ];
            $totalDiscount += $calculated['discount'];
            $shippingDiscount += $calculated['shipping_discount'];
            $gifts = array_merge($gifts, $calculated['gift_products']);

            if ($promotion->stop_further_rules) {
                break;
            }
        }

        return [
            'applied_promotions' => $applied,
            'discount_total' => round(min($totalDiscount, $subtotal), 2),
            'shipping_discount' => round(min($shippingDiscount, $shippingPrice), 2),
            'gift_products' => $gifts,
            'audit' => [
                'subtotal' => $subtotal,
                'shipping_price' => $shippingPrice,
                'coupon_code' => $cart->coupon_code,
            ],
        ];
    }

    public function applyAutomaticGifts(Cart $cart): Cart
    {
        $result = $this->evaluate($cart);
        $giftIds = collect($result['gift_products'])->pluck('product_id')->all();

        $cart->items()->where('is_gift', true)->whereNotIn('product_id', $giftIds ?: [0])->delete();

        foreach ($result['gift_products'] as $gift) {
            $product = Product::query()->published()->where('active', true)->find($gift['product_id']);
            if (! $product) {
                continue;
            }

            $cart->items()->updateOrCreate(
                ['product_id' => $product->id, 'is_gift' => true],
                [
                    'quantity' => max(1, (int) $gift['quantity']),
                    'promotion_id' => $result['applied_promotions'][0]['id'] ?? null,
                    'unit_price' => 0,
                    'total_price' => 0,
                ],
            );

            $this->events->log('gift_added', 'internal', ['product_id' => $product->id], $cart->user, $cart->session_id);
        }

        return $cart->fresh(['items.product.brand', 'items.product.category', 'items.product.images', 'bundleItems.bundle', 'user.loyaltyAccount']);
    }

    public function recordRedemptions(Cart $cart, Order $order, array $result): void
    {
        DB::transaction(function () use ($cart, $order, $result): void {
            foreach ($result['applied_promotions'] as $applied) {
                PromotionRedemption::query()->create([
                    'promotion_id' => $applied['id'],
                    'order_id' => $order->id,
                    'user_id' => $cart->user_id,
                    'session_id' => $cart->session_id,
                    'discount_amount' => $applied['discount'] + $applied['shipping_discount'],
                ]);

                Promotion::query()->whereKey($applied['id'])->increment('usage_count');
                $this->events->log('promotion_applied', 'internal', $applied + ['order_id' => $order->id], $cart->user, $cart->session_id);
            }
        });
    }
}

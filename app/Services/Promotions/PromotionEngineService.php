<?php

namespace App\Services\Promotions;

use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionRedemption;
use App\Services\Cart\CartMutationService;
use App\Services\Marketing\MarketingEventService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PromotionEngineService
{
    public function __construct(
        private readonly PromotionValidatorService $validator,
        private readonly PromotionCalculatorService $calculator,
        private readonly MarketingEventService $events,
        private readonly CartMutationService $mutations,
    ) {}

    public function applyCoupon(Cart $cart, string $code): Cart
    {
        return $this->mutations->run($cart, function (Cart $lockedCart) use ($code): Cart {
            $promotion = Promotion::query()
                ->available()
                ->where('code', strtoupper($code))
                ->with(['rules', 'actions'])
                ->first();
            $lockedCart->coupon_code = strtoupper($code);

            if (! $promotion || ! $this->validator->isAvailable(
                $promotion,
                $lockedCart->loadMissing(['items.product', 'bundleItems.bundle', 'user.loyaltyAccount']),
            )) {
                throw ValidationException::withMessages([
                    'coupon' => 'Coupon is invalid or cannot be applied to this cart.',
                ]);
            }

            $lockedCart->update(['coupon_code' => strtoupper($code)]);

            DB::afterCommit(fn () => $this->events->log('coupon_used', 'internal', [
                'promotion_id' => $promotion->id,
                'code' => $promotion->code,
            ], $lockedCart->user, $lockedCart->session_id));

            return $this->applyAutomaticGiftsLocked($lockedCart);
        });
    }

    public function removeCoupon(Cart $cart): Cart
    {
        return $this->mutations->run($cart, function (Cart $lockedCart): Cart {
            $lockedCart->update(['coupon_code' => null]);

            return $this->applyAutomaticGiftsLocked($lockedCart);
        });
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
            $gifts = array_merge(
                $gifts,
                array_map(
                    fn (array $gift): array => $gift + ['promotion_id' => $promotion->id],
                    $calculated['gift_products'],
                ),
            );

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
        return $this->mutations->run(
            $cart,
            fn (Cart $lockedCart): Cart => $this->applyAutomaticGiftsLocked($lockedCart),
        );
    }

    public function applyAutomaticGiftsLocked(Cart $cart): Cart
    {
        $cart = $cart->fresh(['items.product', 'bundleItems.bundle', 'user.loyaltyAccount']);
        $expected = $this->canonicalGifts($this->evaluate($cart)['gift_products']);
        $existing = $cart->items()
            ->gifts()
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('product_id');

        $staleGifts = $cart->items()->gifts();

        if ($expected->isNotEmpty()) {
            $staleGifts->whereNotIn('product_id', $expected->keys());
        }

        $staleGifts->delete();

        foreach ($expected as $productId => $gift) {
            $product = Product::query()
                ->published()
                ->where('active', true)
                ->find($productId);

            if (! $product) {
                $existing->get($productId)?->delete();

                continue;
            }

            $giftItem = $existing->get($productId);
            $values = [
                'quantity' => $gift['quantity'],
                'promotion_id' => $gift['promotion_id'],
                'unit_price' => 0,
                'total_price' => 0,
            ];

            if ($giftItem === null) {
                $cart->items()->create(['product_id' => $productId, 'is_gift' => true] + $values);

                DB::afterCommit(fn () => $this->events->log(
                    'gift_added',
                    'internal',
                    ['product_id' => $productId],
                    $cart->user,
                    $cart->session_id,
                ));

                continue;
            }

            if (
                (int) $giftItem->quantity !== $values['quantity']
                || (int) ($giftItem->promotion_id ?? 0) !== (int) ($values['promotion_id'] ?? 0)
                || (float) $giftItem->unit_price !== 0.0
                || (float) $giftItem->total_price !== 0.0
            ) {
                $giftItem->update($values);
            }
        }

        return $cart->fresh([
            'items.product.brand',
            'items.product.category',
            'items.product.images',
            'bundleItems.bundle',
            'user.loyaltyAccount',
        ]);
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

    /**
     * @param  array<int, array{product_id: int, quantity: int, promotion_id?: int|null}>  $gifts
     * @return Collection<int, array{product_id: int, quantity: int, promotion_id: int|null}>
     */
    private function canonicalGifts(array $gifts): Collection
    {
        return collect($gifts)
            ->filter(fn (array $gift): bool => (int) ($gift['product_id'] ?? 0) > 0)
            ->groupBy(fn (array $gift): int => (int) $gift['product_id'])
            ->map(fn (Collection $sources, int $productId): array => [
                'product_id' => $productId,
                'quantity' => $sources->sum(fn (array $gift): int => max(1, (int) ($gift['quantity'] ?? 1))),
                'promotion_id' => $sources->first()['promotion_id'] ?? null,
            ])
            ->sortKeys();
    }
}

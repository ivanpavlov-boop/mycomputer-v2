<?php

namespace App\Services\Promotions;

use App\Exceptions\CartPromotionChangedException;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Promotion;
use App\Models\PromotionRedemption;
use App\Models\User;
use App\Services\Marketing\MarketingEventService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PromotionRedemptionService
{
    private const TRANSACTION_ATTEMPTS = 3;

    public function __construct(
        private readonly PromotionEngineService $promotions,
        private readonly MarketingEventService $events,
    ) {}

    /**
     * @return Collection<int, PromotionRedemption>
     */
    public function consume(Cart $cart, Order $order, array $checkoutResult): Collection
    {
        if (DB::transactionLevel() > 0) {
            return $this->consumeLocked($cart, $order, $checkoutResult);
        }

        return DB::transaction(
            fn (): Collection => $this->consumeLocked($cart, $order, $checkoutResult),
            self::TRANSACTION_ATTEMPTS,
        );
    }

    /**
     * @return Collection<int, PromotionRedemption>
     */
    private function consumeLocked(Cart $cart, Order $order, array $checkoutResult): Collection
    {
        $expected = $this->canonicalAppliedPromotions($checkoutResult);
        $promotionIds = $expected->pluck('id')->all();

        if ($promotionIds === []) {
            return collect();
        }

        if (count($promotionIds) !== count(array_unique($promotionIds))) {
            throw new CartPromotionChangedException;
        }

        $promotionIds = collect($promotionIds)->sort()->values()->all();
        $lockedPromotions = Promotion::query()
            ->whereIn('id', $promotionIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($lockedPromotions->modelKeys() !== $promotionIds) {
            throw new CartPromotionChangedException;
        }

        $existing = PromotionRedemption::query()
            ->where('order_id', $order->getKey())
            ->orderBy('promotion_id')
            ->lockForUpdate()
            ->get();

        if ($existing->isNotEmpty()) {
            if ($existing->pluck('promotion_id')->map(fn ($id): int => (int) $id)->all() === $promotionIds) {
                return $existing;
            }

            throw new CartPromotionChangedException;
        }

        $cart = Cart::query()
            ->with(['items.product', 'bundleItems.bundle', 'user.loyaltyAccount'])
            ->findOrFail($cart->getKey());
        $shippingPrice = (float) data_get($checkoutResult, 'audit.shipping_price', 0);
        $freshResult = $this->promotions->evaluate($cart, $shippingPrice);

        if ($this->canonicalAppliedPromotions($freshResult)->all() !== $expected->all()) {
            throw new CartPromotionChangedException;
        }

        $redemptions = collect();

        foreach ($expected as $applied) {
            $redemptions->push(PromotionRedemption::query()->create([
                'promotion_id' => $applied['id'],
                'order_id' => $order->getKey(),
                'user_id' => $cart->user_id,
                'session_id' => $cart->session_id,
                'discount_amount' => $applied['discount_amount'],
            ]));
        }

        foreach ($lockedPromotions as $promotion) {
            $promotion->increment('usage_count');
        }

        $userId = $cart->user_id;
        $sessionId = $cart->session_id;
        $orderId = (int) $order->getKey();

        foreach ($expected as $applied) {
            DB::afterCommit(function () use ($applied, $orderId, $sessionId, $userId): void {
                $this->events->log(
                    'promotion_applied',
                    'internal',
                    [
                        'promotion_id' => $applied['id'],
                        'order_id' => $orderId,
                        'discount_amount' => $applied['discount_amount'],
                    ],
                    $userId ? User::query()->find($userId) : null,
                    $sessionId,
                );
            });
        }

        return $redemptions;
    }

    /**
     * @return Collection<int, array{id: int, discount_amount: float}>
     */
    private function canonicalAppliedPromotions(array $result): Collection
    {
        return collect($result['applied_promotions'] ?? [])
            ->map(fn (array $applied): array => [
                'id' => (int) ($applied['id'] ?? 0),
                'discount_amount' => round(
                    (float) ($applied['discount'] ?? 0) + (float) ($applied['shipping_discount'] ?? 0),
                    2,
                ),
            ])
            ->filter(fn (array $applied): bool => $applied['id'] > 0)
            ->sortBy('id')
            ->values();
    }
}

<?php

namespace App\Services\Cart;

use App\Enums\CartStatus;
use App\Exceptions\CartMergeConflictException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\User;
use App\Services\Promotions\PromotionEngineService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CartMergeService
{
    public function __construct(
        private readonly PromotionEngineService $promotions,
    ) {}

    /**
     * @param  Collection<int, Cart>  $sources
     */
    public function mergeInto(Cart $target, Collection $sources, User $user): Cart
    {
        $sources = $sources
            ->reject(fn (Cart $cart): bool => $cart->is($target))
            ->unique(fn (Cart $cart): int => (int) $cart->getKey())
            ->sortBy(fn (Cart $cart): int => (int) $cart->getKey())
            ->values();

        $cartIds = $sources->pluck('id')->prepend($target->getKey())->all();
        $paidItems = CartItem::query()
            ->whereIn('cart_id', $cartIds)
            ->where('is_gift', false)
            ->orderBy('cart_id')
            ->orderBy('id')
            ->get();

        $this->assertMergeableQuantities($paidItems);
        $couponCode = $this->mergedCouponCode($target, $sources);

        CartItem::query()
            ->whereIn('cart_id', $cartIds)
            ->where('is_gift', true)
            ->delete();

        $targetItems = $paidItems
            ->where('cart_id', $target->getKey())
            ->keyBy('product_id');

        foreach ($sources as $source) {
            foreach ($paidItems->where('cart_id', $source->getKey()) as $sourceItem) {
                /** @var CartItem|null $targetItem */
                $targetItem = $targetItems->get($sourceItem->product_id);

                if ($targetItem === null) {
                    DB::table('cart_items')
                        ->where('id', $sourceItem->getKey())
                        ->update(['cart_id' => $target->getKey()]);

                    $sourceItem->cart_id = $target->getKey();
                    $targetItems->put($sourceItem->product_id, $sourceItem);

                    continue;
                }

                $quantity = (int) $targetItem->quantity + (int) $sourceItem->quantity;
                $targetItem->update([
                    'quantity' => $quantity,
                    'total_price' => (float) $targetItem->unit_price * $quantity,
                ]);
                $sourceItem->delete();
            }

            DB::table('cart_bundle_items')
                ->where('cart_id', $source->getKey())
                ->update(['cart_id' => $target->getKey()]);
        }

        $target->update([
            'user_id' => $user->getKey(),
            'customer_email' => filled($target->customer_email)
                ? $target->customer_email
                : $user->email,
            'coupon_code' => $couponCode,
        ]);

        foreach ($sources as $source) {
            $source->update([
                'status' => CartStatus::Merged->value,
                'expires_at' => now(),
            ]);
        }

        $target->unsetRelation('user');

        return $this->promotions->applyAutomaticGifts(
            $target->fresh(['items.product', 'bundleItems.bundle', 'user.loyaltyAccount']),
        );
    }

    /**
     * @param  Collection<int, CartItem>  $paidItems
     */
    private function assertMergeableQuantities(Collection $paidItems): void
    {
        $hasConflict = $paidItems
            ->groupBy('product_id')
            ->contains(
                fn (Collection $items): bool => $items->sum('quantity') > CartService::MAX_QUANTITY,
            );

        if ($hasConflict) {
            throw new CartMergeConflictException;
        }
    }

    /**
     * @param  Collection<int, Cart>  $sources
     */
    private function mergedCouponCode(Cart $target, Collection $sources): ?string
    {
        $codes = $sources
            ->pluck('coupon_code')
            ->prepend($target->coupon_code)
            ->filter(fn (mixed $code): bool => is_string($code) && trim($code) !== '')
            ->map(fn (string $code): string => trim($code))
            ->unique()
            ->values();

        if ($codes->count() > 1) {
            throw new CartMergeConflictException;
        }

        return $codes->first();
    }
}

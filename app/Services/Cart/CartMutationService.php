<?php

namespace App\Services\Cart;

use App\Enums\CartStatus;
use App\Exceptions\CartMutationConflictException;
use App\Models\Cart;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class CartMutationService
{
    private const TRANSACTION_ATTEMPTS = 3;

    /**
     * @template TResult
     *
     * @param  Closure(Cart): TResult  $callback
     * @return TResult
     */
    public function run(Cart $cart, Closure $callback, bool $requireActive = true): mixed
    {
        try {
            return DB::transaction(function () use ($cart, $callback, $requireActive): mixed {
                $lockedCart = Cart::query()
                    ->whereKey($cart->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                abort_unless(
                    $lockedCart->session_id === $cart->session_id
                        && (int) ($lockedCart->user_id ?? 0) === (int) ($cart->user_id ?? 0),
                    403,
                    'Cart access is not allowed.',
                );
                if ($requireActive) {
                    abort_unless(
                        $lockedCart->status === CartStatus::Active->value
                            && ($lockedCart->expires_at === null || $lockedCart->expires_at->isFuture()),
                        409,
                        'Cart session is not available.',
                    );
                }

                $lockedCart->items()->orderBy('id')->lockForUpdate()->get();
                $lockedCart->bundleItems()->orderBy('id')->lockForUpdate()->get();

                return $callback($lockedCart);
            }, self::TRANSACTION_ATTEMPTS);
        } catch (QueryException $exception) {
            if ($this->isMutationConflict($exception)) {
                throw new CartMutationConflictException;
            }

            throw $exception;
        }
    }

    private function isMutationConflict(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        if (in_array($sqlState, ['40001', '40P01'], true) || in_array($driverCode, [1205, 1213], true)) {
            return true;
        }

        return $sqlState === '23000'
            && $driverCode === 1062
            && str_contains($exception->getMessage(), 'cart_items_cart_product_gift_unique');
    }
}

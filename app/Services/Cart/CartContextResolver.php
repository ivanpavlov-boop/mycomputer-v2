<?php

namespace App\Services\Cart;

use App\Models\Cart;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CartContextResolver
{
    private const RELATIONS = [
        'items.product.brand',
        'items.product.category',
        'items.product.images',
        'items.product.availabilityStatus',
        'bundleItems.bundle.items.product',
        'bundleItems.bundle.options.product',
    ];

    public function resolve(Request $request): Cart
    {
        $sessionId = $this->sessionId($request);
        $user = Auth::guard('sanctum')->user();

        $cart = Cart::query()->firstOrCreate(
            ['session_id' => $sessionId],
            ['status' => 'active', 'expires_at' => now()->addDays(14)],
        );

        $cart = DB::transaction(function () use ($cart, $user): Cart {
            $lockedCart = Cart::query()
                ->whereKey($cart->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($user === null) {
                abort_if($lockedCart->user_id !== null, 403, 'Cart access is not allowed.');

                return $lockedCart;
            }

            abort_if(
                $lockedCart->user_id !== null && (int) $lockedCart->user_id !== (int) $user->id,
                403,
                'Cart access is not allowed.',
            );

            if ($lockedCart->user_id === null) {
                $lockedCart->update([
                    'user_id' => $user->id,
                    'customer_email' => $lockedCart->customer_email ?: $user->email,
                ]);
            } elseif ($lockedCart->customer_email === null) {
                $lockedCart->update(['customer_email' => $user->email]);
            }

            return $lockedCart;
        });

        return $cart->load(self::RELATIONS);
    }

    private function sessionId(Request $request): string
    {
        $sessionId = $request->header('X-Cart-Session');

        if ($sessionId === null || (is_string($sessionId) && trim($sessionId) === '')) {
            return (string) Str::uuid();
        }

        if (
            ! is_string($sessionId)
            || strlen($sessionId) !== 36
            || trim($sessionId) !== $sessionId
            || strtolower($sessionId) !== $sessionId
            || ! Str::isUuid($sessionId)
        ) {
            abort(422, 'Invalid cart session.');
        }

        return $sessionId;
    }
}

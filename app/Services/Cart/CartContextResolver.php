<?php

namespace App\Services\Cart;

use App\Models\Cart;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

    public function __construct(
        private readonly CartLifecycleService $lifecycle,
    ) {}

    public function resolve(Request $request): Cart
    {
        $sessionId = $this->sessionId($request);
        $user = Auth::guard('sanctum')->user();

        $cart = $user === null
            ? $this->lifecycle->resolveGuest($sessionId)
            : $this->lifecycle->resolveAuthenticated($user, $sessionId);

        return $cart->load(self::RELATIONS);
    }

    private function sessionId(Request $request): ?string
    {
        $sessionId = $request->header('X-Cart-Session');

        if ($sessionId === null || (is_string($sessionId) && trim($sessionId) === '')) {
            return null;
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

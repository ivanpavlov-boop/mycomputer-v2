<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CheckoutRequest;
use App\Http\Resources\OrderResource;
use App\Services\Cart\CartService;
use App\Services\Orders\CheckoutService;
use Illuminate\Support\Facades\Auth;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly CheckoutService $checkoutService,
    ) {}

    public function __invoke(CheckoutRequest $request): OrderResource
    {
        $cart = $this->cartService->resolve($request->header('X-Cart-Session'));
        $user = Auth::guard('sanctum')->user();

        if ($user) {
            $cart->update([
                'user_id' => $user->id,
                'customer_email' => $user->email,
            ]);
        }

        $order = $this->checkoutService->checkout($cart, $request->validated());

        return OrderResource::make($order);
    }
}

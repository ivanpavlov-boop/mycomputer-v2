<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CheckoutRequest;
use App\Http\Resources\OrderResource;
use App\Services\Cart\CartContextResolver;
use App\Services\Orders\CheckoutService;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly CartContextResolver $cartContext,
        private readonly CheckoutService $checkoutService,
    ) {}

    public function __invoke(CheckoutRequest $request): OrderResource
    {
        $cart = $this->cartContext->resolve($request);
        $order = $this->checkoutService->checkout($cart, $request->validated());

        return OrderResource::make($order);
    }
}

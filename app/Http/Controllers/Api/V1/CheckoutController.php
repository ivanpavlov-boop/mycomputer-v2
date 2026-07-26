<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CheckoutRequest;
use App\Http\Resources\CheckoutResponseResource;
use App\Services\Cart\CartContextResolver;
use App\Services\Orders\CheckoutConfirmationService;
use App\Services\Orders\CheckoutService;
use Illuminate\Http\JsonResponse;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly CartContextResolver $cartContext,
        private readonly CheckoutService $checkoutService,
        private readonly CheckoutConfirmationService $checkoutConfirmations,
    ) {}

    public function __invoke(CheckoutRequest $request): JsonResponse
    {
        $cart = $this->cartContext->resolve($request);
        $result = $this->checkoutService->checkout($cart, $request->validated());

        return CheckoutResponseResource::make($result->order())
            ->response()
            ->withCookie(
                $this->checkoutConfirmations->cookie(
                    $result->confirmationCapability(),
                    $request,
                ),
            );
    }
}

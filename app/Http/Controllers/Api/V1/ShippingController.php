<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\CartNotReadyException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ShippingCalculateRequest;
use App\Http\Requests\Api\V1\ShippingOfficeIndexRequest;
use App\Http\Resources\ShippingMethodResource;
use App\Http\Resources\ShippingOfficeResource;
use App\Http\Resources\ShippingProviderResource;
use App\Models\ShippingMethod;
use App\Models\ShippingProvider;
use App\Services\Cart\CartContextResolver;
use App\Services\Cart\CartPricingRefreshService;
use App\Services\Cart\CartReadinessService;
use App\Services\Cart\CartService;
use App\Services\Shipping\ShippingOfficeService;
use App\Services\Shipping\ShippingPriceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ShippingController extends Controller
{
    public function providers(): AnonymousResourceCollection
    {
        return ShippingProviderResource::collection(
            ShippingProvider::query()->where('status', 'active')->orderBy('name')->get(),
        );
    }

    public function methods(): AnonymousResourceCollection
    {
        return ShippingMethodResource::collection(
            ShippingMethod::query()->with('provider')->where('status', 'active')->whereHas('provider', fn ($query) => $query->where('status', 'active'))->orderBy('sort_order')->get(),
        );
    }

    public function offices(ShippingOfficeIndexRequest $request, ShippingOfficeService $officeService): AnonymousResourceCollection
    {
        return ShippingOfficeResource::collection($officeService->search($request->validated()));
    }

    public function calculate(
        ShippingCalculateRequest $request,
        ShippingPriceService $priceService,
        CartService $cartService,
        CartContextResolver $cartContext,
        CartPricingRefreshService $pricing,
        CartReadinessService $readiness,
    ): JsonResponse {
        $data = $request->validated();
        $subtotal = 0.0;
        $hasSession = filled($request->header('X-Cart-Session'));

        if ($hasSession) {
            $cart = $cartContext->resolve($request);
            abort_if(
                filled($data['cart_id'] ?? null) && (int) $data['cart_id'] !== (int) $cart->id,
                422,
                'Cart session does not match the requested cart.',
            );
            $cart = $pricing->refresh($cart)->cart;
            $cartReadiness = $readiness->assess($cart);

            if (! $cartReadiness->canCheckout) {
                throw new CartNotReadyException($cartReadiness);
            }

            $subtotal = $cartService->subtotal($cart);
        } elseif (filled($data['cart_id'] ?? null)) {
            abort(422, 'Cart session is required for cart-based shipping calculation.');
        }

        $shipping = $priceService->calculate(array_merge($data, [
            'shipping_method' => $data['shipping_method'] ?? $data['delivery_type'],
        ]), $subtotal);

        return response()->json(['data' => [
            'shipping_price' => number_format((float) $shipping['price'], 2, '.', ''),
            'estimated_delivery' => $shipping['estimated_delivery'],
            'provider' => $shipping['provider'],
            'method' => $shipping['method'],
        ]]);
    }
}

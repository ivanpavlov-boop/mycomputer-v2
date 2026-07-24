<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AddCartItemRequest;
use App\Http\Requests\Api\V1\ApplyCouponRequest;
use App\Http\Requests\Api\V1\CartEmailRequest;
use App\Http\Requests\Api\V1\UpdateCartItemRequest;
use App\Http\Resources\CartResource;
use App\Jobs\AnalyticsEventJob;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Services\Cart\CartContextResolver;
use App\Services\Cart\CartPricingRefreshService;
use App\Services\Cart\CartReadinessService;
use App\Services\Cart\CartService;
use App\Services\Email\EmailMarketingService;
use App\Services\Promotions\PromotionEngineService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CartController extends Controller
{
    public function __construct(
        private readonly CartContextResolver $cartContext,
        private readonly CartService $cartService,
        private readonly CartPricingRefreshService $pricing,
        private readonly CartReadinessService $readiness,
        private readonly EmailMarketingService $emailMarketing,
        private readonly PromotionEngineService $promotions,
    ) {}

    public function show(Request $request): CartResource
    {
        return CartResource::make($this->ready($this->cartContext->resolve($request)));
    }

    public function store(AddCartItemRequest $request): CartResource
    {
        $cart = $this->cartContext->resolve($request);
        $product = Product::query()->withTrashed()->findOrFail($request->integer('product_id'));
        $quantity = $request->integer('quantity');
        $cart = $this->cartService->add($cart, $product, $quantity);

        AnalyticsEventJob::dispatch('add_to_cart', 'internal', [
            'product_id' => $product->id,
            'sku' => $product->sku,
            'quantity' => $quantity,
            'value' => $this->cartService->price($product) * $quantity,
        ], Auth::guard('sanctum')->id(), $request->header('X-Marketing-Session'));

        return CartResource::make($this->ready($cart));
    }

    public function update(UpdateCartItemRequest $request, CartItem $item): CartResource
    {
        $cart = $this->cartContext->resolve($request);

        $cart = $this->cartService->update($cart, $item, $request->integer('quantity'));

        return CartResource::make($this->ready($cart));
    }

    public function destroy(Request $request, CartItem $item): CartResource
    {
        $cart = $this->cartContext->resolve($request);

        $cart = $this->cartService->remove($cart, $item);

        return CartResource::make($this->ready($cart));
    }

    public function clear(Request $request): CartResource
    {
        $cart = $this->cartContext->resolve($request);

        return CartResource::make($this->ready($this->cartService->clear($cart)));
    }

    public function applyCoupon(ApplyCouponRequest $request): CartResource
    {
        $cart = $this->pricing->refresh($this->cartContext->resolve($request))->cart;
        $cart = $this->promotions->applyCoupon($cart, $request->validated('code'));

        return CartResource::make($this->ready($cart));
    }

    public function removeCoupon(Request $request): CartResource
    {
        $cart = $this->pricing->refresh($this->cartContext->resolve($request))->cart;
        $cart = $this->promotions->removeCoupon($cart);

        return CartResource::make($this->ready($cart));
    }

    public function email(CartEmailRequest $request): CartResource
    {
        $cart = $this->emailMarketing->attachEmailToCart(
            $this->cartContext->resolve($request),
            $request->validated('email'),
        );

        return CartResource::make($this->ready($cart));
    }

    public function recover(Request $request, string $token): CartResource
    {
        $cart = $this->emailMarketing->restoreCartFromToken($token, $this->sessionId($request));

        return CartResource::make($this->ready($cart));
    }

    private function sessionId(Request $request): ?string
    {
        return $request->header('X-Cart-Session');
    }

    private function ready(Cart $cart): Cart
    {
        $cart = $this->pricing->refresh($cart)->cart;

        return $this->readiness->assess($cart)->cart;
    }
}

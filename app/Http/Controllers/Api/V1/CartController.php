<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AddCartItemRequest;
use App\Http\Requests\Api\V1\ApplyCouponRequest;
use App\Http\Requests\Api\V1\CartEmailRequest;
use App\Http\Requests\Api\V1\UpdateCartItemRequest;
use App\Http\Resources\CartResource;
use App\Jobs\AnalyticsEventJob;
use App\Models\CartItem;
use App\Models\Product;
use App\Services\Cart\CartContextResolver;
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
        private readonly EmailMarketingService $emailMarketing,
        private readonly PromotionEngineService $promotions,
    ) {}

    public function show(Request $request): CartResource
    {
        return CartResource::make($this->cartContext->resolve($request));
    }

    public function store(AddCartItemRequest $request): CartResource
    {
        $cart = $this->cartContext->resolve($request);
        $product = Product::query()->findOrFail($request->integer('product_id'));
        $quantity = $request->integer('quantity');
        $cart = $this->cartService->add($cart, $product, $quantity);
        $cart = $this->promotions->applyAutomaticGifts($cart);

        AnalyticsEventJob::dispatch('add_to_cart', 'internal', [
            'product_id' => $product->id,
            'sku' => $product->sku,
            'quantity' => $quantity,
            'value' => $this->cartService->price($product) * $quantity,
        ], Auth::guard('sanctum')->id(), $request->header('X-Marketing-Session'));

        return CartResource::make($cart);
    }

    public function update(UpdateCartItemRequest $request, CartItem $item): CartResource
    {
        $cart = $this->cartContext->resolve($request);

        return CartResource::make($this->promotions->applyAutomaticGifts($this->cartService->update($cart, $item, $request->integer('quantity'))));
    }

    public function destroy(Request $request, CartItem $item): CartResource
    {
        $cart = $this->cartContext->resolve($request);

        return CartResource::make($this->promotions->applyAutomaticGifts($this->cartService->remove($cart, $item)));
    }

    public function clear(Request $request): CartResource
    {
        $cart = $this->cartContext->resolve($request);

        return CartResource::make($this->cartService->clear($cart));
    }

    public function applyCoupon(ApplyCouponRequest $request): CartResource
    {
        return CartResource::make($this->promotions->applyCoupon($this->cartContext->resolve($request), $request->validated('code')));
    }

    public function removeCoupon(Request $request): CartResource
    {
        return CartResource::make($this->promotions->removeCoupon($this->cartContext->resolve($request)));
    }

    public function email(CartEmailRequest $request): CartResource
    {
        return CartResource::make(
            $this->emailMarketing->attachEmailToCart($this->cartContext->resolve($request), $request->validated('email')),
        );
    }

    public function recover(Request $request, string $token): CartResource
    {
        return CartResource::make(
            $this->emailMarketing->restoreCartFromToken($token, $this->sessionId($request)),
        );
    }

    private function sessionId(Request $request): ?string
    {
        return $request->header('X-Cart-Session');
    }
}

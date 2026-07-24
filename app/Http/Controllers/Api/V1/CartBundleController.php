<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreCartBundleRequest;
use App\Http\Requests\Api\V1\UpdateCartBundleRequest;
use App\Http\Resources\CartResource;
use App\Models\Cart;
use App\Models\CartBundleItem;
use App\Models\ProductBundle;
use App\Services\Bundles\BundleCartService;
use App\Services\Cart\CartContextResolver;
use App\Services\Cart\CartPricingRefreshService;
use App\Services\Promotions\PromotionEngineService;
use Illuminate\Http\Request;

class CartBundleController extends Controller
{
    public function __construct(
        private readonly CartContextResolver $carts,
        private readonly BundleCartService $bundles,
        private readonly CartPricingRefreshService $pricing,
        private readonly PromotionEngineService $promotions,
    ) {}

    public function store(StoreCartBundleRequest $request): CartResource
    {
        $cart = $this->carts->resolve($request);
        $bundle = ProductBundle::query()->with(['items.product', 'options.product'])->findOrFail($request->integer('bundle_id'));
        $this->bundles->add($cart, $bundle, $request->validated('selected_items') ?? [], $request->integer('quantity'));

        return CartResource::make($this->refreshed($cart));
    }

    public function update(UpdateCartBundleRequest $request, CartBundleItem $bundle): CartResource
    {
        $cart = $this->carts->resolve($request);
        abort_unless($bundle->cart_id === $cart->id, 404);
        $this->bundles->update($bundle, $request->validated('selected_items') ?? [], $request->integer('quantity'));

        return CartResource::make($this->refreshed($cart));
    }

    public function destroy(Request $request, CartBundleItem $bundle): CartResource
    {
        $cart = $this->carts->resolve($request);
        abort_unless($bundle->cart_id === $cart->id, 404);
        $this->bundles->remove($bundle);

        return CartResource::make($this->refreshed($cart));
    }

    private function refreshed(Cart $cart): Cart
    {
        $cart = $this->pricing->refresh($cart, refreshAutomaticGifts: false)->cart;
        $cart = $this->promotions->applyAutomaticGifts($cart);

        return $this->pricing->refresh($cart, refreshAutomaticGifts: false)->cart;
    }
}

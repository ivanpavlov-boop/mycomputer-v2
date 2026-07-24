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
use App\Services\Cart\CartMutationService;
use App\Services\Cart\CartPricingRefreshService;
use App\Services\Cart\CartReadinessService;
use App\Services\Promotions\PromotionEngineService;
use Illuminate\Http\Request;

class CartBundleController extends Controller
{
    public function __construct(
        private readonly CartContextResolver $carts,
        private readonly BundleCartService $bundles,
        private readonly CartPricingRefreshService $pricing,
        private readonly CartReadinessService $readiness,
        private readonly PromotionEngineService $promotions,
        private readonly CartMutationService $mutations,
    ) {}

    public function store(StoreCartBundleRequest $request): CartResource
    {
        $cart = $this->carts->resolve($request);
        $bundle = ProductBundle::query()->with(['items.product', 'options.product'])->findOrFail($request->integer('bundle_id'));
        $cart = $this->mutations->run($cart, function (Cart $lockedCart) use ($bundle, $request): Cart {
            $this->bundles->add(
                $lockedCart,
                $bundle,
                $request->validated('selected_items') ?? [],
                $request->integer('quantity'),
            );

            return $this->refreshedLocked($lockedCart);
        });

        return CartResource::make($this->readiness->assess($cart)->cart);
    }

    public function update(UpdateCartBundleRequest $request, CartBundleItem $bundle): CartResource
    {
        $cart = $this->carts->resolve($request);
        $cart = $this->mutations->run($cart, function (Cart $lockedCart) use ($bundle, $request): Cart {
            $lockedBundle = $lockedCart->bundleItems()
                ->whereKey($bundle->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->bundles->update(
                $lockedBundle,
                $request->validated('selected_items') ?? [],
                $request->integer('quantity'),
            );

            return $this->refreshedLocked($lockedCart);
        });

        return CartResource::make($this->readiness->assess($cart)->cart);
    }

    public function destroy(Request $request, CartBundleItem $bundle): CartResource
    {
        $cart = $this->carts->resolve($request);
        $cart = $this->mutations->run($cart, function (Cart $lockedCart) use ($bundle): Cart {
            $lockedBundle = $lockedCart->bundleItems()
                ->whereKey($bundle->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->bundles->remove($lockedBundle);

            return $this->refreshedLocked($lockedCart);
        });

        return CartResource::make($this->readiness->assess($cart)->cart);
    }

    private function refreshedLocked(Cart $cart): Cart
    {
        $cart = $this->pricing->refreshLocked($cart, refreshAutomaticGifts: false)->cart;
        $cart = $this->promotions->applyAutomaticGiftsLocked($cart);

        return $this->pricing->refreshLocked($cart, refreshAutomaticGifts: false)->cart;
    }
}
